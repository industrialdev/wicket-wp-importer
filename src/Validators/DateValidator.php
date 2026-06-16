<?php
declare(strict_types=1);

namespace WicketLockbox\Validators;

use WicketLockbox\ValueObjects\ValidationResult;

/**
 * Strict ISO 8601 calendar date: YYYY-MM-DD.
 *
 * Both the shape and the calendar validity are enforced (checkdate). Empty
 * values pass (pair with RequiredValidator).
 */
final class DateValidator implements ValidatorInterface
{
	public function validate( mixed $value, array $context ): ValidationResult
	{
		$date = trim( (string) $value );

		if ( $date === '' ) {
			return ValidationResult::valid();
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) !== 1 ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Date must be in YYYY-MM-DD format.' );
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Invalid calendar date.' );
		}

		return ValidationResult::valid();
	}
}
