<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Scopes role-keyed renewal coupons to the entitled member's line items.
 *
 * A WC coupon applied to a bundle renewal order is order-wide: WooCommerce
 * discounts every line matching the coupon's product rules, entitled member
 * or not (WWID-2616: on feature1 order 29679, one member's coupon discounted
 * a different member's same-section line). The coupons must stay real WC
 * coupons (client coupon reporting reads them), so the money is scoped
 * instead: MappingResolver offers the entitlement stamp on the entitled
 * line item BEFORE apply_coupon() runs, and this guard drops every other
 * line from the coupon's application list.
 *
 * The hook is woocommerce_coupon_get_items_to_apply because it is the only
 * coupon filter that runs in order context: woocommerce_coupon_get_discount_amount
 * fires from WC_Discounts only when the discounted object is a WC_Cart.
 *
 * Orders without engine stamps (storefront carts, plain cheque orders) and
 * coupons whose code matches no stamp (an admin's manual coupon) pass
 * through untouched. The client's wicket_import_discount_applies veto still
 * gates whether the coupon is offered at all.
 */
final class ScopedCouponGuard
{
    /**
     * Per-line entitlement stamps. The stamp lives on the WC_Order_Item, so
     * it survives order edits and recalcs: a later WC_Discounts run re-reads
     * the same stamps and re-scopes identically.
     */
    public const ATTRIBUTION_META = '_wicket_lockbox_coupon_code';

    public const ROLE_ATTRIBUTION_META = '_wicket_lockbox_coupon_role_slug';

    public static function register(): void
    {
        add_filter('woocommerce_coupon_get_items_to_apply', [self::class, 'scopeCouponItems'], 10, 3);
    }

    /**
     * Drops non-entitled lines from an engine coupon's application list.
     *
     * @param list<object> $itemsToApply WC_Discounts item entries (stdClass with ->object).
     * @param mixed        $coupon       WC_Coupon.
     * @param mixed        $discounts    WC_Discounts (duck-typed: get_object + get_items).
     *
     * @return list<object>
     */
    public static function scopeCouponItems(array $itemsToApply, mixed $coupon, mixed $discounts): array
    {
        if (!is_object($coupon) || !is_object($discounts)
            || !method_exists($discounts, 'get_object') || !method_exists($discounts, 'get_items')) {
            return $itemsToApply;
        }

        if (!$discounts->get_object() instanceof \WC_Order) {
            return $itemsToApply;
        }

        $stamps = [];
        foreach ($discounts->get_items() as $entry) {
            $stamps += self::itemStamps($entry->object ?? null);
        }

        $code = self::formatCode($coupon->get_code() ?? '');
        if ($stamps === [] || !isset($stamps[$code])) {
            return $itemsToApply;
        }

        return array_values(array_filter(
            $itemsToApply,
            static fn ($entry): bool => isset(self::itemStamps($entry->object ?? null)[$code]),
        ));
    }

    /**
     * The item's entitlement stamps as a formatted-code set, [] when
     * unstamped. A line can qualify for several role coupons and
     * apply_coupon() recalculates EVERY coupon on the order, so each earlier
     * coupon's recalculation must still find its stamp: codes ride as one
     * meta row per offer, not one overwritable scalar.
     *
     * @return array<string, true>
     */
    private static function itemStamps(mixed $item): array
    {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return [];
        }

        $stamps = [];
        foreach ((array) $item->get_meta(self::ATTRIBUTION_META, false) as $row) {
            // WC stores rows as WC_Meta_Data; the flat string covers legacy
            // single-row orders and lightweight doubles.
            $value = is_object($row) && isset($row->value) ? $row->value : $row;
            $formatted = self::formatCode($value);
            if ($formatted !== '') {
                $stamps[$formatted] = true;
            }
        }

        return $stamps;
    }

    /**
     * wc_format_coupon_code is WooCommerce's code identity: without it a
     * casing or whitespace difference would bypass the scope while WC still
     * honors the differently-written code on the order.
     */
    private static function formatCode(mixed $code): string
    {
        if (!is_string($code) || $code === '') {
            return '';
        }

        return wc_format_coupon_code($code);
    }
}
