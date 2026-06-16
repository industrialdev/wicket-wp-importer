<?php
declare(strict_types=1);

namespace WicketLockbox\ValueObjects;

/**
 * Outcome of validating a single row (or a single field within a row). Immutable.
 *
 * A row-level result aggregates every flagged field for that row. The status is
 * the worst status among all field-level checks; duplicate status is assigned at
 * the batch level by ValidationService (in-file dedup).
 */
final class ValidationResult
{
	public const STATUS_VALID = 'valid';
	public const STATUS_INVALID = 'invalid';
	public const STATUS_DUPLICATE = 'duplicate';
	public const STATUS_WARNING = 'warning';

	/**
	 * @param string        $status       One of the STATUS_* constants.
	 * @param string|null   $message      Human-readable reason for non-valid results.
	 * @param list<string>  $flaggedFields Column keys that failed validation.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $message = null,
		public readonly array $flaggedFields = [],
	) {
	}

	/**
	 * Convenience factory for a clean result.
	 */
	public static function valid(): self
	{
		return new self( self::STATUS_VALID );
	}

	/**
	 * Whether the row/field passed validation.
	 */
	public function isValid(): bool
	{
		return self::STATUS_VALID === $this->status;
	}

	/**
	 * Whether the row needs attention (invalid, duplicate, or warning).
	 */
	public function isFlagged(): bool
	{
		return ! $this->isValid();
	}
}
