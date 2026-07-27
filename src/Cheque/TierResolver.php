<?php

declare(strict_types=1);

namespace WicketCheque;

use WicketImporter\Services\Logger;

/**
 * Resolves a member's renewal tier + product from the client-supplied tier
 * succession map (decision D-LOCKBOX-1: engine/map split).
 *
 * The ENGINE lives here (importer core); the MAP is client data supplied via
 * the `wicket_lockbox_tier_succession_map` filter (default empty). The map is
 * keyed by tier NAME and references the next tier by NAME plus the renewal
 * product by SKU, so it carries zero environment-specific post/product IDs.
 * Names and SKUs resolve to IDs at call time.
 *
 * Answers the bundle-renewal Filter #2 `wicket_mship_bundle_renewal_member_tier_product`
 * (decision D-LOCKBOX-2, PULL architecture): a non-null return fully overrides
 * core's renewal_type/next_tier_id decision; null means "no succession rule
 * applies, use core's own default."
 *
 * Safe by default: with no client map registered, resolveRenewalTier() always
 * returns null, so bundle renewal behaves exactly as it does today.
 */
final class TierResolver
{
    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Resolve the renewal tier + product for a member's current tier.
     *
     * @param int $oldMembershipPostId The member's expiring wicket_membership post ID.
     *
     * @return array{tier_post_id:int, product_id:int}|null Override shape for Filter #2, or null when no succession rule applies (core default stands).
     */
    public function resolveRenewalTier(int $oldMembershipPostId): ?array
    {
        $currentTierPostId = (int) get_post_meta($oldMembershipPostId, 'membership_tier_post_id', true);
        if ($currentTierPostId === 0) {
            return null;
        }

        // Tier NAME is the map key. The tier post title is the canonical name
        // a client writes into wicket_lockbox_tier_succession_map.
        $currentTierName = get_the_title($currentTierPostId);
        if ($currentTierName === '') {
            return null;
        }

        $entry = $this->successionMap()[$currentTierName] ?? null;
        if (!is_array($entry)) {
            // No succession rule for this tier; core's own renewal decision stands.
            return null;
        }

        $nextTierName = (string) ($entry['next_tier'] ?? '');
        $productSku = (string) ($entry['product_sku'] ?? '');
        if ($nextTierName === '' || $productSku === '') {
            $this->logger?->warning('Tier succession entry incomplete; skipping override.', ['tier' => $currentTierName]);

            return null;
        }

        $nextTierPostId = $this->tierPostIdByName($nextTierName);
        $productId = $this->productIdBySku($productSku);

        // Fail closed to null on any resolution miss: returning a partial shape
        // (valid tier, missing product) would let a bad map silently re-route
        // renewals into a tier with no purchasable product. Null hands control
        // back to core's default instead of guessing.
        if ($nextTierPostId === 0 || $productId === 0) {
            $this->logger?->warning('Tier succession resolution failed; core default stands.', [
                'tier' => $currentTierName,
                'next_tier' => $nextTierName,
                'sku' => $productSku,
                'tier_post_id' => $nextTierPostId,
                'product_id' => $productId,
            ]);

            return null;
        }

        return ['tier_post_id' => $nextTierPostId, 'product_id' => $productId];
    }

    /**
     * The client-supplied succession map: tier NAME => {next_tier, product_sku}.
     *
     * @return array<string, array{next_tier?:string, product_sku?:string}>
     */
    private function successionMap(): array
    {
        /** @var array $map */
        $map = apply_filters('wicket_lockbox_tier_succession_map', []);

        return is_array($map) ? $map : [];
    }

    /**
     * Resolve a tier NAME to its wicket_mship_tier post ID, cached per request.
     *
     * Per-request memoization (static) matches the shipped T30
     * wicket_oba_tier_post_id() precedent: a bundle renewal batch may ask for
     * the same tier many times in one request, and the get_posts lookup is the
     * hot path. Title match is exact (WP_Query `title` since WP 4.4).
     */
    private function tierPostIdByName(string $name): int
    {
        static $cache = [];
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $posts = get_posts([
            'post_type' => $this->tierCptSlug(),
            'title' => $name,
            'numberposts' => 1,
            'post_status' => 'publish',
            'no_found_rows' => true,
        ]);

        $id = !empty($posts[0]->ID) ? (int) $posts[0]->ID : 0;
        $cache[$name] = $id;

        return $id;
    }

    /**
     * Resolve a product SKU to its WC product ID (0 when not found).
     */
    private function productIdBySku(string $sku): int
    {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return 0;
        }

        return (int) wc_get_product_id_by_sku($sku);
    }

    /**
     * The membership-tier CPT slug. Reuses the memberships helper when present
     * (AD15 priority rung 1: reuse wicket-wp-memberships first), falling back
     * to its documented stable slug 'wicket_mship_tier' when the helper class
     * is not yet loaded.
     */
    private function tierCptSlug(): string
    {
        if (class_exists('\Wicket_Memberships\Helper')) {
            return (string) \Wicket_Memberships\Helper::get_membership_tier_cpt_slug();
        }

        return 'wicket_mship_tier';
    }
}
