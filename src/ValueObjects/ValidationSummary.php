<?php
declare(strict_types=1);

namespace WicketLockbox\ValueObjects;

/**
 * Aggregate outcome of validating a batch of rows. Immutable.
 *
 * `$results` holds every row's result keyed by row index; `$flagged` and
 * `$duplicates` are derived subsets so the caller can render the summary bar
 * ("N valid - M flagged - K duplicates") and insert each row into the staging
 * table with the correct status.
 */
final class ValidationSummary
{
	/**
	 * @param int                                $total      Total rows validated.
	 * @param int                                $validCount Rows that passed (valid only).
	 * @param array<int, ValidationResult>       $flagged    rowIndex => result, status invalid|warning (never duplicate: detectDuplicates only promotes initially-valid rows).
	 * @param array<int, ValidationResult>       $duplicates rowIndex => result, status duplicate.
	 * @param array<int, ValidationResult>       $results    rowIndex => result, all rows. AUTHORITATIVE per-row state — Task 6 staging inserts must read status/message/flaggedFields from here, not from the $flagged/$duplicates buckets (which are derived views for summary counts).
	 */
	public function __construct(
		public readonly int $total,
		public readonly int $validCount,
		public readonly array $flagged = [],
		public readonly array $duplicates = [],
		public readonly array $results = [],
	) {
	}

	/**
	 * Look up a single row's result by its 0-based index.
	 */
	public function resultFor( int $rowIndex ): ?ValidationResult
	{
		return $this->results[ $rowIndex ] ?? null;
	}

	/**
	 * Whether any row was flagged or marked duplicate.
	 */
	public function hasIssues(): bool
	{
		return $this->flagged !== [] || $this->duplicates !== [];
	}
}
