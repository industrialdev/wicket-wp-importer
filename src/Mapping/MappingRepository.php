<?php
declare(strict_types=1);

namespace WicketLockbox\Mapping;

use WicketLockbox\BulkImport\Database\DbInstaller;

class MappingRepository
{
	/**
	 * Retrieve all mappings optionally filtered by type.
	 *
	 * @return MappingEntry[]
	 */
	public function getAll( ?string $type = null ): array
	{
		$options = get_option( DbInstaller::MAPPINGS_OPTION, [] );
		if ( ! is_array( $options ) ) {
			return [];
		}

		$entries = [];

		// Handle late fees
		if ( ( null === $type || 'late_fee' === $type ) && isset( $options['late_fees'] ) && is_array( $options['late_fees'] ) ) {
			foreach ( $options['late_fees'] as $row ) {
				$entries[] = MappingEntry::fromArray( $row, 'late_fee' );
			}
		}

		// Handle discounts
		if ( ( null === $type || 'discount' === $type ) && isset( $options['discounts'] ) && is_array( $options['discounts'] ) ) {
			foreach ( $options['discounts'] as $row ) {
				$entries[] = MappingEntry::fromArray( $row, 'discount' );
			}
		}

		// Handle sections
		if ( ( null === $type || 'section' === $type ) && isset( $options['sections'] ) && is_array( $options['sections'] ) ) {
			foreach ( $options['sections'] as $row ) {
				$entries[] = MappingEntry::fromArray( $row, 'section' );
			}
		}

		return $entries;
	}

	/**
	 * Find a mapping entry by its stable role slug.
	 */
	public function getByRoleSlug( string $slug ): ?MappingEntry
	{
		$all = $this->getAll();
		foreach ( $all as $entry ) {
			if ( $entry->roleSlug === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Get only active mappings, optionally filtered by type.
	 *
	 * @return MappingEntry[]
	 */
	public function getActiveMappings( ?string $type = null ): array
	{
		return array_values(
			array_filter(
				$this->getAll( $type ),
				fn( MappingEntry $entry ) => $entry->isActive
			)
		);
	}

	/**
	 * Find multiple mappings by their role slugs.
	 *
	 * @return MappingEntry[]
	 */
	public function getByRoleSlugs( array $slugs ): array
	{
		if ( empty( $slugs ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$this->getAll(),
				fn( MappingEntry $entry ) => in_array( $entry->roleSlug, $slugs, true )
			)
		);
	}

	/**
	 * Save a single mapping entry.
	 */
	public function saveMapping( MappingEntry $entry ): void
	{
		$options = get_option( DbInstaller::MAPPINGS_OPTION, [] );
		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$key = $this->getOptionKey( $entry->mappingType );
		if ( ! isset( $options[ $key ] ) || ! is_array( $options[ $key ] ) ) {
			$options[ $key ] = [];
		}

		$found_index = -1;
		foreach ( $options[ $key ] as $index => $row ) {
			if ( isset( $row['role_slug'] ) && $row['role_slug'] === $entry->roleSlug ) {
				$found_index = $index;
				break;
			}
		}

		if ( $found_index >= 0 ) {
			$options[ $key ][ $found_index ] = $entry->toArray();
		} else {
			$options[ $key ][] = $entry->toArray();
		}

		update_option( DbInstaller::MAPPINGS_OPTION, $options );
	}

	/**
	 * Delete mapping entry by role slug.
	 */
	public function deleteMapping( string $roleSlug ): void
	{
		$entry = $this->getByRoleSlug( $roleSlug );
		if ( ! $entry ) {
			return;
		}

		$options = get_option( DbInstaller::MAPPINGS_OPTION, [] );
		$key     = $this->getOptionKey( $entry->mappingType );

		if ( isset( $options[ $key ] ) && is_array( $options[ $key ] ) ) {
			$options[ $key ] = array_values(
				array_filter(
					$options[ $key ],
					fn( array $row ) => ( $row['role_slug'] ?? '' ) !== $roleSlug
				)
			);
			update_option( DbInstaller::MAPPINGS_OPTION, $options );
		}
	}

	/**
	 * Toggle active status of a mapping entry.
	 */
	public function toggleActive( string $roleSlug ): void
	{
		$entry = $this->getByRoleSlug( $roleSlug );
		if ( $entry ) {
			$entry->isActive = ! $entry->isActive;
			$this->saveMapping( $entry );
		}
	}

	/**
	 * Explicitly seed default late fee mappings.
	 */
	public function seedDefaults(): void
	{
		DbInstaller::seedDefaultMappings();
	}

	/**
	 * Get an immutable snapshot of all active mappings for batch registration.
	 */
	public function getMappingsSnapshot(): array
	{
		$active = $this->getActiveMappings();
		return array_map( fn( MappingEntry $entry ) => $entry->toArray(), $active );
	}

	/**
	 * Translate mapping types to options array keys.
	 */
	private function getOptionKey( string $mappingType ): string
	{
		return match ( $mappingType ) {
			'late_fee' => 'late_fees',
			'discount' => 'discounts',
			'section'  => 'sections',
			default    => throw new \InvalidArgumentException( "Unknown mapping type: {$mappingType}" ),
		};
	}
}
