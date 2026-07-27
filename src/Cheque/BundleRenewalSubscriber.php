<?php

declare(strict_types=1);

namespace WicketCheque;

use WicketImporter\Services\Logger;

/**
 * Subscribes the Lockbox (wicket-wp-importer) to the bundle-renewal filters
 * fired by wicket-wp-memberships (decision D-LOCKBOX-2: PULL, not PUSH).
 *
 * The importer owns the resolver answers; the Memberships plugin owns the
 * renewal order. This class is the single wiring point: it hooks the filters
 * and delegates to the Phase 4 resolver chain. Adding a resolver = adding a
 * method here; the resolver classes themselves stay free of WP add_filter noise.
 *
 * Filter #2 (wicket_mship_bundle_renewal_member_tier_product) -> TierResolver.
 * Filter #3 (wicket_mship_bundle_renewal_line_item_price) -> MappingResolver
 * (wired when MappingResolver ships; left as a documented TODO until then).
 */
final class BundleRenewalSubscriber
{
    private TierResolver $tierResolver;

    private MappingResolver $mappingResolver;

    public function __construct(?Logger $logger = null)
    {
        $this->tierResolver = new TierResolver($logger);
        $this->mappingResolver = new MappingResolver($logger);

        // Filter #2: tier/product override. 6 args = override value + 5 context args.
        add_filter('wicket_mship_bundle_renewal_member_tier_product', [$this, 'resolveRenewalTierProduct'], 10, 6);

        // Filter #3: per-line-item price/fee adjustment (single-channel). 6 args.
        // The callback mutates $renewal_order directly; return value is ignored by core.
        add_filter('wicket_mship_bundle_renewal_line_item_price', [$this, 'applyLineItemPrice'], 10, 6);
    }

    /**
     * Filter #2 callback: answer the member's renewal tier/product from the
     * succession map, or defer to core's default.
     *
     * Respects a prior non-null override: if another subscriber already
     * answered, do not clobber it (composability with any future consumer).
     *
     * @param mixed $override             Current value (null until someone answers).
     * @param int   $oldMembershipPostId  The expiring wicket_membership post.
     * @param int   $userId               WP user ID of the renewing member.
     * @param int   $newBundlePostId      The new bundle post.
     * @param int   $oldBundlePostId      The prior bundle post.
     * @param array $coreDefault          Core's own tier/product decision, for reference.
     *
     * @return array{tier_post_id:int, product_id:int}|null
     */
    public function resolveRenewalTierProduct(
        mixed $override,
        int $oldMembershipPostId,
        int $userId,
        int $newBundlePostId,
        int $oldBundlePostId,
        array $coreDefault,
    ): ?array {
        if ($override !== null) {
            return $override;
        }

        return $this->tierResolver->resolveRenewalTier($oldMembershipPostId);
    }

    /**
     * Filter #3 callback (single-channel): apply the member's late-fee +
     * discount mappings by mutating the renewal order directly. Return value is
     * ignored by core (decision D-LOCKBOX-2).
     *
     * @param mixed $override          Ignored (single-channel; kept for arg position).
     * @param mixed $item              The renewal-order line item (WC_Order_Item).
     * @param int   $itemId            Line item ID.
     * @param int   $membershipPostId  The member's wicket_membership post.
     * @param int   $userId            WP user ID.
     * @param mixed $renewalOrder      The renewal order (WC_Order).
     */
    public function applyLineItemPrice(
        mixed $override,
        mixed $item,
        int $itemId,
        int $membershipPostId,
        int $userId,
        mixed $renewalOrder,
    ): null {
        if (is_object($renewalOrder)) {
            $this->mappingResolver->applyLineItemAdjustments(
                is_object($item) ? $item : new \stdClass(),
                $itemId,
                $membershipPostId,
                $userId,
                $renewalOrder
            );
        }

        return null;
    }
}
