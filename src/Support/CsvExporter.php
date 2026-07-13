<?php

declare(strict_types=1);

namespace WicketImporter\Support;

use WicketWP\Support\CsvExporter as BaseCsvExporter;

/**
 * Backwards-compatibility shim.
 *
 * The canonical implementation now lives in wicket-wp-base-plugin at
 * WicketWP\Support\CsvExporter (extracted in WWID-1907 phase 0 so the
 * account-centre plugin can share it without inverting the dependency
 * direction — AD15). This class is preserved so existing importer call sites
 * keep resolving under their old namespace. It delegates every call to the
 * base-plugin implementation and adds no behaviour of its own.
 *
 * Prefer depending on WicketWP\Support\CsvExporter directly in new code.
 *
 * Note: this shim depends on wicket-wp-base-plugin being active (declared in
 * the importer's `Requires Plugins` header). It resolves at WordPress runtime
 * and under the central QA autoload map (qa/pest.php), but a standalone
 * `composer install` of the importer repo alone will NOT autoload the
 * WicketWP\ namespace — there is no composer dependency on base-plugin by
 * design (matches the cross-plugin convention used across the stack).
 */
class CsvExporter
{
    private BaseCsvExporter $delegate;

    public function __construct()
    {
        $this->delegate = new BaseCsvExporter();
    }

    /**
     * Escape a single cell value against CSV formula injection.
     */
    public function escapeCellValue(mixed $value): string
    {
        return $this->delegate->escapeCellValue($value);
    }

    /**
     * Write one escaped row to a CSV handle.
     *
     * @param resource    $handle  Open write handle (e.g. php://output).
     * @param list<mixed> $values  Cell values for the row.
     */
    public function writeRow(array $values, $handle): void
    {
        $this->delegate->writeRow($values, $handle);
    }

    /**
     * Stream a CSV download to the browser and terminate the request.
     *
     * @param string            $filename Download filename (sanitized via sanitize_file_name()).
     * @param list<list<mixed>> $rows     Rows to write; the first row is the header row.
     */
    public function download(string $filename, array $rows): never
    {
        $this->delegate->download($filename, $rows);
    }
}
