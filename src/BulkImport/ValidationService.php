<?php
declare(strict_types=1);

namespace WicketLockbox\BulkImport;

use WicketLockbox\Services\Logger;
use WicketLockbox\ValueObjects\ColumnDefinition;
use WicketLockbox\ValueObjects\CsvRow;
use WicketLockbox\ValueObjects\ValidationResult;
use WicketLockbox\ValueObjects\ValidationSummary;
use WicketLockbox\Validators\DateValidator;
use WicketLockbox\Validators\EmailValidator;
use WicketLockbox\Validators\EnumValidator;
use WicketLockbox\Validators\PhoneValidator;
use WicketLockbox\Validators\RequiredValidator;
use WicketLockbox\Validators\UsStateValidator;
use WicketLockbox\Validators\ValidatorInterface;
use WicketLockbox\Validators\ZipValidator;

/**
 * Validates parsed CSV rows against column definitions.
 *
 * Column validators are declared as specs (list<mixed>) on each ColumnDefinition;
 * each spec is `['type' => <name>, ...options]`. Specs are resolved to
 * ValidatorInterface instances via a registry that fires the
 * `wicket_import_validators` filter so extensions can add or replace validators.
 *
 * Two layers:
 *  - validateRow(): field-level checks (valid/invalid/warning).
 *  - validateBatch(): runs validateRow() for every row, then performs in-file
 *    duplicate detection across rows whose `dedup` columns share a composite key.
 */
final class ValidationService
{
	/**
	 * @var array<string, ValidatorInterface>|null Lazy-built validator registry, cached for the request.
	 */
	private ?array $registry = null;

	public function __construct(
		private ?Logger $logger = null,
	) {
	}

	/**
	 * Validate a single row's fields.
	 *
	 * @param CsvRow                   $row
	 * @param list<ColumnDefinition>   $columnDefinitions
	 */
	public function validateRow( CsvRow $row, array $columnDefinitions ): ValidationResult
	{
		$registry = $this->validatorRegistry();
		$flagged = [];
		$messages = [];
		$worst = ValidationResult::STATUS_VALID;

		foreach ( $columnDefinitions as $column ) {
			$value = $row->get( $column->key );

			foreach ( $column->validators as $spec ) {
				$result = $this->runSpec( $spec, $value, $column, $row, $registry );

				if ( $result->isValid() ) {
					continue;
				}

				// Keyed set avoids duplicate keys when multiple specs fail one column.
				$flagged[ $column->key ] = true;
				// Keep the first message per field; built-in validators are mutually
				// exclusive (required fires only on empty, formats skip empty).
				$messages[ $column->key ] ??= $result->message;
				$worst = $this->worstStatus( $worst, $result->status );
			}
		}

		if ( $flagged === [] ) {
			return ValidationResult::valid();
		}

		return new ValidationResult(
			status: $worst,
			message: implode( ' ', array_unique( array_values( $messages ) ) ),
			flaggedFields: array_keys( $flagged ),
		);
	}

	/**
	 * Validate every row, then run in-file duplicate detection.
	 *
	 * @param list<CsvRow>             $rows
	 * @param list<ColumnDefinition>   $columnDefinitions
	 */
	public function validateBatch( array $rows, array $columnDefinitions ): ValidationSummary
	{
		$results = [];
		$flagged = [];

		foreach ( $rows as $row ) {
			$result = $this->validateRow( $row, $columnDefinitions );
			$results[ $row->rowIndex ] = $result;

			if ( $result->isFlagged() ) {
				$flagged[ $row->rowIndex ] = $result;
			}
		}

		$duplicates = $this->detectDuplicates( $rows, $columnDefinitions, $results );

		// Invariant: flagged ∩ duplicates = ∅. detectDuplicates only promotes
		// initially-valid rows, so a row can never be in both buckets.
		$validCount = count( $rows ) - count( $flagged ) - count( $duplicates );

		return new ValidationSummary(
			total: count( $rows ),
			validCount: $validCount,
			flagged: $flagged,
			duplicates: $duplicates,
			results: $results,
		);
	}

	/**
	 * In-file duplicate detection: the first row sharing a composite key wins,
	 * later rows with the same key are flagged duplicate. Rows that already
	 * failed field validation are skipped (field errors take precedence).
	 *
	 * @param list<CsvRow>             $rows
	 * @param list<ColumnDefinition>   $columnDefinitions
	 * @param array<int, ValidationResult> $results Modified in place when a row is marked duplicate.
	 * @return array<int, ValidationResult> rowIndex => duplicate result
	 */
	private function detectDuplicates( array $rows, array $columnDefinitions, array &$results ): array
	{
		$dedupColumns = array_values( array_filter( $columnDefinitions, fn ( ColumnDefinition $c ): bool => $c->dedup ) );

		if ( $dedupColumns === [] ) {
			return [];
		}

		$seen = [];
		$duplicates = [];

		foreach ( $rows as $row ) {
			$existing = $results[ $row->rowIndex ] ?? null;

			// Only clean rows can become duplicates; field-invalid rows keep their status.
			if ( $existing === null || $existing->isFlagged() ) {
				continue;
			}

			$key = $this->dedupKey( $row, $dedupColumns );

			// An all-empty key cannot be meaningfully deduped.
			if ( $key === '' ) {
				continue;
			}

			if ( array_key_exists( $key, $seen ) ) {
				$duplicate = new ValidationResult(
					status: ValidationResult::STATUS_DUPLICATE,
					message: 'Duplicate of an earlier row in this file.',
					flaggedFields: array_map( fn ( ColumnDefinition $c ): string => $c->key, $dedupColumns ),
				);
				$results[ $row->rowIndex ] = $duplicate;
				$duplicates[ $row->rowIndex ] = $duplicate;
			} else {
				$seen[ $key ] = $row->rowIndex;
			}
		}

		return $duplicates;
	}

	/**
	 * Case-insensitive composite key from the row's dedup columns, unit-separated.
	 *
	 * @param list<ColumnDefinition> $dedupColumns
	 */
	private function dedupKey( CsvRow $row, array $dedupColumns ): string
	{
		$parts = [];
		foreach ( $dedupColumns as $column ) {
			$value = $row->get( $column->key );
			if ( $value === null ) {
				$parts[] = '';
				continue;
			}
			// Strip control chars so embedded separators can't forge key collisions.
			$cleaned = preg_replace( '/[\x00-\x1f\x7f]/', '', (string) $value );
			$parts[] = mb_strtolower( trim( $cleaned ?? '' ), 'UTF-8' );
		}
		return implode( "\x1f", $parts );
	}

	/**
	 * Resolve a spec to a validator and run it. Unknown types fail closed
	 * (treated as invalid) so a misconfigured spec surfaces loudly at validation
	 * time instead of letting values pass through unvalidated.
	 *
	 * @param array<string, mixed>      $spec
	 * @param array<string, ValidatorInterface> $registry
	 */
	private function runSpec( array $spec, mixed $value, ColumnDefinition $column, CsvRow $row, array $registry ): ValidationResult
	{
		$type = $spec['type'] ?? null;

		if ( ! is_string( $type ) || ! isset( $registry[ $type ] ) ) {
			$this->log( 'warning', sprintf( 'Unknown validator type "%s" on column "%s"; treating as invalid.', (string) $type, $column->key ) );
			return new ValidationResult( ValidationResult::STATUS_INVALID, sprintf( 'Unknown validator type "%s".', (string) $type ) );
		}

		$options = $spec;
		unset( $options['type'] );

		$result = $registry[ $type ]->validate( $value, [
			'options' => $options,
			'column' => $column,
			'row' => $row,
		] );

		// STATUS_DUPLICATE is a batch-level assignment (detectDuplicates). A
		// field-level validator must not return it; rewrite to invalid so the
		// bucket invariant (flagged = invalid|warning, duplicates = duplicate) holds.
		if ( ValidationResult::STATUS_DUPLICATE === $result->status ) {
			$result = new ValidationResult( ValidationResult::STATUS_INVALID, $result->message, $result->flaggedFields );
		}

		return $result;
	}

	/**
	 * Built-in validators, overridable/extendable via the wicket_import_validators
	 * filter. Memoized: the filter fires once per request, not once per row.
	 *
	 * @return array<string, ValidatorInterface> name => validator instance
	 */
	private function validatorRegistry(): array
	{
		if ( $this->registry !== null ) {
			return $this->registry;
		}

		$defaults = [
			'required' => new RequiredValidator(),
			'email' => new EmailValidator(),
			'phone' => new PhoneValidator(),
			'date' => new DateValidator(),
			'zip' => new ZipValidator( $this->logger ),
			'us_state' => new UsStateValidator(),
			'enum' => new EnumValidator(),
		];

		/** @var array<string, ValidatorInterface> $validators */
		$validators = apply_filters( 'wicket_import_validators', $defaults, [] );

		$this->registry = $validators;

		return $validators;
	}

	/**
	 * Pick the worse of two statuses. invalid > warning > valid. duplicate is
	 * only ever assigned at the batch level (runSpec rewrites any field-level
	 * duplicate to invalid), so it is intentionally NOT ranked here.
	 */
	private function worstStatus( string $a, string $b ): string
	{
		$rank = [
			ValidationResult::STATUS_VALID => 0,
			ValidationResult::STATUS_WARNING => 1,
			ValidationResult::STATUS_INVALID => 2,
		];

		$ra = $rank[ $a ] ?? 0;
		$rb = $rank[ $b ] ?? 0;

		return $ra >= $rb ? $a : $b;
	}

	private function log( string $level, string $message ): void
	{
		$this->logger?->{$level}( $message );
	}
}
