<?php
declare(strict_types=1);

namespace WicketImporter\Validators;

use WicketImporter\ValueObjects\ValidationResult;

/**
 * Contract for a single, reusable field validator.
 *
 * Validators are field-agnostic: they receive a raw value plus context and
 * return whether that value is acceptable. They do NOT know the column key;
 * ValidationService attributes flagged fields to columns. Format validators
 * treat an empty value as valid (required-ness is owned by RequiredValidator).
 *
 * Context keys:
 *  - options:  array  Spec options declared on the column (everything except 'type').
 *  - column:   ColumnDefinition|null  The column being validated.
 *  - row:      CsvRow|null            The full row, for cross-field validators.
 */
interface ValidatorInterface
{
	/**
	 * @param mixed               $value   The raw cell value (string|null from CSV).
	 * @param array<string, mixed> $context See class docblock.
	 */
	public function validate( mixed $value, array $context ): ValidationResult;
}
