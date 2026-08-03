<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\Mapping\MappingRepository;
use WicketImporter\Services\Logger;

/**
 * Maps a member's section slugs to WC product IDs via the section mapping
 * snapshot (MappingRepository sections bucket).
 *
 * DESIGN NOTE (section source): this resolver is a pure mapper. It does NOT
 * source the member's section slugs; the caller (the cheque-flow
 * ProductResolver / OrderCreator) supplies them, however the cheque import
 * surfaces them (CSV column, MDP group membership, membership meta). Keeping
 * sourcing out of the resolver avoids coupling it to an unsettled data source
 * and makes it trivially testable. When the section source is finalized, feed
 * it in here; do not push the fetch into this class.
 *
 * Match policy: a slug with no active section mapping is skipped with a
 * warning, never a hard failure (per the Phase 6 spec: "no match = skip +
 * warn, do not block").
 */
final class SectionResolver
{
    public function __construct(
        private readonly ?Logger $logger = null,
        private readonly ?MappingRepository $mappings = null,
    ) {}

    /**
     * Resolve section slugs to their mapped WC product IDs.
     *
     * @param list<string> $sectionSlugs Section/group slugs the member belongs to.
     *
     * @return list<int> Distinct WC product IDs for slugs with an active section mapping, in input order.
     */
    public function resolveProducts(array $sectionSlugs): array
    {
        if ($sectionSlugs === []) {
            return [];
        }

        $bySlug = [];
        foreach ($this->mappingRepository()->getActiveMappings('section') as $entry) {
            $bySlug[$entry->roleSlug] = $entry;
        }

        $productIds = [];
        $seen = [];
        foreach ($sectionSlugs as $slug) {
            $slug = (string) $slug;
            if ($slug === '') {
                continue;
            }
            $entry = $bySlug[$slug] ?? null;
            if ($entry === null) {
                $this->logger?->warning('No section mapping for slug; skipping.', ['slug' => $slug]);
                continue;
            }
            $productId = $entry->productId;
            if ($productId === null || $productId === 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $productIds[] = $productId;
        }

        return $productIds;
    }

    private function mappingRepository(): MappingRepository
    {
        return $this->mappings ?? new MappingRepository();
    }
}
