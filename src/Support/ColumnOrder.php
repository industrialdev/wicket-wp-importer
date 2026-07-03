<?php

declare(strict_types=1);

namespace WicketImporter\Support;

use WicketImporter\ValueObjects\ColumnDefinition;

/**
 * Resolves the column order for rendered tables and CSV exports.
 *
 * Centralized so the admin validation/confirmation tables (ImportAdminPage)
 * and the REST CSV exports (UploadController) agree on column order — and so
 * the order matches what the extension registered via wicket_import_csv_columns,
 * not the happenstance order keys first appear in the staged rows.
 *
 * Order rule:
 *   1. Registered columns, in their declared order (wicket_import_csv_columns).
 *   2. Any extra keys present in the rows but not registered (e.g. an alias
 *      the parser normalized, or an unregistered column the CSV carried),
 *      appended in first-seen order so no data is silently dropped.
 *
 * Without step 1, a session whose first row is missing a field would render
 * columns in a shifted order vs a session whose first row had every field —
 * confusing for admins comparing two imports.
 */
final class ColumnOrder
{
    /**
     * Resolve the ordered column keys for a set of staged rows.
     *
     * @param array<array<string,mixed>> $rows  Staged rows (each with a raw_data JSON blob).
     * @param list<ColumnDefinition>    $columns Registered column definitions (optional;
     *                                          defaults to the wicket_import_csv_columns filter).
     *
     * @return list<string> Ordered, de-duplicated column keys.
     */
    public static function forRows(array $rows, array $columns = null): array
    {
        if ($columns === null) {
            $columns = self::registeredColumns();
        }

        // 1. Registered order first.
        $ordered = [];
        foreach ($columns as $column) {
            $key = $column->key;
            if ($key !== '' && !in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        // 2. Append any extra row keys not covered by the registry, in
        //    first-seen order. Decodes raw_data safely (string JSON or array).
        foreach ($rows as $row) {
            foreach (array_keys(self::decodeRowData($row)) as $key) {
                if (!in_array($key, $ordered, true)) {
                    $ordered[] = $key;
                }
            }
        }

        return $ordered;
    }

    /**
     * Registered columns via the wicket_import_csv_columns filter (the same
     * source UploadController::resolveColumns uses). Centralized so the order
     * resolver doesn't depend on a controller instance.
     *
     * @return list<ColumnDefinition>
     */
    private static function registeredColumns(): array
    {
        /** @var list<ColumnDefinition> $columns */
        $columns = apply_filters('wicket_import_csv_columns', [], ['context' => 'bulk']);
        $columns = is_array($columns) ? $columns : [];

        // Fall back to the baseline person columns so export ordering matches
        // the upload/validation resolution (see UploadController::resolveColumns).
        if ($columns === []) {
            $columns = DefaultColumns::bulk();
        }

        return $columns;
    }

    /**
     * Decode the raw_data blob on a staged row into an array. Accepts either
     * a JSON string (the stored shape) or an already-decoded array (tests).
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function decodeRowData(array $row): array
    {
        $raw = $row['raw_data'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($raw) ? $raw : [];
    }
}
