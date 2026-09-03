<?php

declare(strict_types=1);

namespace WicketImporter\Validators;

use WicketImporter\ValueObjects\ValidationResult;

/**
 * Money/quantity number: optional sign, digits, optional 2-decimal fraction.
 *
 * Currency artifacts ($, thousands commas, whitespace) are stripped before
 * the strict check, so "150.00", "$1,150" and " 350 " pass while prose
 * ("ABABABA") fails. Empty values pass (pair with RequiredValidator).
 *
 * Options: 'min'/'max' (numeric strings compared as floats) bound the value.
 */
final class NumericValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $context): ValidationResult
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return ValidationResult::valid();
        }

        // Accounting-style negatives: (12.50) means -12.50 (reversals).
        $negative = preg_match('/^\(.*\)$/', $raw) === 1;
        if ($negative) {
            $raw = trim((string) substr($raw, 1, -1));
        }

        // Strip currency artifacts ($, thousands commas, regular + non-breaking
        // spaces — the latter copy-pasted from spreadsheets).
        $stripped = preg_replace('/[$,\x{00A0}\s]/u', '', $raw);

        if ($stripped === null || preg_match('/^-?\d+(\.\d{1,2})?$/', $stripped) !== 1) {
            return new ValidationResult(ValidationResult::STATUS_INVALID, 'Value must be a numeric amount (e.g. 150.00).');
        }

        $number = (float) $stripped;
        $min = $context['options']['min'] ?? null;
        $max = $context['options']['max'] ?? null;

        if ($negative) {
            $number = -abs($number);
        }

        if ($min !== null && is_numeric($min) && $number < (float) $min) {
            return new ValidationResult(ValidationResult::STATUS_INVALID, sprintf('Value must be at least %s.', $min));
        }

        if ($max !== null && is_numeric($max) && $number > (float) $max) {
            return new ValidationResult(ValidationResult::STATUS_INVALID, sprintf('Value must be at most %s.', $max));
        }

        return ValidationResult::valid();
    }
}
