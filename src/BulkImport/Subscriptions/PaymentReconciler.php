<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\Services\Logger;

/**
 * Phase 2 reconciliation engine (D-LOCKBOX-4, supersedes the Slice 5
 * PaymentMatcher). There is ONE CSV: each Phase 1 record carries its own
 * bank amount (the CSV order_total) and its own order_id on the staging row,
 * so Phase 2 reconciles a record against the order Phase 1 created for it.
 * No Bar ID lookup, no amount disambiguation, no client seam for order
 * resolution (mechanism, AD1-clean).
 *
 * Per-record flow:
 *   1. expected = the order's total (what the membership plugin calculated);
 *      payment = the CSV amount (what the bank charged).
 *   2. |payment - expected| within 0.01 -> process (On Hold -> Processing,
 *      internal cheque note, activate every subscription on the order, fire
 *      wicket_import_create_renewal_membership).
 *   3. payment < expected (shortfall): the order is NOT processed; the row
 *      lands needs_review with the discrepancy recorded. A client may process
 *      any shortfall instead via wicket_import_phase2_shortfall_policy
 *      (hold | process, default hold).
 *   4. payment > expected (surplus): the order processes and the surplus is
 *      recorded. wicket_import_phase2_surplus_policy (record | hold, default
 *      record) lets a client hold surplus rows instead.
 *   5. Any mismatch past tolerance also stamps _wicket_payment_discrepancy
 *      order meta for order-level audit.
 */
final class PaymentReconciler
{
    /**
     * Equality tolerance in currency units, compared in integer cents (same
     * rule as the WWID-2318 amount gate and ProductResolver's divergence
     * check).
     */
    private const TOLERANCE = 0.01;

    /**
     * Order meta key for the audit record of a mismatch past tolerance.
     */
    public const DISCREPANCY_META = '_wicket_payment_discrepancy';

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Reconcile one record against its own On Hold order.
     *
     * @param array<string,mixed> $row Decoded raw_data of the Phase 1 staging row.
     */
    public function reconcile(object $order, array $row): ReconcileResult
    {
        $expected = (float) $order->get_total();
        $payment = (float) ($row['order_total'] ?? 0);
        $deltaCents = (int) round($payment * 100) - (int) round($expected * 100);
        $toleranceCents = (int) round(self::TOLERANCE * 100);

        if (abs($deltaCents) <= $toleranceCents) {
            $subscriptionIds = $this->processOrder($order, $row);

            return ReconcileResult::processed(
                $payment,
                $expected,
                0.0,
                $subscriptionIds,
                sprintf('Payment received – Cheque #%s', (string) ($row['check_id'] ?? ''))
            );
        }

        $discrepancy = $deltaCents / 100.0;
        $this->stampDiscrepancy($order, $expected, $payment, $discrepancy);

        if ($deltaCents < 0) {
            // Shortfall: fail closed by default (a $40 cheque must not
            // activate a $350 membership; WWID-2318 rationale).
            $policy = (string) apply_filters('wicket_import_phase2_shortfall_policy', 'hold');
            if ($policy === 'process') {
                $subscriptionIds = $this->processOrder($order, $row);

                return ReconcileResult::processed(
                    $payment,
                    $expected,
                    $discrepancy,
                    $subscriptionIds,
                    sprintf(
                        'Shortfall processed per site policy: order total is %.2F but the payment amount was %.2F (short %.2F).',
                        $expected,
                        $payment,
                        abs($discrepancy)
                    )
                );
            }

            return ReconcileResult::heldForReview(
                $payment,
                $expected,
                $discrepancy,
                sprintf(
                    'Shortfall: order total is %.2F but the payment amount was %.2F (short %.2F); order held for review.',
                    $expected,
                    $payment,
                    abs($discrepancy)
                )
            );
        }

        // Surplus: process by default, record the leftover. A client may hold
        // surplus rows instead via wicket_import_phase2_surplus_policy.
        $policy = (string) apply_filters('wicket_import_phase2_surplus_policy', 'record');
        if ($policy === 'hold') {
            return ReconcileResult::heldForReview(
                $payment,
                $expected,
                $discrepancy,
                sprintf(
                    'Surplus held per site policy: order total is %.2F but the payment amount was %.2F (over %.2F).',
                    $expected,
                    $payment,
                    $discrepancy
                )
            );
        }

        $subscriptionIds = $this->processOrder($order, $row);

        return ReconcileResult::processed(
            $payment,
            $expected,
            $discrepancy,
            $subscriptionIds,
            sprintf(
                'Payment received – Cheque #%s (surplus %.2F recorded for the user).',
                (string) ($row['check_id'] ?? ''),
                $discrepancy
            )
        );
    }

    /**
     * Move the order On Hold -> Processing and activate everything attached
     * to it. Customer emails are OFF by default (WWID-2318, 2026-08-27): a
     * bulk run must not email every matched member a "Processing order"
     * notice. A client opts in with
     * add_filter('wicket_import_send_customer_emails', '__return_true').
     * The suppression is scoped try/finally so a throw mid-transition cannot
     * leave it attached for the rest of the request.
     *
     * @return list<int> Activated subscription IDs.
     */
    private function processOrder(object $order, array $row): array
    {
        $sendEmails = (bool) apply_filters('wicket_import_send_customer_emails', false);
        if (!$sendEmails) {
            \add_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'suppressCustomerProcessingEmail']);
        }
        try {
            // add_order_note inside update_status is an internal (customer-not-
            // notified) note per spec Story 10.
            $order->update_status('processing', sprintf('Payment received – Cheque #%s', (string) ($row['check_id'] ?? '')));
            $order->save();
        } finally {
            if (!$sendEmails) {
                \remove_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'suppressCustomerProcessingEmail']);
            }
        }

        // Activate every subscription attached to the order (membership +
        // section, when present). WCS's wcs_get_subscriptions(['order_id'=>..])
        // bails under HPOS on its classic-mode guard, so query the
        // shop_subscription posts by post_parent directly; both storage
        // backends store subs as posts.
        $subscriptionIds = [];
        if (post_type_exists('shop_subscription')) {
            global $wpdb;
            $subIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_parent = %d",
                    (int) $order->get_id()
                )
            );
            foreach ((array) $subIds as $sid) {
                $sub = function_exists('wcs_get_subscription') ? wcs_get_subscription((int) $sid) : null;
                if ($sub !== null && method_exists($sub, 'set_status') && method_exists($sub, 'save')) {
                    $sub->set_status('active');
                    $sub->save();
                    $subscriptionIds[] = (int) $sub->get_id();
                }
            }
        }

        // The renewal-membership creation belongs to the memberships plugin's
        // domain (it hooks woocommerce_order_status_processing off the
        // _membership_post_id_renew line meta). The importer owns the trigger
        // point; clients without the memberships plugin may answer the action.
        // Per D-LOCKBOX-3.
        do_action('wicket_import_create_renewal_membership', $order, $row);

        $this->logger?->info('Payment reconciled; order processed.', [
            'order_id'         => (int) $order->get_id(),
            'subscription_ids' => $subscriptionIds,
            'check_id'         => (string) ($row['check_id'] ?? ''),
        ]);

        return $subscriptionIds;
    }

    /**
     * Audit record on the order for any mismatch past tolerance (shortfall or
     * surplus), so order-level reporting and the reconciler's staging columns
     * never disagree.
     */
    private function stampDiscrepancy(object $order, float $expected, float $payment, float $discrepancy): void
    {
        if (!method_exists($order, 'update_meta_data') || !method_exists($order, 'save')) {
            return;
        }

        $order->update_meta_data(self::DISCREPANCY_META, (string) wp_json_encode([
            'expected'    => $expected,
            'payment'     => $payment,
            'discrepancy' => $discrepancy,
        ]));
        $order->save();
    }

    /**
     * Force-disable the WC customer "Processing order" email while a matched
     * payment transitions an order. Callback for the
     * woocommerce_email_enabled_customer_processing_order filter.
     */
    public static function suppressCustomerProcessingEmail(): bool
    {
        return false;
    }
}
