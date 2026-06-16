<?php
declare(strict_types=1);

namespace WicketLockbox\Validators;

use WicketLockbox\ValueObjects\ValidationResult;

/**
 * RFC 5321-aligned email check.
 *
 * Rules: max 254 octets total, FILTER_VALIDATE_EMAIL as the baseline, and no
 * leading/trailing dot on the local part (the only boundary forbidden by
 * RFC 5321). Empty values pass (pair with RequiredValidator to make an email
 * mandatory).
 */
final class EmailValidator implements ValidatorInterface
{
	private const MAX_LENGTH = 254;

	public function validate( mixed $value, array $context ): ValidationResult
	{
		$email = trim( (string) $value );

		// Empty is not this validator's concern.
		if ( $email === '' ) {
			return ValidationResult::valid();
		}

		if ( strlen( $email ) > self::MAX_LENGTH ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Email must be 254 characters or fewer.' );
		}

		if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Invalid email format.' );
		}

		// FILTER_VALIDATE_EMAIL guarantees exactly one '@', so strrpos is safe.
		$local = substr( $email, 0, (int) strrpos( $email, '@' ) );

		// RFC 5321 forbids a dot at local-part boundaries. Other atext
		// characters (_ + - ~ etc.) are legal there, so do not over-reject.
		if ( preg_match( '/^[.]|[.]$/u', $local ) ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Email local part must not start or end with a dot.' );
		}

		return ValidationResult::valid();
	}
}
