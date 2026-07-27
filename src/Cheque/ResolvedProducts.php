<?php

declare(strict_types=1);

namespace WicketCheque;

/**
 * The set of WC products a single cheque-renewal row resolves to, plus the
 * divergence verdict that gates whether the row proceeds to order creation.
 *
 * Carries the membership renewal product, the section products, and the
 * late-fee products (from the MappingResolver match), the expected total
 * (sum of their prices), and whether that total diverges from the CSV's
 * order_total past the configured tolerance.
 */
final class ResolvedProducts
{
    /**
     * @param int       $membershipProductId The renewal product for the member's tier.
     * @param list<int> $sectionProductIds   Section products the member belongs to.
     * @param list<int> $lateFeeProductIds   Late-fee products applicable to the member's role.
     * @param float     $expectedTotal       Sum of all resolved product prices.
     * @param bool      $divergent           True when expected vs CSV total exceeds tolerance.
     * @param string|null $error             Non-null when resolution failed (missing/non-subscription product); divergent is forced true.
     */
    public function __construct(
        public readonly int $membershipProductId,
        public readonly array $sectionProductIds,
        public readonly array $lateFeeProductIds,
        public readonly float $expectedTotal,
        public readonly bool $divergent,
        public readonly ?string $error = null,
    ) {}

    /**
     * Failure shape: a resolution error (bad/missing product) gates the row
     * out of order creation. All product lists are empty, total is 0.0.
     */
    public static function error(string $error): self
    {
        return new self(
            membershipProductId: 0,
            sectionProductIds: [],
            lateFeeProductIds: [],
            expectedTotal: 0.0,
            divergent: true,
            error: $error,
        );
    }
}
