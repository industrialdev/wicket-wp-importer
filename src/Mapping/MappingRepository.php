<?php

declare(strict_types=1);

namespace WicketImporter\Mapping;

use WicketImporter\BulkImport\Database\DbInstaller;

/**
 * Read access to the HyperFields mappings option.
 *
 * Writes happen directly through the HyperFields "Import Mappings" option page
 * (MappingSettings), so this repository is read-only: it resolves the stored
 * option into MappingEntry value objects. The CRUD methods that used to live
 * here (save/delete/toggle/snapshot) had zero callers — HyperFields owns the
 * writes — and carried a read-modify-write race, so they were removed.
 */
class MappingRepository
{
    /**
     * Retrieve all mappings optionally filtered by type.
     *
     * @return list<MappingEntry>
     */
    public function getAll(?string $type = null): array
    {
        $options = get_option(DbInstaller::MAPPINGS_OPTION, []);
        if (!is_array($options)) {
            return [];
        }

        $entries = [];

        if ((null === $type || 'late_fee' === $type) && isset($options['late_fees']) && is_array($options['late_fees'])) {
            foreach ($options['late_fees'] as $row) {
                $entries[] = MappingEntry::fromArray($row, 'late_fee');
            }
        }

        if ((null === $type || 'discount' === $type) && isset($options['discounts']) && is_array($options['discounts'])) {
            foreach ($options['discounts'] as $row) {
                $entries[] = MappingEntry::fromArray($row, 'discount');
            }
        }

        if ((null === $type || 'section' === $type) && isset($options['sections']) && is_array($options['sections'])) {
            foreach ($options['sections'] as $row) {
                $entries[] = MappingEntry::fromArray($row, 'section');
            }
        }

        return $entries;
    }

    /**
     * Get only active mappings, optionally filtered by type.
     *
     * @return list<MappingEntry>
     */
    public function getActiveMappings(?string $type = null): array
    {
        return array_values(
            array_filter(
                $this->getAll($type),
                fn (MappingEntry $entry) => $entry->isActive
            )
        );
    }
}
