<?php

declare(strict_types=1);

namespace WicketImporter\ValueObjects;

/**
 * Outcome of parsing a CSV file into typed rows. Immutable.
 */
final class ParseResult
{
    /**
     * @param list<CsvRow> $rows           Successfully parsed rows.
     * @param list<string> $missingHeaders Required column keys the CSV did not provide.
     * @param bool        $sizeExceeded   True when the error is a max-size rejection (FileParserService owns the check; the REST upload endpoint reads this to map to HTTP 413 without duplicating enforcement).
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $missingHeaders,
        public readonly int $totalCount,
        public readonly ?string $error = null,
        public readonly bool $sizeExceeded = false,
    ) {}

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Whether the parse failed specifically because the file exceeded the
     * filterable max upload size. Lets callers pick the right HTTP status (413)
     * while FileParserService stays the single enforcement point.
     */
    public function hasSizeError(): bool
    {
        return $this->sizeExceeded;
    }

    public function hasMissingHeaders(): bool
    {
        return $this->missingHeaders !== [];
    }
}
