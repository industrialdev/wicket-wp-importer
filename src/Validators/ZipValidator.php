<?php

declare(strict_types=1);

namespace WicketImporter\Validators;

use WicketImporter\Services\Logger;
use WicketImporter\ValueObjects\ValidationResult;

/**
 * Postal code check, configurable per locale via the spec 'locale' option.
 *
 * Supported locales: 'US' (5 or 9 digit ZIP+4, default) and 'CA'
 * (A1A 1A1, optional single space). Unknown locale falls back to US. Empty
 * values pass (pair with RequiredValidator).
 *
 * Spec example: ['type' => 'zip', 'locale' => 'CA']
 */
final class ZipValidator implements ValidatorInterface
{
    private const LOCALE_US = 'US';
    private const LOCALE_CA = 'CA';

    private const PATTERN_US = '/^\d{5}(-\d{4})?$/';
    private const PATTERN_CA = '/^[A-Z]\d[A-Z] ?\d[A-Z]\d$/i';

    /** @var array<string,bool> Unknown locales already warned about this request. */
    private array $warnedLocales = [];

    /**
     * @param Logger|null $logger Warn-once logger for unknown locales. Safe as a
     *                           request-scoped singleton because ValidationService
     *                           memoizes the validator registry, so this instance
     *                           is reused across all rows in a request.
     */
    public function __construct(
        private ?Logger $logger = null,
    ) {}

    public function validate(mixed $value, array $context): ValidationResult
    {
        $zip = trim((string) $value);

        if ($zip === '') {
            return ValidationResult::valid();
        }

        $locale = strtoupper((string) ($context['options']['locale'] ?? self::LOCALE_US));

        // Unsupported locales fall back to US, but warn once so an extension typo
        // (e.g. 'AU') does not silently validate against the wrong pattern.
        if (self::LOCALE_CA !== $locale && self::LOCALE_US !== $locale) {
            if (!isset($this->warnedLocales[$locale])) {
                $this->warnedLocales[$locale] = true;
                $this->logger?->warning(sprintf('Unknown ZIP locale "%s"; falling back to US validation.', $locale));
            }
            $locale = self::LOCALE_US;
        }

        if (self::LOCALE_CA === $locale) {
            if (preg_match(self::PATTERN_CA, $zip) !== 1) {
                return new ValidationResult(ValidationResult::STATUS_INVALID, 'Invalid Canadian postal code (expected A1A 1A1).');
            }

            return ValidationResult::valid();
        }

        if (preg_match(self::PATTERN_US, $zip) !== 1) {
            return new ValidationResult(ValidationResult::STATUS_INVALID, 'Invalid ZIP code (expected 5 or 9 digits).');
        }

        return ValidationResult::valid();
    }
}
