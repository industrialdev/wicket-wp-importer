<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\Services\Logger;

/**
 * Orchestrates the Tier + Section + Mapping resolvers into the product set for
 * one cheque-renewal row, then runs the divergence check that gates whether
 * the row proceeds to order creation (lockbox plan Phase 4).
 *
 * This is the CHEQUE-FLOW orchestrator. It is NOT wired into the
 * BundleRenewalSubscriber (which only needs Tier + Mapping to answer the two
 * bundle filters directly); its consumer is the cheque OrderCreator /
 * BatchProcessor (Phase 4, not yet built). Built now so the resolver chain is
 * complete and testable in isolation.
 *
 * ASSUMPTIONS (documented; revise when the cheque flow's real inputs land):
 *   - Membership product = the TierResolver succession result (next-tier
 *     product). When no succession rule applies, falls back to the member's
 *     current product meta so a tier with no succession still renews.
 *   - Late-fee products ARE included in the expected total (they are charged).
 *   - Divergence tolerance is 0.01 (configurable via the DIVERGENCE_TOLERANCE
 *     constant; make filterable if a client needs it).
 */
class ProductResolver
{
    private const DIVERGENCE_TOLERANCE = 0.01;

    public function __construct(
        private readonly ?Logger $logger = null,
        private readonly ?TierResolver $tierResolver = null,
        private readonly ?SectionResolver $sectionResolver = null,
        private readonly ?MappingResolver $mappingResolver = null,
    ) {}

    /**
     * Resolve the product set for a cheque-renewal row and run the divergence check.
     *
     * @param int         $membershipPostId The member's wicket_membership post ID.
     * @param list<string> $sectionSlugs    Section slugs the member belongs to (caller-sourced).
     * @param float       $csvTotal         The order_total from the lockbox CSV row.
     */
    public function resolve(int $membershipPostId, array $sectionSlugs, float $csvTotal): ResolvedProducts
    {
        // Honesty guard: with no membership post, every meta read below returns
        // empty and a caller-side member-resolution failure would masquerade as
        // a tier-map miss. Keep the tier error meaning what it says.
        if ($membershipPostId <= 0) {
            return ResolvedProducts::error('No membership post supplied for this row.');
        }

        $membershipProductId = $this->resolveMembershipProduct($membershipPostId);
        if ($membershipProductId === 0) {
            return ResolvedProducts::error('No renewal product resolved for the member tier.');
        }

        $sectionProductIds = $this->sectionResolver()->resolveProducts($sectionSlugs);

        $lateFeeProductIds = $this->lateFeeProductIds($membershipPostId);

        // B5: only RECURRING products (membership + sections) must be WC
        // subscriptions. Late-fee products are one-off charges and only need to
        // exist. Requiring all of them to be subscriptions rejected every row
        // that carried a late fee.
        foreach (array_merge([$membershipProductId], $sectionProductIds) as $productId) {
            if (!$this->isSubscription($productId)) {
                return ResolvedProducts::error(
                    sprintf('Recurring product %d is missing or not a subscription product.', $productId)
                );
            }
        }
        foreach ($lateFeeProductIds as $productId) {
            if (!$this->productExists($productId)) {
                return ResolvedProducts::error(sprintf('Late-fee product %d does not exist.', $productId));
            }
        }

        $expected = 0.0;
        foreach (array_merge([$membershipProductId], $sectionProductIds, $lateFeeProductIds) as $productId) {
            $expected += $this->productPrice($productId);
        }
        // B6: product-type discounts join the order as their own negative-
        // priced lines so a discounted member is not false-positive gated.
        // Coupon-type discounts can't be priced without cart context; they
        // ride to OrderCreator as codes and are applied at creation.
        [$discountProductIds, $couponCodes] = $this->discountsFor($membershipPostId);
        foreach ($discountProductIds as $productId) {
            $expected += $this->productPrice($productId);
        }

        // B6: compare in integer cents with a filterable tolerance (a float >
        // 0.01 comparison drifts on the edge and is not adjustable per client).
        $tolerance = (float) apply_filters('wicket_import_divergence_tolerance', self::DIVERGENCE_TOLERANCE);
        $divergent = abs((int) round($expected * 100) - (int) round($csvTotal * 100))
            > (int) round($tolerance * 100);

        return new ResolvedProducts(
            membershipProductId: $membershipProductId,
            sectionProductIds: $sectionProductIds,
            lateFeeProductIds: $lateFeeProductIds,
            expectedTotal: $expected,
            divergent: $divergent,
            discountProductIds: $discountProductIds,
            couponCodes: $couponCodes,
        );
    }

    /**
     * Membership renewal product: TierResolver succession result, falling back
     * to the member's current product meta when no succession rule applies.
     */
    private function resolveMembershipProduct(int $membershipPostId): int
    {
        $tier = $this->tierResolver()->resolveRenewalTier($membershipPostId);
        if ($tier !== null && $tier['product_id'] > 0) {
            return $tier['product_id'];
        }

        // No succession rule: renew the member's current product.
        return (int) get_post_meta($membershipPostId, 'membership_product_id', true);
    }

    /**
     * Late-fee product IDs applicable to the member's WP user roles (via the
     * shared MappingResolver::memberRoles resolver; one fee line per matching
     * mapping, overlapping roles stack — spec Story 6).
     */
    private function lateFeeProductIds(int $membershipPostId): array
    {
        $roles = MappingResolver::memberRoles($membershipPostId);
        if ($roles === []) {
            return [];
        }

        $entries = $this->mappingResolver()->mappingsForRoles($roles)['late_fees'] ?? [];
        $ids = array_map(static fn ($e): int => (int) ($e->resolveProductId() ?? 0), $entries);

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * Does the product exist (regardless of subscription-ness)? Used for
     * one-off late-fee products that are not subscriptions but must be purchasable.
     */
    private function productExists(int $productId): bool
    {
        if ($productId === 0 || !function_exists('wc_get_product')) {
            return false;
        }

        return wc_get_product($productId) !== false;
    }

    /**
     * Role-keyed discount mappings for the member, split by application type.
     * Product-type discounts are negative-priced lines (part of the order
     * total); coupon-type discounts are applied to the order at creation.
     *
     * @return array{0: list<int>, 1: list<string>} [discountProductIds, couponCodes]
     */
    private function discountsFor(int $membershipPostId): array
    {
        $roles = MappingResolver::memberRoles($membershipPostId);
        if ($roles === []) {
            return [[], []];
        }

        $entries = $this->mappingResolver()->mappingsForRoles($roles)['discounts'] ?? [];

        $productIds = [];
        $couponCodes = [];
        foreach ($entries as $entry) {
            // SKU-canonical (D-LOCKBOX-1): entries may carry only a SKU;
            // resolve at call time like every other mapping consumer.
            // Reading $entry->productId directly here silently dropped every
            // SKU-only discount and caused a false-positive divergence vs the
            // actual order total MappingResolver::applyMappings() builds.
            if (($entry->applicationType ?? '') === 'product') {
                $resolved = $entry->resolveProductId();
                if ($resolved !== null && $resolved > 0) {
                    $productIds[] = $resolved;
                }
            } elseif (($entry->applicationType ?? '') === 'coupon' && !empty($entry->couponCode)) {
                $couponCodes[] = (string) $entry->couponCode;
            }
        }

        return [$productIds, $couponCodes];
    }

    /**
     * True when the product exists and is a WC Subscriptions product.
     */
    private function isSubscription(int $productId): bool
    {
        if ($productId === 0 || !function_exists('wc_get_product')) {
            return false;
        }
        $product = wc_get_product($productId);
        if ($product === false) {
            return false;
        }
        if (!class_exists('\WC_Subscriptions_Product')) {
            return false;
        }

        return (bool) \WC_Subscriptions_Product::is_subscription($productId);
    }

    private function productPrice(int $productId): float
    {
        if (!function_exists('wc_get_product')) {
            return 0.0;
        }
        $product = wc_get_product($productId);

        return $product !== false ? (float) $product->get_price() : 0.0;
    }

    private function tierResolver(): TierResolver
    {
        return $this->tierResolver ?? new TierResolver($this->logger);
    }

    private function sectionResolver(): SectionResolver
    {
        return $this->sectionResolver ?? new SectionResolver($this->logger);
    }

    private function mappingResolver(): MappingResolver
    {
        return $this->mappingResolver ?? new MappingResolver($this->logger);
    }
}
