<?php
declare(strict_types=1);

namespace WicketImporter\Validators;

use WicketImporter\ValueObjects\ValidationResult;

/**
 * Two-letter US state abbreviation (50 states + DC). Case-insensitive.
 *
 * Empty values pass (pair with RequiredValidator).
 */
final class UsStateValidator implements ValidatorInterface
{
	/**
	 * 50 states + District of Columbia.
	 */
	private const STATES = [
		'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
		'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
		'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
		'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
		'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
		'DC',
	];

	public function validate( mixed $value, array $context ): ValidationResult
	{
		$state = trim( (string) $value );

		if ( $state === '' ) {
			return ValidationResult::valid();
		}

		if ( ! in_array( strtoupper( $state ), self::STATES, true ) ) {
			return new ValidationResult( ValidationResult::STATUS_INVALID, 'Unrecognized US state abbreviation.' );
		}

		return ValidationResult::valid();
	}
}
