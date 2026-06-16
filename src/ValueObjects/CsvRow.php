<?php
declare(strict_types=1);

namespace WicketImporter\ValueObjects;

/**
 * A single parsed CSV row, mapped to canonical column keys. Immutable.
 */
final class CsvRow
{
	/**
	 * @param int                        $rowIndex 0-based position in the source file.
	 * @param array<string, string|null> $data     Values keyed by canonical column key.
	 * @param list<string|null>          $rawData  Original cell values in CSV column order.
	 */
	public function __construct(
		public readonly int $rowIndex,
		public readonly array $data,
		public readonly array $rawData,
	) {
	}

	/**
	 * Get a column value by canonical key, with a default for missing keys.
	 */
	public function get( string $key, mixed $default = null ): mixed
	{
		return $this->data[ $key ] ?? $default;
	}
}
