<?php

declare(strict_types=1);

namespace WicketImporter\Validators;

use WicketImporter\ValueObjects\ValidationResult;

/**
 * Fails when the value is absent or whitespace-only.
 *
 * Owns emptiness: this is the only built-in validator that rejects empty values.
 * "0" is a valid (present) value. All format validators skip empty values, so a
 * column composes required + format by declaring both specs.
 */
final class RequiredValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $context): ValidationResult
    {
        if ($value === null) {
            return new ValidationResult(ValidationResult::STATUS_INVALID, 'This field is required.');
        }

        if (is_string($value) && trim($value) === '') {
            return new ValidationResult(ValidationResult::STATUS_INVALID, 'This field is required.');
        }

        return ValidationResult::valid();
    }
}
