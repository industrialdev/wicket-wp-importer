<?php
declare(strict_types=1);

namespace WicketLockbox\ValueObjects;

/**
 * Outcome of parsing a CSV file into typed rows. Immutable.
 */
final class ParseResult
{
	/**
	 * @param list<CsvRow> $rows           Successfully parsed rows.
	 * @param list<string> $missingHeaders Required column keys the CSV did not provide.
	 */
	public function __construct(
		public readonly array $rows,
		public readonly array $missingHeaders,
		public readonly int $totalCount,
		public readonly ?string $error = null,
	) {
	}

	public function hasError(): bool
	{
		return $this->error !== null;
	}

	public function hasMissingHeaders(): bool
	{
		return $this->missingHeaders !== [];
	}
}
