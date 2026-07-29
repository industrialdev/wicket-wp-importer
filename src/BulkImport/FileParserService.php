<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

use WicketImporter\Services\Logger;
use WicketImporter\ValueObjects\ColumnDefinition;
use WicketImporter\ValueObjects\CsvRow;
use WicketImporter\ValueObjects\ParseResult;

/**
 * Parses a CSV file into CsvRow objects mapped against column definitions.
 *
 * Responsibilities: file/size validation, BOM handling, delimiter resolution
 * via the wicket_import_csv_delimiter filter, and alias-aware case-insensitive
 * header matching. Max file size is enforced here via the wicket_import_max_file_size
 * filter so the REST upload endpoint (Task 6) only needs to delegate.
 */
final class FileParserService
{
    public function __construct(
        private ?Logger $logger = null,
    ) {}

    /**
     * Parse a CSV file into rows keyed by canonical column keys.
     *
     * @param string                 $path             Absolute path to an uploaded CSV. Caller (REST endpoint, Task 6) MUST verify it resides under wp_upload_dir()['basedir'] before calling.
     * @param list<ColumnDefinition> $columnDefinitions Expected columns.
     * @param string|null            $delimiter        Per-upload override (user-selectable). Restricted to the supported set; null falls back to the wicket_import_csv_delimiter filter default.
     * @return ParseResult Rows on success; error populated on failure.
     */
    public function parseFile(string $path, array $columnDefinitions, ?string $delimiter = null): ParseResult
    {
        if ($columnDefinitions === []) {
            return new ParseResult(rows: [], missingHeaders: [], totalCount: 0, error: 'No column definitions provided.');
        }

        $precheck = $this->precheckFile($path);
        if ($precheck !== null) {
            return new ParseResult(
                rows: [],
                missingHeaders: [],
                totalCount: 0,
                error: $precheck['message'],
                sizeExceeded: $precheck['size_exceeded'],
            );
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return new ParseResult(rows: [], missingHeaders: [], totalCount: 0, error: 'Unable to open CSV file.');
        }

        try {
            $this->stripBom($handle);
            $delimiter = $this->resolveDelimiter($delimiter);

            return $this->readRows($handle, $delimiter, $columnDefinitions);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate existence and size before opening. Returns a failure shape
     * (message + whether it was a size rejection) or null when the file is OK.
     *
     * @return array{message: string, size_exceeded: bool}|null
     */
    private function precheckFile(string $path): ?array
    {
        if (!is_file($path)) {
            return [
                'message'       => 'CSV file not found.',
                'size_exceeded' => false,
            ];
        }

        /** @var int $maxSize Filterable max upload size in bytes. */
        $maxSize = (int) apply_filters('wicket_import_max_file_size', WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE);
        $size = filesize($path);

        if ($size !== false && $size > $maxSize) {
            return [
                'message'       => sprintf(
                    'CSV file (%1$s) exceeds the maximum allowed size (%2$s).',
                    size_format((float) $size),
                    size_format((float) $maxSize)
                ),
                'size_exceeded' => true,
            ];
        }

        return null;
    }

    /**
     * Resolve the CSV delimiter.
     *
     * A per-upload user choice (from the upload UI) wins when it is one of the
     * supported set; otherwise the wicket_import_csv_delimiter filter default
     * applies. The supported set is locked to comma and semicolon because those
     * are the only delimiters the UI offers.
     *
     * @param string|null $explicit Per-upload delimiter chosen in the UI.
     */
    private function resolveDelimiter(?string $explicit = null): string
    {
        if (in_array($explicit, [',', ';'], true)) {
            return $explicit;
        }

        /** @var string $delimiter */
        $delimiter = (string) apply_filters('wicket_import_csv_delimiter', ',');

        if (mb_strlen($delimiter) !== 1) {
            $this->log('warning', sprintf('Invalid CSV delimiter from filter (%s); falling back to comma.', $delimiter));

            return ',';
        }

        return $delimiter;
    }

    /**
     * Detect a BOM and either consume it (UTF-8) or attach an iconv stream filter (UTF-16).
     *
     * @param resource $handle
     */
    private function stripBom($handle): void
    {
        rewind($handle);
        $peek = fread($handle, 3);
        $peek = $peek === false ? '' : $peek;
        rewind($handle);

        // UTF-16 BOMs are 2 bytes; fread returns 3, so use str_starts_with (=== is always false on unequal lengths).
        // Consume the 2-byte BOM before attaching iconv, else the filter decodes the BOM codepoint itself.
        if (str_starts_with($peek, "\xFF\xFE")) {
            fread($handle, 2);
            stream_filter_append($handle, 'convert.iconv.UTF-16LE.UTF-8');

            return;
        }
        if (str_starts_with($peek, "\xFE\xFF")) {
            fread($handle, 2);
            stream_filter_append($handle, 'convert.iconv.UTF-16BE.UTF-8');

            return;
        }
        // UTF-8 BOM: consume the 3 bytes, pointer sits at real content.
        if ($peek === "\xEF\xBB\xBF") {
            fread($handle, 3);
        }
    }

    /**
     * Read header + data rows.
     *
     * @param resource              $handle
     * @param list<ColumnDefinition> $columnDefinitions
     */
    private function readRows($handle, string $delimiter, array $columnDefinitions): ParseResult
    {
        $rawHeaders = fgetcsv($handle, 0, $delimiter, '"', '');
        $headers = is_array($rawHeaders) ? array_map(strval(...), $rawHeaders) : [];

        $hasHeader = array_filter($headers, fn (string $h) => $h !== '') !== [];
        if (!$hasHeader) {
            return new ParseResult(rows: [], missingHeaders: [], totalCount: 0, error: 'CSV file has no header row.');
        }

        $headerToKey = $this->mapHeaders($headers, $columnDefinitions);
        $missing = $this->missingRequiredHeaders($columnDefinitions, $headerToKey);

        if ($missing !== []) {
            return new ParseResult(
                rows: [],
                missingHeaders: $missing,
                totalCount: 0,
                error: 'Missing required CSV headers: ' . implode(', ', $this->labelsForKeys($missing, $columnDefinitions)),
            );
        }

        // B14: two headers mapping to the same column -> the later cell silently
        // wins (possibly an empty one). Reject up front so the admin sees the
        // collision instead of a confusing wrong-cell import.
        $matchedKeys = array_values($headerToKey);
        if (count($matchedKeys) !== count(array_unique($matchedKeys))) {
            $dupes = array_unique(array_diff_assoc($matchedKeys, array_unique($matchedKeys)));

            return new ParseResult(
                rows: [],
                missingHeaders: [],
                totalCount: 0,
                error: 'Duplicate CSV header for column: ' . implode(', ', $dupes),
            );
        }

        $rows = [];
        $rowIndex = 0;
        while (($raw = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            // Skip blank lines. [null] = truly empty line; [''] = single empty quoted token.
            // Whitespace-only cells ("  ","  ") are NOT skipped — spec says blank lines only.
            if ($raw === [null] || $raw === ['']) {
                continue;
            }

            $rows[] = new CsvRow(
                rowIndex: $rowIndex,
                data: $this->buildRowData($raw, $headerToKey),
                rawData: $raw,
            );
            $rowIndex++;
        }

        return new ParseResult(rows: $rows, missingHeaders: [], totalCount: count($rows));
    }

    /**
     * Map CSV column positions to canonical keys via alias-aware matching.
     *
     * @param list<string>           $headers
     * @param list<ColumnDefinition> $columnDefinitions
     * @return array<int, string> position => column key
     */
    private function mapHeaders(array $headers, array $columnDefinitions): array
    {
        $map = [];
        foreach ($headers as $position => $header) {
            foreach ($columnDefinitions as $column) {
                if ($column->matchesHeader((string) $header)) {
                    $map[$position] = $column->key;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Required column keys with no matching CSV header.
     *
     * @param list<ColumnDefinition> $columnDefinitions
     * @param array<int, string>     $headerToKey
     * @return list<string>
     */
    private function missingRequiredHeaders(array $columnDefinitions, array $headerToKey): array
    {
        $matched = array_values($headerToKey);
        $missing = [];
        foreach ($columnDefinitions as $column) {
            if ($column->required && !in_array($column->key, $matched, true)) {
                $missing[] = $column->key;
            }
        }

        return $missing;
    }

    /**
     * Build the associative row data from raw cells and the position map.
     *
     * @param list<string|null> $raw
     * @param array<int, string> $headerToKey
     * @return array<string, string|null>
     */
    private function buildRowData(array $raw, array $headerToKey): array
    {
        $data = [];
        foreach ($headerToKey as $position => $key) {
            $value = $raw[$position] ?? null;
            $data[$key] = is_string($value) ? trim($value) : $value;
        }

        return $data;
    }

    /**
     * Map required-column keys back to their human-readable labels for error messages.
     *
     * @param list<string>           $keys
     * @param list<ColumnDefinition> $columnDefinitions
     * @return list<string>
     */
    private function labelsForKeys(array $keys, array $columnDefinitions): array
    {
        $byKey = [];
        foreach ($columnDefinitions as $column) {
            $byKey[$column->key] = $column->label;
        }

        return array_map(fn (string $key): string => $byKey[$key] ?? $key, $keys);
    }

    private function log(string $level, string $message): void
    {
        $this->logger?->{$level}($message);
    }
}
