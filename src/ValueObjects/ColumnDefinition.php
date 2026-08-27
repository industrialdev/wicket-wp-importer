<?php

declare(strict_types=1);

namespace WicketImporter\ValueObjects;

/**
 * Immutable definition of a single expected CSV column.
 *
 * Header matching is alias-aware and case-insensitive: a CSV header cell maps
 * to this column when it normalizes to the key, the label, or any alias.
 */
final class ColumnDefinition
{
    /**
     * @param string       $key        Canonical column key used in parsed row data.
     * @param string       $label      Human-readable label; also accepted as a header.
     * @param bool         $required   Whether the CSV must include a header for this column.
     * @param list<string> $aliases    Additional accepted header spellings.
     * @param list<array<string,mixed>> $validators Validator specs applied by ValidationService (Task 5). Each spec is ['type' => <name>, ...options].
     * @param bool         $dedup      Whether this column is part of the in-file duplicate composite key (Task 5.5).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $aliases = [],
        public readonly array $validators = [],
        public readonly bool $dedup = false,
    ) {}

    /** @var list<string>|null */
    private ?array $acceptedHeadersCache = null;

    /**
     * All header strings that should map to this column, normalized for comparison.
     *
     * @return list<string>
     */
    public function acceptedHeaders(): array
    {
        if ($this->acceptedHeadersCache !== null) {
            return $this->acceptedHeadersCache;
        }

        $candidates = array_merge([$this->key, $this->label], $this->aliases);

        return $this->acceptedHeadersCache = array_values(array_unique(array_map([self::class, 'normalize'], $candidates)));
    }

    /**
     * Does a raw CSV header cell map to this column?
     */
    public function matchesHeader(string $header): bool
    {
        return in_array(self::normalize($header), $this->acceptedHeaders(), true);
    }

    /**
     * Normalize a header/key/alias for case-insensitive, whitespace-insensitive comparison.
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        // Collapse underscores, hyphens, and whitespace runs so that
        // "Email Address", "email_address", and "Email-Address" share one alias.
        $value = preg_replace('/[_\-\s]+/u', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }
}
