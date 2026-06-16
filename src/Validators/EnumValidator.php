<?php
declare(strict_types=1);

namespace WicketLockbox\Validators;

use WicketLockbox\ValueObjects\ValidationResult;

/**
 * Value must be one of an allowed set.
 *
 * Spec example: ['type' => 'enum', 'values' => ['M', 'F'], 'case_sensitive' => false]
 *
 * Comparison is case-insensitive by default. Empty values pass (pair with
 * RequiredValidator).
 */
final class EnumValidator implements ValidatorInterface
{
	public function validate( mixed $value, array $context ): ValidationResult
	{
		$raw = trim( (string) $value );

		if ( $raw === '' ) {
			return ValidationResult::valid();
		}

		$values = $context['options']['values'] ?? [];
		$values = is_array( $values ) ? array_map( 'strval', $values ) : [];

		if ( $values === [] ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'No allowed values are configured for this field.' );
		}

		$caseSensitive = (bool) ( $context['options']['case_sensitive'] ?? false );

		if ( $caseSensitive ) {
			$match = in_array( $raw, $values, true );
		} else {
			$lower = strtolower( $raw );
			$set = array_map( 'strtolower', $values );
			$match = in_array( $lower, $set, true );
		}

		if ( ! $match ) {
			$preview = implode( ', ', array_slice( $values, 0, 5 ) );
			$suffix = count( $values ) > 5 ? sprintf( ', ... (%d total)', count( $values ) ) : '';
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Value must be one of: ' . $preview . $suffix . '.' );
		}

		return ValidationResult::valid();
	}
}
