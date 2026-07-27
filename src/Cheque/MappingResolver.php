<?php

declare(strict_types=1);

namespace WicketCheque;

use WicketImporter\Mapping\MappingEntry;
use WicketImporter\Mapping\MappingRepository;
use WicketImporter\Services\Logger;

/**
 * Answers the bundle-renewal Filter #3
 * `wicket_mship_bundle_renewal_line_item_price` (decision D-LOCKBOX-2,
 * single-channel).
 *
 * Loads the active late-fee + discount mappings fresh from MappingRepository
 * (the HyperFields option, never stale row data) and, for the line item's
 * member, applies every mapping whose role matches the member's tier. Every
 * effect is communicated by mutating the passed `$item` / `$renewal_order`
 * directly via the WooCommerce API; the filter's return value is not
 * load-bearing (core recalculates totals once after the loop).
 *
 * ASSUMPTION (late-fee tally rule): the locked Filter #3 contract deliberately
 * leaves the tally rule to the implementer. This resolver treats it as "one
 * late-fee product line per matching active late-fee mapping": for each active
 * late_fee entry whose roleSlug equals the member's tier slug, one unit of that
 * mapping's product is added to the renewal order. Discounts: a 'coupon' entry
 * applies its coupon code; a 'product' entry is added as a line item (callers
 * expect a negative total for a discount product, set on the product itself).
 * Revise here if the real OBA/lockbox spec differs; the seam is
 * applyMappings().
 *
 * Safe by default: with no client-configured role-matched mappings (the seeded
 * defaults carry role slugs like 'late-fee-1' that do not match any real tier
 * slug), nothing is added and renewal pricing is unchanged.
 */
final class MappingResolver
{
    public function __construct(
        private readonly ?Logger $logger = null,
        private readonly ?MappingRepository $mappings = null,
    ) {}

    /**
     * Filter #3 callback (single-channel). Mutates the renewal order for
     * applicable late-fee + discount mappings. Return is ignored by core.
     *
     * @param object $item               The renewal-order line item (WC_Order_Item).
     * @param int    $itemId             Line item ID.
     * @param int    $membershipPostId   The member's wicket_membership post.
     * @param int    $userId             WP user ID of the member.
     * @param object $renewalOrder       The full renewal order (WC_Order).
     */
    public function applyLineItemAdjustments(
        object $item,
        int $itemId,
        int $membershipPostId,
        int $userId,
        object $renewalOrder,
    ): void {
        $role = $this->memberRole($membershipPostId);
        if ($role === '') {
            return;
        }

        $matched = $this->mappingsForRole($role);
        if ($matched['late_fees'] === [] && $matched['discounts'] === []) {
            return;
        }

        $this->applyMappings($matched, $renewalOrder);
    }

    /**
     * Load active late-fee + discount mappings that match a role.
     *
     * Centralized so the matching rule is one seam (role equality on the tier
     * slug today; widen here if a client needs partial / multi-role matching).
     *
     * @return array{late_fees: list<MappingEntry>, discounts: list<MappingEntry>}
     */
    public function mappingsForRole(string $role): array
    {
        $repo = $this->mappingRepository();
        $lateFees = array_values(array_filter(
            $repo->getActiveMappings('late_fee'),
            static fn (MappingEntry $m): bool => $m->roleSlug === $role
        ));
        $discounts = array_values(array_filter(
            $repo->getActiveMappings('discount'),
            static fn (MappingEntry $m): bool => $m->roleSlug === $role
        ));

        return ['late_fees' => $lateFees, 'discounts' => $discounts];
    }

    /**
     * Apply matched mappings to the renewal order.
     *
     * Each mutation is isolated in a try/catch: a single bad mapping (missing
     * product, throwing coupon) must not abort the rest of the order's
     * adjustments. Failures are logged and skipped.
     *
     * @param array{late_fees: list<MappingEntry>, discounts: list<MappingEntry>} $matched
     */
    private function applyMappings(array $matched, object $renewalOrder): void
    {
        foreach ($matched['late_fees'] as $fee) {
            try {
                $product = $this->product($fee->productId);
                if ($product === null) {
                    $this->logger?->warning('Late-fee product missing; skipping line.', ['role' => $fee->roleSlug, 'product_id' => $fee->productId]);
                    continue;
                }
                $renewalOrder->add_product($product, 1);
            } catch (\Throwable $e) {
                $this->logger?->warning('Late-fee application threw; continuing.', ['role' => $fee->roleSlug, 'error' => $e->getMessage()]);
            }
        }

        foreach ($matched['discounts'] as $discount) {
            try {
                if ($discount->applicationType === 'coupon') {
                    if ($discount->couponCode !== null && $discount->couponCode !== '') {
                        $renewalOrder->apply_coupon($discount->couponCode);
                    }
                    continue;
                }
                // 'product' discount: add the product line (the product's own
                // price carries the negative/discount value).
                $product = $this->product($discount->productId);
                if ($product !== null) {
                    $renewalOrder->add_product($product, 1);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Discount application threw; continuing.', ['role' => $discount->roleSlug, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The member's mapping role: the wicket_mship_tier post slug (post_name).
     * Role equality against MappingEntry::roleSlug is the match key.
     */
    private function memberRole(int $membershipPostId): string
    {
        $tierPostId = (int) get_post_meta($membershipPostId, 'membership_tier_post_id', true);
        if ($tierPostId === 0) {
            return '';
        }
        $tier = get_post($tierPostId);

        return $tier ? (string) $tier->post_name : '';
    }

    private function mappingRepository(): MappingRepository
    {
        return $this->mappings ?? new MappingRepository();
    }

    /**
     * Fetch a WC product by ID (null when missing or WC unavailable).
     */
    private function product(?int $productId): ?object
    {
        if ($productId === null || $productId === 0 || !function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product($productId);

        return $product !== false ? $product : null;
    }
}
