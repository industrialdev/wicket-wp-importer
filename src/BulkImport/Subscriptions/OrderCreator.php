<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\BulkImport\MemberData;
use WicketImporter\Services\Logger;

/**
 * Creates the On Hold WooCommerce order for one cheque-renewal row: the resolved
 * membership + section + late-fee products as line items, the cheque payment
 * method, associated with the member's WC customer account.
 *
 * Generic by design (AD1). Core resolves the WC customer from the authoritative
 * MDP person UUID (the same path ImportAdapter uses), NEVER from any client
 * identifier. A client that keys members on a different identifier (OBA on Bar
 * ID) overrides the resolution via the wicket_import_resolve_order_customer
 * filter; core is agnostic to what that identifier is.
 *
 * Per spec Story 7, the WC order total reflects the resolved product prices (not
 * the CSV order_total, which is the divergence anchor ProductResolver checks).
 * The created order ID feeds SubscriptionCreator::create().
 */
class OrderCreator
{
    /**
     * Default payment method for importer-created orders. Filterable via
     * wicket_import_order_payment_method so a client can route elsewhere.
     */
    public const DEFAULT_PAYMENT_METHOD = 'cheque';

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Create the On Hold order for a row's resolved products.
     *
     * @return OrderResult created (carries the order ID) or failed.
     */
    public function create(MemberData $data, ResolvedProducts $resolved, ?string $batchLabel = null, int $membershipPostId = 0): OrderResult
    {
        if ($resolved->isError()) {
            return OrderResult::failed('Product resolution failed: ' . (string) $resolved->error);
        }

        if (!function_exists('wc_create_order')) {
            return OrderResult::failed('WooCommerce is not available.');
        }

        // Resolve the WC customer. Generic default: MDP person UUID -> WP user
        // (mirrors ImportAdapter::resolveUserId). A client overrides the
        // identifier (e.g. Bar ID -> WP user) via the filter; core never
        // references the client-specific identifier.
        $userId = (int) apply_filters(
            'wicket_import_resolve_order_customer',
            $this->resolveUserIdFromPerson($data),
            $data,
            $resolved
        );

        // A row that resolves no WC customer must never become a guest On Hold
        // order: payment matching (Phase 2) keys on the member's user, and the
        // D3 duplicate check below only runs for a known customer. Live for any
        // client whose membership seam resolves while the customer seam does
        // not (peer review 2026-08-26).
        if ($userId <= 0) {
            return OrderResult::failed('Could not resolve a WooCommerce customer for this row.');
        }

        /*
         * D3: a member with an existing bulk-created On Hold order already
         * awaits payment matching. A second order for the same member would
         * fork the lockbox match, so the row skips with an error for a human
         * to reconcile. Covers previous-run leftovers AND duplicates inside
         * the same run (row N creates the order; row N+k for the same member
         * skips). Scoped to _batch_id-bearing orders so an UNRELATED On Hold
         * order (stuck cart, manual payment) never blocks a renewal (peer
         * review 2026-08-21). Correct under the engine's single-chain,
         * sequential Action Scheduler model; revisit if chunks ever run
         * concurrently (check-then-create is not atomic across processes).
         */
        if ($userId > 0 && function_exists('wc_get_orders')) {
            $existing = wc_get_orders([
                'customer_id' => $userId,
                'status'      => ['on-hold'],
                'limit'       => 1,
                'return'      => 'objects',
                'meta_query'  => [
                    [
                        'key'     => '_batch_id',
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);
            if (is_array($existing) && $existing !== []) {
                $hold = $existing[0];

                return OrderResult::failed(sprintf(
                    'Existing On Hold order #%d for this member; row skipped so payment matching stays unique.',
                    (int) $hold->get_id()
                ));
            }
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            return OrderResult::failed('Could not create the order: ' . $order->get_error_message());
        }

        if ($userId > 0) {
            $order->set_customer_id($userId);
        }
        $this->applyPaymentMethod($order);

        // Story 12: the run's human-readable batch label on every order so
        // bulk and manual (Story 13) orders group under the same _batch_id key.
        if ($batchLabel !== null && $batchLabel !== '') {
            $order->update_meta_data('_batch_id', $batchLabel);
        }

        // Line items: membership renewal + sections + late fees + discount
        // products (negative-priced). Returns the count added so a fully-empty
        // set (every product missing) fails closed instead of producing an
        // empty order.
        if ($this->addLineItems($order, $resolved) === 0) {
            return OrderResult::failed('No products could be added to the order.');
        }

        // Coupon-type discounts (WWID-2436): product-type discounts ride in as
        // line items above; coupons apply at order level. Failures are logged
        // and non-fatal, mirroring MappingResolver::applyMappings.
        foreach ($resolved->couponCodes as $couponCode) {
            try {
                $order->apply_coupon($couponCode);
            } catch (\Throwable $e) {
                $this->logger?->warning('Discount coupon application threw; continuing.', ['coupon' => $couponCode, 'error' => $e->getMessage()]);
            }
        }

        // Story 5: stamp _membership_post_id_renew on every membership-linked
        // ORDER line item (membership + sections; NOT late fees) so the
        // Memberships plugin recognizes each as a renewal downstream.
        if ($membershipPostId > 0) {
            $this->stampRenewalMetaOnOrderItems($order, $resolved, $membershipPostId);
        }

        $order->calculate_totals();
        // Set status last so status-transition hooks fire on a complete order.
        $order->set_status('on-hold');
        $order->save();

        return OrderResult::created((int) $order->get_id());
    }

    /**
     * Add every resolved product as a qty-1 line item. Missing products are
     * skipped (logged downstream via the resolver's warnings) but do not abort
     * the order, matching the spec's "section/product not mapped -> flag, do
     * not block". Returns the number added.
     *
     * @param object $order
     *
     * @return int
     */
    private function addLineItems(object $order, ResolvedProducts $resolved): int
    {
        $ids = array_values(array_filter(array_merge(
            $resolved->membershipProductId > 0 ? [$resolved->membershipProductId] : [],
            $resolved->sectionProductIds,
            $resolved->lateFeeProductIds,
            // Discount products carry their own negative price (WWID-2436):
            // they reduce the order total to match the member's discounted
            // rate, keeping the order total consistent with the divergence
            // check's expected total.
            $resolved->discountProductIds,
        ), static fn ($id): bool => $id > 0));

        $added = 0;
        foreach ($ids as $productId) {
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : false;
            if ($product === false) {
                continue;
            }
            $order->add_product($product, 1);
            $added++;
        }

        return $added;
    }

    /**
     * Stamp _membership_post_id_renew on the order's membership-linked line
     * items (membership product + section products; late-fee items are not
     * memberships and stay unstamped). Matching is by product ID, qty 1 each.
     */
    private function stampRenewalMetaOnOrderItems(object $order, ResolvedProducts $resolved, int $membershipPostId): void
    {
        if (!method_exists($order, 'get_items')) {
            return;
        }

        $stampable = array_values(array_filter(array_merge(
            $resolved->membershipProductId > 0 ? [$resolved->membershipProductId] : [],
            $resolved->sectionProductIds,
        ), static fn ($id): bool => $id > 0));
        if ($stampable === []) {
            return;
        }

        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product_id') || !method_exists($item, 'update_meta_data')) {
                continue;
            }
            if (in_array((int) $item->get_product_id(), $stampable, true)) {
                $item->update_meta_data('_membership_post_id_renew', $membershipPostId);
                $item->save();
            }
        }
    }

    /**
     * Resolve (creating if absent) the WP user for the MDP person UUID.
     * Mirrors ImportAdapter::resolveUserId / the memberships Import_Controller:
     * person UUID as login, names + email forwarded so the base helper does not
     * re-fetch the person.
     */
    private function resolveUserIdFromPerson(MemberData $data): int
    {
        $user = function_exists('get_user_by') ? get_user_by('login', $data->personUuid) : false;
        if ($user !== false && isset($user->ID)) {
            return (int) $user->ID;
        }

        if (!function_exists('wicket_create_wp_user_if_not_exist')) {
            return 0;
        }

        $id = wicket_create_wp_user_if_not_exist(
            $data->personUuid,
            $data->person['first_name'] ?? null,
            $data->person['last_name'] ?? null,
            $data->person['email'] ?? null
        );

        return $id === false ? 0 : (int) $id;
    }

    /**
     * Set the (filterable) payment method on the order.
     *
     * @param object $order
     */
    private function applyPaymentMethod(object $order): void
    {
        $method = (string) apply_filters('wicket_import_order_payment_method', self::DEFAULT_PAYMENT_METHOD);
        if (method_exists($order, 'set_payment_method')) {
            $order->set_payment_method($method);
        }
    }
}
