<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\BulkImport\MemberData;
use WicketImporter\BulkImport\Subscriptions\SubscriptionResult;
use WicketImporter\BulkImport\Subscriptions\ResolvedProducts;
use WicketImporter\Services\Logger;

/**
 * Creates the WooCommerce Subscriptions for one cheque-renewal row: a membership
 * subscription (the tier renewal product) and, when section products resolved,
 * a single section subscription carrying them as line items.
 *
 * Adapted from wicket-wp-memberships' Membership_Subscription_Controller::
 * create_subscriptions() for the async / Action Scheduler context: NO
 * wc_add_notice (no request/session), failures return a SubscriptionResult
 * error VO instead of mutating order status inline, and the
 * wicket_import_create_subscription action fires so extensions can adjust.
 *
 * INPUT-GAP ASSUMPTIONS (the create() contract is fixed as
 * create(int $orderId, MemberData, ResolvedProducts); OrderCreator + the
 * cheque BatchProcessor are not yet built, so these are documented calls to
 * revise when they land):
 *
 *   1. Membership post ID for the `_membership_post_id_renew` line-item meta is
 *      resolved at RUNTIME by querying the member's wicket_membership post for
 *      the row's tier (WP user derived from the order). When none is found, the
 *      meta is omitted and a warning logged; the subscription is still created.
 *      Revise when OrderCreator defines where the membership post is created.
 *   2. Section products are added as line items to ONE section subscription
 *      (a single wcs_create_subscription call). If per-section subscriptions
 *      are required, split createSectionSubscription().
 *   3. Billing period + interval come from the product's WC Subscriptions meta
 *      (WC_Subscriptions_Product::get_period/get_interval), not the
 *      memberships 'payment_terms' attribute. Start date is now; no explicit
 *      end date is forced (the subscription is open-ended) until the
 *      wicket_mship_config-driven calendar/anniversary end date is wired.
 */
class SubscriptionCreator
{
    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Create the membership + section subscriptions for a cheque-renewal row.
     *
     * @param int              $orderId    The On Hold order created by OrderCreator.
     * @param MemberData       $memberData The member context (row + tier).
     * @param ResolvedProducts $resolved   The resolved product set (WWID-2029).
     */
    public function create(int $orderId, MemberData $memberData, ResolvedProducts $resolved): SubscriptionResult
    {
        if (!$this->wcsAvailable()) {
            return SubscriptionResult::failed('WooCommerce Subscriptions is not available.');
        }

        $order = $this->order($orderId);
        if ($order === null) {
            return SubscriptionResult::failed(sprintf('Order %d not found.', $orderId));
        }

        $userId = (int) $order->get_user_id();
        $membershipPostId = $this->resolveMembershipPostId($userId, $memberData->tierPostId);
        if ($membershipPostId === 0) {
            $this->logger?->warning('No membership post found for the tier; _membership_post_id_renew meta omitted.', [
                'order' => $orderId, 'tier_post_id' => $memberData->tierPostId,
            ]);
        }

        $subscriptionIds = [];

        // 1. Membership subscription (primary; failure aborts).
        $membershipSubId = $this->createSubscriptionForProduct($order, $resolved->membershipProductId, $membershipPostId);
        if ($membershipSubId === 0) {
            return SubscriptionResult::failed('Failed to create the membership subscription.');
        }
        $subscriptionIds[] = $membershipSubId;

        // 2. Section subscription (secondary; failure is logged, not fatal).
        if ($resolved->sectionProductIds !== []) {
            $sectionSubId = $this->createSectionSubscription($order, $resolved->sectionProductIds, $membershipPostId);
            if ($sectionSubId === 0) {
                $this->logger?->warning('Section subscription creation failed; membership subscription stands.', ['order' => $orderId]);
            } else {
                $subscriptionIds[] = $sectionSubId;
            }
        }

        /**
         * Let extensions adjust after subscription creation (AD10 hook surface;
         * ImportAdapter fires the same action in the OBA flow).
         *
         * @param int    $membershipPostId The wicket_membership post (0 if unresolved).
         * @param int    $userId           WP user ID (from the order).
         * @param array  $row              Original CSV row.
         */
        do_action('wicket_import_create_subscription', $membershipPostId, $userId, $memberData->row);

        return SubscriptionResult::created($subscriptionIds);
    }

    /**
     * Create one subscription for a single product (the membership product).
     * Returns the subscription ID, or 0 on failure.
     */
    private function createSubscriptionForProduct(object $order, int $productId, int $membershipPostId): int
    {
        // B2: resolve the product BEFORE creating the subscription, else a bad
        // product ID leaves an orphan line-item-less pending subscription
        // parented to the order (and a retry mints another each attempt).
        $product = $this->product($productId);
        if ($product === null) {
            $this->logger?->warning('Membership product missing; not creating a subscription.', ['product_id' => $productId]);

            return 0;
        }

        $sub = $this->newPendingSubscription($order, $productId);
        if ($sub === null) {
            return 0;
        }

        $sub->add_product($product, 1);
        $this->stampMembershipMeta($sub, $membershipPostId);
        $sub->calculate_totals();
        $sub->save();

        return (int) $sub->get_id();
    }

    /**
     * Create one subscription carrying all section products as line items.
     * Returns the subscription ID, or 0 on failure.
     *
     * @param list<int> $productIds
     */
    private function createSectionSubscription(object $order, array $productIds, int $membershipPostId): int
    {
        // B2: resolve every product up front; bail if none resolve, so we never
        // create a subscription we then can't populate.
        $products = [];
        foreach ($productIds as $productId) {
            $product = $this->product($productId);
            if ($product !== null) {
                $products[] = $product;
            }
        }
        if ($products === []) {
            $this->logger?->warning('No section products resolved; not creating a section subscription.', ['product_ids' => $productIds]);

            return 0;
        }

        $sub = $this->newPendingSubscription($order, (int) $productIds[0]);
        if ($sub === null) {
            return 0;
        }

        foreach ($products as $product) {
            $sub->add_product($product, 1);
        }
        $this->stampMembershipMeta($sub, $membershipPostId);
        $sub->calculate_totals();
        $sub->save();

        return (int) $sub->get_id();
    }

    /**
     * Build a pending subscription parented to the order, with billing period
     * + interval read from the product's WC Subscriptions meta.
     */
    private function newPendingSubscription(object $order, int $productId): ?object
    {
        [$period, $interval] = $this->billingForProduct($productId);

        $sub = wcs_create_subscription([
            'order_id' => (int) $order->get_id(),
            'status' => 'pending',
            'billing_period' => $period,
            'billing_interval' => $interval,
            'start_date' => current_time('mysql', true),
        ]);

        if (is_wp_error($sub)) {
            $this->logger?->warning('wcs_create_subscription returned an error.', ['order' => $order->get_id(), 'error' => $sub->get_error_message()]);

            return null;
        }

        // Inherit the billing address so renewals can be charged.
        if (method_exists($order, 'get_address')) {
            $sub->set_address($order->get_address('billing'), 'billing');
        }

        // C4: a cheque subscription needs an explicit payment method + manual
        // renewal flag; wcs_create_subscription does NOT inherit the parent
        // order's payment method, so without these WCS cannot activate it.
        $this->setChequePaymentMethod($sub, $order);

        // C4: wcs_create_subscription only sets start_date. Without an explicit
        // next_payment the subscription never schedules a renewal order (silent
        // revenue loss, surfaced a billing cycle later).
        $next = $this->nextPaymentDate($period, $interval);
        if ($next !== '' && method_exists($sub, 'update_dates')) {
            $sub->update_dates(['next_payment' => $next]);
        }

        return $sub;
    }

    /**
     * Stamp `_membership_post_id_renew` on the subscription's first line item so
     * the memberships renewal pointer is preserved across cycles. Best-effort:
     * skipped silently when no membership post resolved.
     */
    private function stampMembershipMeta(object $subscription, int $membershipPostId): void
    {
        if ($membershipPostId === 0 || !method_exists($subscription, 'get_items')) {
            return;
        }
        $items = $subscription->get_items();
        $first = $items !== [] ? array_values($items)[0] : null;
        if ($first !== null && method_exists($first, 'update_meta_data')) {
            $first->update_meta_data('_membership_post_id_renew', $membershipPostId);
            $first->save();
        }
    }

    /**
     * Resolve the member's wicket_membership post for the row's tier.
     *
     * @return int Post ID, or 0 when none found.
     */
    private function resolveMembershipPostId(int $userId, int $tierPostId): int
    {
        if ($userId === 0 || $tierPostId === 0) {
            return 0;
        }
        // P1: per-row double-meta-join. Minimize the query cost (no found-row
        // count, no term/meta cache priming) and prefer the NEWEST matching post
        // (orderby date DESC) so an expired membership does not win over the
        // current one. Preferred long-term fix per the lockbox plan: pass the
        // membership post ID into create() once OrderCreator owns that step, so
        // this lookup disappears entirely.
        $posts = get_posts([
            'post_type' => $this->membershipCptSlug(),
            'numberposts' => 1,
            'post_status' => 'any',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                ['key' => 'user_id', 'value' => $userId],
                ['key' => 'membership_tier_post_id', 'value' => $tierPostId],
            ],
        ]);

        return !empty($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * Billing period + interval for a subscription product.
     *
     * @return array{0:string,1:int}
     */
    /**
     * Set the cheque payment method + manual-renewal flag on a subscription.
     * Cheque renewals are manual: WCS must not attempt an auto-charge against a
     * gateway the subscription does not have. The method is filterable so a
     * client can route to a different gateway.
     */
    private function setChequePaymentMethod(object $subscription, object $order): void
    {
        $method = method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '';
        if ($method === '') {
            /** @var string $method Filterable default payment method for importer-created subscriptions. */
            $method = (string) apply_filters('wicket_import_subscription_payment_method', 'cheque', $order);
        }
        if (method_exists($subscription, 'set_payment_method')) {
            $subscription->set_payment_method($method);
        }
        if (method_exists($subscription, 'set_requires_manual_renewal')) {
            $subscription->set_requires_manual_renewal(true);
        }
    }

    /**
     * Compute the next_payment date (UTC) from the billing period + interval.
     *
     * @return string Y-m-d H:i:s in UTC, or '' when the period is unrecognized.
     */
    private function nextPaymentDate(string $period, int $interval): string
    {
        $unit = match ($period) {
            'day'   => 'day',
            'week'  => 'week',
            'month' => 'month',
            'year'  => 'year',
            default => '',
        };
        if ($unit === '' || $interval < 1) {
            return '';
        }
        $next = strtotime("+{$interval} {$unit}s");

        return $next !== false ? gmdate('Y-m-d H:i:s', $next) : '';
    }

    private function billingForProduct(int $productId): array
    {
        $period = 'year';
        $interval = 1;
        if (class_exists('\WC_Subscriptions_Product')) {
            $p = \WC_Subscriptions_Product::get_period($productId);
            $i = \WC_Subscriptions_Product::get_interval($productId);
            if (is_string($p) && $p !== '') {
                $period = $p;
            }
            if (is_numeric($i) && (int) $i > 0) {
                $interval = (int) $i;
            }
        }

        return [$period, $interval];
    }

    private function wcsAvailable(): bool
    {
        return function_exists('wcs_create_subscription') && class_exists('\WC_Subscriptions_Product');
    }

    private function order(int $orderId): ?object
    {
        if (!function_exists('wc_get_order') || $orderId === 0) {
            return null;
        }
        $order = wc_get_order($orderId);

        return $order !== false ? $order : null;
    }

    private function product(int $productId): ?object
    {
        if (!function_exists('wc_get_product') || $productId === 0) {
            return null;
        }
        $product = wc_get_product($productId);

        return $product !== false ? $product : null;
    }

    private function membershipCptSlug(): string
    {
        if (class_exists('\Wicket_Memberships\Helper') && method_exists('\Wicket_Memberships\Helper', 'get_membership_cpt_slug')) {
            return (string) \Wicket_Memberships\Helper::get_membership_cpt_slug();
        }

        return 'wicket_membership';
    }
}
