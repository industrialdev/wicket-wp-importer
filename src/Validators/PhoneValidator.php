<?php
declare(strict_types=1);

namespace WicketLockbox\Validators;

use WicketLockbox\ValueObjects\ValidationResult;

/**
 * Phone number check: 7 to 15 digits after stripping non-digits.
 *
 * 15 is the E.164 ceiling. Empty values pass (pair with RequiredValidator).
 */
final class PhoneValidator implements ValidatorInterface
{
	private const MIN_DIGITS = 7;
	private const MAX_DIGITS = 15;

	public function validate( mixed $value, array $context ): ValidationResult
	{
		$raw = trim( (string) $value );

		if ( $raw === '' ) {
			return ValidationResult::valid();
		}

		$digits = preg_replace( '/[^0-9]/', '', $raw );
		$count = $digits === null ? 0 : strlen( $digits );

		if ( $count < self::MIN_DIGITS || $count > self::MAX_DIGITS ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, sprintf( 'Phone must contain %d to %d digits.', self::MIN_DIGITS, self::MAX_DIGITS ) );
		}

		return ValidationResult::valid();
	}
}
