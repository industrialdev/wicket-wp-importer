<?php

declare(strict_types=1);

namespace WicketImporter\Support;

/**
 * CSV export helper: streams an injection-safe CSV download.
 *
 * AD14: every exported cell is prefixed with a tab when it starts with a
 * formula-injection character (= + - @ tab CR), so a malicious member-supplied
 * value can't execute as a spreadsheet formula on download.
 *
 * NOTE: this used to delegate to WicketWP\Support\CsvExporter in wicket-wp-base-plugin
 * (extracted in WWID-1907). That extraction is not merged, so the importer ships its
 * own inline implementation to keep the download buttons (template / flagged / results)
 * working without the unmerged dependency. If/when WWID-1907 lands, this can return to
 * a thin delegate.
 */
final class CsvExporter
{
    /**
     * Escape a single cell value against CSV formula injection.
     */
    public function escapeCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        // Prefix cells starting with a formula-injection character with a tab.
        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            return "\t" . $value;
        }

        return $value;
    }

    /**
     * Write one escaped row to a CSV handle.
     *
     * @param resource    $handle  Open write handle (e.g. php://output).
     * @param list<mixed> $values  Cell values for the row.
     */
    public function writeRow(array $values, $handle): void
    {
        fputcsv($handle, array_map([$this, 'escapeCellValue'], $values));
    }

    /**
     * Stream a CSV download to the browser and terminate the request.
     *
     * @param string            $filename Download filename (sanitized via sanitize_file_name()).
     * @param list<list<mixed>> $rows     Rows to write; the first row is the header row.
     */
    public function download(string $filename, array $rows): never
    {
        $filename = sanitize_file_name($filename);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            $this->writeRow($row, $out);
        }
        fclose($out);

        exit;
    }
}
