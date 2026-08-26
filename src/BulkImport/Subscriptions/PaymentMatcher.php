<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\BulkImport\Database\PaymentStagingTable;
use WicketImporter\Services\Logger;

/**
 * Phase 2 match engine (Slice 5, Stories 9-11 + Story 10 downstream).
 *
 * Per-row flow:
 *   1. Decode the CSV row.
 *   2. Resolve On Hold orders for the member via the wicket_import_phase2_resolve_orders
 *      client seam (OBA answers from Bar ID -> user -> user's On Hold orders).
 *   3. If 0 orders -> failed (Story 11: "No On Hold order found for this Bar ID").
 *   4. If multiple -> match by csv order total; if still ambiguous -> needs_review
 *      ("Multiple On Hold orders found - manual resolution required").
 *   5. On a unique match: transition On Hold -> Processing, add the internal
 *      note, activate EVERY subscription attached to the order (membership +
 *      section, when present), fire the wicket_import_create_renewal_membership
 *      action so the OBA child theme (or the memberships plugin) can create the
 *      renewed wicket_membership post via its own domain path. Per-row failures
 *      are isolated; the chunk continues.
 */
final class PaymentMatcher
{
    /**
     * Why the last resolveMatch() call returned null (per-row reason for the
     * error log / review table). Null while a match is live or was found.
     */
    public ?string $lastFailure = null;
    /**
     * On a unique match, transition the order + activate subscriptions + fire
     * the renewal-membership action. Returns ['order_id', 'subscription_ids'].
     *
     * @param array<string,mixed> $row Payment row from PaymentStagingTable (raw_data JSON-decoded externally).
     *
     * @return array{order_id:int, subscription_ids: list<int>}
     */
    public function applyMatch(object $order, array $row, ?Logger $logger = null): array
    {
        $orderId = (int) $order->get_id();
        $matched = [];

        // Customer emails are OFF by default during import matches (WWID-2318
        // decision 2026-08-27): a bulk lockbox run would email every matched
        // member a "Processing order" notice that means nothing to them. A
        // client opts in from its child theme with
        // add_filter('wicket_import_send_customer_emails', '__return_true').
        // Scoped with try/finally so a throw mid-transition cannot leave the
        // suppression attached for the rest of the request.
        $sendEmails = (bool) apply_filters('wicket_import_send_customer_emails', false);
        if (!$sendEmails) {
            \add_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'suppressCustomerProcessingEmail']);
        }
        try {
            // On Hold -> Processing + internal note. add_order_note('', false) is
            // an internal/customer-not-notified note per spec Story 10.
            $order->update_status('processing', sprintf('Payment received - Cheque #%s', (string) ($row['check_id'] ?? '')));
            $order->save();
        } finally {
            if (!$sendEmails) {
                \remove_filter('woocommerce_email_enabled_customer_processing_order', [self::class, 'suppressCustomerProcessingEmail']);
            }
        }

        // Activate every subscription attached to the order (membership +
        // section, when present). WCS exposes a getSubscriptions helper that
        // takes 'order_id', but under HPOS it bails on its classic-mode guard
        // (it expects the order to live in the shop_order CPT). Query the
        // shop_subscription CPT by post_parent directly so the activation
        // path works under both storage backends.
        $subscriptionIds = [];
        // HPOS-safe subscription lookup: under the custom orders table
        // (WCS still stores subs as shop_subscription CPT posts) the order_id
        // param on wcs_get_subscriptions bails on its classic-mode guard.
        // Query post_parent directly so the activation path works under both
        // storage backends; wcs_get_subscription hydrates the sub object.
        if (post_type_exists('shop_subscription')) {
            global $wpdb;
            $subIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_parent = %d",
                    $orderId
                )
            );
            foreach ((array) $subIds as $sid) {
                $sub = function_exists('wcs_get_subscription') ? wcs_get_subscription((int) $sid) : null;
                if ($sub !== null && method_exists($sub, 'set_status') && method_exists($sub, 'save')) {
                    $sub->set_status('active');
                    $sub->save();
                    $subscriptionIds[] = (int) $sub->get_id();
                } else {
                }
            }
        }

        // The renewal-membership creation belongs to the memberships plugin's
        // domain (a wicket_membership post). Importer owns the mechanism (the
        // trigger point); OBA / memberships plugin owns the policy (how to
        // create the next-year membership). Per D-LOCKBOX-3.
        do_action('wicket_import_create_renewal_membership', $order, $row);

        $matched['order_id'] = $orderId;
        $matched['subscription_ids'] = $subscriptionIds;

        $logger?->info('Payment matched; order processed.', [
            'order_id' => $orderId,
            'subscription_ids' => $subscriptionIds,
            'check_id' => $row['check_id'] ?? '',
        ]);

        return $matched;
    }

    /**
     * Resolve the On Hold orders that match a payment row.
     *
     * Two-stage per spec Story 9: the client seam returns the candidate
     * order IDs for the Bar ID (typically filtered to the same Phase 1 batch
     * via _batch_id so a user's multiple historical On Hold orders don't all
     * match); the engine then disambiguates by csv order_total when more
     * than one returns.
     *
     * @param array<string,mixed> $row Decoded CSV row.
     * @param string             $batchId The Phase 1 batch_id whose orders are eligible
     *                                    for this Phase 2 run (empty when not applicable).
     *
     * @return object|null The unique matched WC_Order, or null when none / ambiguous.
     */
    /**
     * Force-disable the WC customer "Processing order" email while a matched
     * payment transitions an order. Callback for the
     * woocommerce_email_enabled_customer_processing_order filter.
     */
    public static function suppressCustomerProcessingEmail(): bool
    {
        return false;
    }

    public function resolveMatch(array $row, string $batchId = '', ?Logger $logger = null): ?object
    {
        $this->lastFailure = null;
        $barId = trim((string) ($row['bar_id'] ?? ''));
        $csvTotal = (float) ($row['order_total'] ?? 0);
        if ($barId === '' || $csvTotal <= 0) {
            $this->lastFailure = 'Invalid payment row: a Bar ID and a positive payment total are required.';

            return null;
        }

        /** @var list<int> $orderIds */
        $orderIds = array_values(array_map('intval', (array) apply_filters(
            'wicket_import_phase2_resolve_orders',
            [],
            $barId,
            $csvTotal,
            $batchId
        )));
        if ($orderIds === []) {
            $this->lastFailure = self::reasonFor($row, 0, false);

            return null;
        }

        $orders = [];
        foreach ($orderIds as $oid) {
            $o = function_exists('wc_get_order') ? wc_get_order($oid) : false;
            if ($o !== false && method_exists($o, 'get_status') && (string) $o->get_status() === 'on-hold') {
                $orders[] = $o;
            }
        }
        if ($orders === []) {
            $this->lastFailure = self::reasonFor($row, 0, false);

            return null;
        }

        /*
         * Single candidate: the acceptance match is "On Hold order found for
         * the Bar ID AND the payment amount equals the order total" (WWID-2318).
         * Returning the only candidate without comparing totals let a $40
         * cheque process a $350 order (WWID-2318 UAT follow-up, 2026-08-27),
         * so the amount gate applies to the single-candidate path too.
         */
        if (count($orders) === 1) {
            $candidate = $orders[0];
            if (abs((float) $candidate->get_total() - $csvTotal) >= 0.01) {
                $this->lastFailure = sprintf(
                    'On Hold order #%d found for this Bar ID but the payment total %.2F does not equal the order total %.2F.',
                    (int) $candidate->get_id(),
                    $csvTotal,
                    (float) $candidate->get_total()
                );

                return null;
            }

            return $candidate;
        }

        // Multiple -> filter by total (tolerance 0.01).
        $byAmount = array_values(array_filter(
            $orders,
            static fn ($o): bool => abs((float) $o->get_total() - $csvTotal) < 0.01
        ));
        if (count($byAmount) === 1) {
            return $byAmount[0];
        }

        $this->lastFailure = self::reasonFor($row, count($orders), true);
        $logger?->warning('Ambiguous payment match; manual resolution required.', [
            'bar_id' => $barId,
            'csv_total' => $csvTotal,
            'candidates' => array_map(static fn ($o): int => (int) $o->get_id(), $orders),
        ]);

        return null;
    }

    /**
     * The reason string for a failed/ambiguous match (Story 11).
     *
     * @return string Human-readable reason.
     */
    public static function reasonFor(array $row, ?int $resolvedCount, bool $ambiguous): string
    {
        if ($resolvedCount === 0) {
            return 'No On Hold order found for this Bar ID.';
        }
        if ($ambiguous) {
            $csvTotal = (float) ($row['order_total'] ?? 0);

            return sprintf('Multiple On Hold orders found for this Bar ID and the payment total %.2F did not disambiguate them - manual resolution required.', $csvTotal);
        }

        return 'Unknown match failure.';
    }
}
