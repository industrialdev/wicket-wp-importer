<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

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
    /**
     * Per-instance memo of mappingsForRole() (P2): the HyperFields option is
     * stable for a request, so the active-filter runs once per role. Instance-
     * level (not static) so test cases that restub the option get a fresh cache.
     *
     * @var array<string, array{late_fees: list<MappingEntry>, discounts: list<MappingEntry>}>
     */
    private array $mappingsForRoleCache = [];

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
        int $membershipPostId,
        object $renewalOrder,
    ): void {
        // Filter #3 fires once PER line item, but late-fee + discount tallies are
        // order-level mutations. Without this guard a renewal order with N line
        // items would receive every fee/discount N times (silent overcharge).
        // Run the whole-order tally exactly once, then stamp the order.
        if ($this->mappingsAlreadyApplied($renewalOrder)) {
            return;
        }

        $roles = self::memberRoles($membershipPostId);
        if ($roles === []) {
            return;
        }

        $matched = $this->mappingsForRoles($roles);
        if ($matched['late_fees'] === [] && $matched['discounts'] === []) {
            return;
        }

        $this->applyMappings($matched, $renewalOrder);
        $this->markMappingsApplied($renewalOrder);
    }

    /**
     * Has this renewal order already had its lockbox fee/discount tally applied?
     * Reads the order meta flag set by markMappingsApplied().
     */
    private function mappingsAlreadyApplied(object $renewalOrder): bool
    {
        return method_exists($renewalOrder, 'get_meta')
            && $renewalOrder->get_meta('_wicket_lockbox_mappings_applied') === 'yes';
    }

    /**
     * Stamp the order so subsequent Filter #3 callbacks (one per remaining line
     * item) short-circuit instead of re-applying the whole-order tally.
     */
    private function markMappingsApplied(object $renewalOrder): void
    {
        if (!method_exists($renewalOrder, 'update_meta_data')) {
            return;
        }
        $renewalOrder->update_meta_data('_wicket_lockbox_mappings_applied', 'yes');
        if (method_exists($renewalOrder, 'save')) {
            $renewalOrder->save();
        }
    }

    /**
     * Load active late-fee + discount mappings that match a role.
     *
     * @return array{late_fees: list<MappingEntry>, discounts: list<MappingEntry>}
     */
    public function mappingsForRole(string $role): array
    {
        return $this->mappingsForRoles([$role]);
    }

    /**
     * Load active late-fee + discount mappings that match ANY of the member's
     * roles (spec Story 6: WP user roles synced from MDP; a member may hold
     * several, and each matching mapping applies — overlapping late-fee roles
     * each add their fee line).
     *
     * @param list<string> $roles
     *
     * @return array{late_fees: list<MappingEntry>, discounts: list<MappingEntry>}
     */
    public function mappingsForRoles(array $roles): array
    {
        $key = implode('|', $roles);
        if (isset($this->mappingsForRoleCache[$key])) {
            return $this->mappingsForRoleCache[$key];
        }

        $want = array_fill_keys(array_values(array_filter($roles, 'is_string')), true);
        if ($want === []) {
            return $this->mappingsForRoleCache[$key] = ['late_fees' => [], 'discounts' => []];
        }

        $repo = $this->mappingRepository();
        $lateFees = array_values(array_filter(
            $repo->getActiveMappings('late_fee'),
            static fn (MappingEntry $m): bool => isset($want[$m->roleSlug])
        ));
        $discounts = array_values(array_filter(
            $repo->getActiveMappings('discount'),
            static fn (MappingEntry $m): bool => isset($want[$m->roleSlug])
        ));

        return $this->mappingsForRoleCache[$key] = ['late_fees' => $lateFees, 'discounts' => $discounts];
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
                $product = $this->product($fee->resolveProductId());
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
                $product = $this->product($discount->resolveProductId());
                if ($product !== null) {
                    $renewalOrder->add_product($product, 1);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Discount application threw; continuing.', ['role' => $discount->roleSlug, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The member's mapping roles: the WP USER role slugs of the user linked to
     * the wicket_membership post (user_id meta). Spec Stories 1/6: roles are
     * synced from the MDP per member and key the late-fee/discount mappings.
     * Shared by MappingResolver + ProductResolver (S5) so role resolution
     * lives in one place and cannot drift between the two.
     *
     * @return list<string>
     */
    public static function memberRoles(int $membershipPostId): array
    {
        $userId = (int) get_post_meta($membershipPostId, 'user_id', true);
        if ($userId === 0) {
            return [];
        }

        $user = get_userdata($userId);
        if ($user === false) {
            return [];
        }

        // Steady-state WP roles carry no mapping signal (nothing maps them);
        // keep them anyway — the mapping equality filter decides, not this.
        // ASSUMPTION: mapping role slugs are namespaced (e.g. late-fee-1) and
        // cannot collide with stock WP roles (administrator, subscriber, etc.).
        // If a client ever picks a colliding slug this would silently misfire.
        return array_values(array_filter(
            array_map(static fn ($r): string => (string) $r, $user->roles),
            static fn (string $r): bool => $r !== ''
        ));
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
