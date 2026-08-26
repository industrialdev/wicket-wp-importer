<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\Services\Logger;

/**
 * Default handler for the inline member import's subscription create-seam.
 *
 * ImportAdapter fires `wicket_import_create_subscription` on the OBA inline
 * path because core does not call WCS there. This class IS that capability:
 * the generic, client-agnostic, no-order subscription creator (AD1:
 * subscription creation is a core importer capability; client themes invoke
 * it, they never reimplement it). Registering this as the default handler
 * means every client inherits subscription creation on import without writing
 * WCS code.
 *
 * Spec (member-upload contract): create a subscription for the user with the
 * membership product, the membership id as line-item meta, start = membership
 * start, end = membership grace-period end (expires_at), and NO parent order.
 *
 * Status is 'pending' + manual renewal: the wicket_membership CPT remains the
 * source of truth for membership status, and pending avoids WCS renewal-order
 * generation and gateway charges. Mirrors the cheque SubscriptionCreator,
 * which also creates pending and activates in a separate step.
 *
 * Idempotent: a core-owned meta key on the membership post
 * (`_wicket_import_subscription_id`) guards against duplicate creation on
 * Scenario-B re-runs and retries. The canonical `membership_subscription_id`
 * cannot guard this — the memberships plugin overwrites it to '' on every
 * re-run before this hook fires.
 *
 * Self-registering: instantiated in WicketImporter::plugin_setup(); its
 * constructor hooks `wicket_import_create_subscription`.
 */
final class InlineSubscriptionCreator
{
    /**
     * Core-owned idempotency meta key stamped on the membership CPT.
     */
    public const SUBSCRIPTION_META_KEY = '_wicket_import_subscription_id';

    public function __construct(private readonly ?Logger $logger = null)
    {
        add_action('wicket_import_create_subscription', [$this, 'create'], 10, 4);
    }

    /**
     * Create the no-order membership subscription for one imported member.
     *
     * Hooked on `wicket_import_create_subscription`.
     *
     * @param int   $membershipPostId wicket_membership CPT post ID.
     * @param int   $userId           WP user ID the membership was created for.
     * @param array $row              Original CSV row.
     * @param int   $stagingId        Staged row ID (0 when the caller had none);
     *                                the created subscription ID is written back
     *                                onto the staged row for the results CSV.
     */
    public function create(int $membershipPostId, int $userId, array $row = [], int $stagingId = 0): void
    {
        if (! function_exists('wcs_create_subscription') || ! class_exists('WC_Subscriptions_Product')) {
            $this->logger?->warning('Inline subscription creator skipped: WCS not available.', [
                'membership_post_id' => $membershipPostId,
            ]);

            return;
        }

        if ($membershipPostId <= 0 || $userId <= 0 || get_post($membershipPostId) === null) {
            $this->logger?->warning('Inline subscription creator skipped: invalid membership post or user.', [
                'membership_post_id' => $membershipPostId,
                'user_id'            => $userId,
            ]);

            return;
        }

        // Idempotency: a prior run of this handler already linked a subscription.
        $existing = (int) get_post_meta($membershipPostId, self::SUBSCRIPTION_META_KEY, true);
        if ($existing > 0) {
            $this->logger?->info('Inline subscription creator skipped: membership already linked to a subscription.', [
                'membership_post_id' => $membershipPostId,
                'subscription_id'    => $existing,
            ]);

            // A retry of a staged row whose membership already carries a
            // subscription must still report that ID (WWID-2350): the first run
            // predates the write-back, and the results CSV reads the staged row.
            $this->recordSubscriptionOnStagingRow($stagingId, [$existing]);

            return;
        }

        $productId = $this->resolveProductId($membershipPostId);
        if ($productId === 0) {
            return; // logged inside the resolver
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : false;
        if (! $product instanceof \WC_Product) {
            $this->logger?->warning('Inline subscription creator skipped: tier product could not be loaded.', [
                'membership_post_id' => $membershipPostId,
                'product_id'         => $productId,
            ]);

            return;
        }

        [$period, $interval] = $this->billingForProduct($productId);

        $start = $this->date((string) get_post_meta($membershipPostId, 'membership_starts_at', true));
        $end   = $this->date(
            (string) (get_post_meta($membershipPostId, 'membership_expires_at', true)
                ?: get_post_meta($membershipPostId, 'membership_ends_at', true))
        );

        if ($start === '') {
            $this->logger?->warning('Inline subscription creator skipped: membership has no start date.', [
                'membership_post_id' => $membershipPostId,
            ]);

            return;
        }

        $sub = wcs_create_subscription([
            'customer_id'      => $userId,
            'status'           => 'pending',
            'billing_period'   => $period,
            'billing_interval' => $interval,
            'start_date'       => $start,
            'created_via'      => 'wicket-importer',
        ]);

        if (is_wp_error($sub)) {
            $this->logger?->error('Inline subscription creation failed.', [
                'membership_post_id' => $membershipPostId,
                'user_id'            => $userId,
                'product_id'         => $productId,
                'error_code'         => $sub->get_error_code(),
                'error'              => $sub->get_error_message(),
            ]);

            return;
        }

        $sub->add_product($product, 1);
        $this->stampMembershipMeta($sub, $membershipPostId);

        // Manual renewal with a filterable placeholder method; imported members
        // carry no payment token. 'cheque' matches the cheque-flow default.
        $method = (string) apply_filters('wicket_import_subscription_payment_method', 'cheque', $userId, $row);
        if (method_exists($sub, 'set_payment_method')) {
            $sub->set_payment_method($method);
        }
        if (method_exists($sub, 'set_requires_manual_renewal')) {
            $sub->set_requires_manual_renewal(true);
        }

        // Billing address + email so tax/renewal notices resolve to the member.
        if (class_exists(\WC_Customer::class)) {
            $customer = new \WC_Customer($userId);
            if (method_exists($sub, 'set_address')) {
                $sub->set_address($customer->get_billing(), 'billing');
            }
        }
        $wpUser = function_exists('get_user_by') ? get_user_by('ID', $userId) : false;
        if ($wpUser !== false && method_exists($sub, 'set_billing_email')) {
            $sub->set_billing_email($wpUser->user_email);
        }

        // Persist BEFORE dates. update_dates() throws (it does not return
        // WP_Error); persisting here keeps the line item + payment + the
        // membership link even if the date update later rejects, so a retry
        // never mints an orphan line-item-less subscription.
        $sub->calculate_totals();
        $sub->save();

        $subscriptionId = (int) $sub->get_id();

        // Link the subscription back to the membership CPT. The canonical key is
        // what the memberships plugin consumes; the private key is the idempotency
        // guard (the memberships plugin overwrites the canonical one to '' on
        // every re-run before this hook fires).
        update_post_meta($membershipPostId, 'membership_subscription_id', $subscriptionId);
        update_post_meta($membershipPostId, self::SUBSCRIPTION_META_KEY, $subscriptionId);

        // Report the created subscription on the staged row (WWID-2350): the
        // results CSV's "Subscription IDs" column reads the staged row, not the
        // membership postmeta, and there is no order to carry the ID.
        $this->recordSubscriptionOnStagingRow($stagingId, [$subscriptionId]);

        // End date is a refinement on a complete subscription; apply it last and
        // best-effort. A rejection (e.g. end <= next_payment) must not demote the
        // already-created subscription.
        if ($end !== '') {
            try {
                $sub->update_dates(['end' => $end]);
                $sub->save();
            } catch (\Throwable $e) {
                $this->logger?->warning('Inline subscription end date not applied; subscription still created.', [
                    'membership_post_id' => $membershipPostId,
                    'subscription_id'    => $subscriptionId,
                    'end'                => $end,
                    'error'              => $e->getMessage(),
                ]);
            }
        }

        $this->logger?->info('Inline subscription created.', [
            'membership_post_id' => $membershipPostId,
            'user_id'            => $userId,
            'product_id'         => $productId,
            'subscription_id'    => $subscriptionId,
            'start'              => $start,
            'end'                => $end,
        ]);
    }

    /**
     * Resolve the WC product ID (variation preferred) for the membership's tier.
     *
     * The variation is preferred over the parent variable product: the parent
     * carries no price, so a 0-total subscription would result.
     *
     * @return int Product or variation ID, or 0 when unresolved.
     */
    private function resolveProductId(int $membershipPostId): int
    {
        $tierPostId = (int) get_post_meta($membershipPostId, 'membership_tier_post_id', true);
        if ($tierPostId <= 0 || ! class_exists(\Wicket_Memberships\Membership_Tier::class)) {
            $this->logger?->warning('Inline subscription creator skipped: no tier on membership / Membership_Tier missing.', [
                'membership_post_id' => $membershipPostId,
                'tier_post_id'       => $tierPostId,
            ]);

            return 0;
        }

        $tier = new \Wicket_Memberships\Membership_Tier($tierPostId);
        $productsData = $tier->get_products_data();
        $productsData = is_array($productsData) ? $productsData : [];

        if ($productsData === []) {
            $this->logger?->warning('Inline subscription creator skipped: tier has no linked product.', [
                'membership_post_id' => $membershipPostId,
                'tier_post_id'       => $tierPostId,
            ]);

            return 0;
        }

        if (count($productsData) > 1) {
            $this->logger?->warning('Tier maps to multiple products; using the first for the subscription.', [
                'membership_post_id' => $membershipPostId,
                'tier_post_id'       => $tierPostId,
                'products_data'      => $productsData,
            ]);
        }

        $entry = $productsData[0] ?? [];
        $variationId = (int) ($entry['variation_id'] ?? 0);
        if ($variationId > 0) {
            return $variationId;
        }

        return (int) ($entry['product_id'] ?? 0);
    }

    /**
     * Billing period + interval from the product's WC Subscriptions meta.
     * Defaults to yearly when unset.
     *
     * @return array{0:string,1:int}
     */
    private function billingForProduct(int $productId): array
    {
        $period = 'year';
        $interval = 1;

        $p = \WC_Subscriptions_Product::get_period($productId);
        $i = \WC_Subscriptions_Product::get_interval($productId);
        if (is_string($p) && $p !== '') {
            $period = $p;
        }
        if (is_numeric($i) && (int) $i > 0) {
            $interval = (int) $i;
        }

        return [$period, $interval];
    }

    /**
     * Write subscription IDs onto the staged row when the caller provided one.
     * Direct construction (no plugin singleton) mirrors the extension-side
     * staging writes; the guard keeps call sites without a staged row free.
     *
     * @param list<int> $subscriptionIds
     */
    private function recordSubscriptionOnStagingRow(int $stagingId, array $subscriptionIds): void
    {
        if ($stagingId <= 0 || $subscriptionIds === []) {
            return;
        }

        (new \WicketImporter\BulkImport\Database\ImportStagingTable())->updateSubscriptionIds($stagingId, $subscriptionIds);
    }

    /**
     * Normalize an ISO-8601 datetime (membership CPT meta is stored as
     * ->format('c')) to the 'Y-m-d H:i:s' UTC form wcs_create_subscription /
     * update_dates expect.
     */
    private function date(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        $ts = strtotime($iso);

        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    /**
     * Stamp `_membership_post_id_renew` on the subscription's first line item so
     * the memberships renewal pointer is preserved across cycles.
     */
    private function stampMembershipMeta(object $subscription, int $membershipPostId): void
    {
        if (! method_exists($subscription, 'get_items')) {
            return;
        }
        $items = $subscription->get_items();
        if ($items === []) {
            return;
        }
        $first = array_values($items)[0];
        if ($first !== null && method_exists($first, 'update_meta_data')) {
            $first->update_meta_data('_membership_post_id_renew', $membershipPostId);
            $first->save();
        }
    }
}
