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
    public static function forRows(array $rows, ?array $columns = null): array
    {
        if ($columns === null) {
            $columns = self::resolvedColumns('bulk');
        } else {
            // Caller-supplied columns still get client presentation overrides
            // (label + order). applyClientOverrides is idempotent, so callers
            // that already finalized their set pay no cost, and callers passing
            // raw registered columns are not silently skipped.
            $columns = self::applyClientOverrides($columns);
        }

        // 1. Registered order first.
        $ordered = [];
        $seen = [];
        foreach ($columns as $column) {
            $key = $column->key;
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $ordered[] = $key;
            }
        }

        // 2. Append any extra row keys not covered by the registry, in
        //    first-seen order. Decodes raw_data safely (string JSON or array).
        //    P5: isset lookup (O(1)) instead of in_array (O(n)) per key — was O(n^2).
        foreach ($rows as $row) {
            foreach (array_keys(self::decodeRowData($row)) as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $ordered[] = $key;
                }
            }
        }

        return $ordered;
    }

    /**
     * Apply client-side presentation overrides to a resolved column set.
     *
     * Two opt-in filters, both defaulting to empty so an extension that hooks
     * neither keeps the registered order and labels verbatim (zero behavior
     * change):
     *
     *   - wicket_import_csv_column_labels: array<string,string> canonical key
     *     => header label. Rebuilds the immutable ColumnDefinition with the
     *     overridden label (validators/required/aliases/dedup preserved). The
     *     label is also an accepted header, but aliases still tolerate the
     *     original spellings, so re-uploading a CSV with either name parses.
     *   - wicket_import_csv_column_order: list<string> canonical keys in the
     *     desired order. Unlisted keys append in their current order so no
     *     column is silently dropped.
     *
     * Wired into {@see resolvedColumns()} (the single resolution seam used by
     * UploadController and forRows) so the CSV template, validation table, and
     * exports all agree on column presentation.
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return list<ColumnDefinition>
     */
    public static function applyClientOverrides(array $columns): array
    {
        // 1. Labels first. Immutable VO -> rebuild with the overridden label.
        //    Applied before ordering so the order filter sees the final set.
        $labels = apply_filters('wicket_import_csv_column_labels', [], $columns);
        if (is_array($labels) && $labels !== []) {
            $columns = array_map(static function (ColumnDefinition $c) use ($labels): ColumnDefinition {
                $label = $labels[$c->key] ?? null;
                if (!is_string($label) || $label === '') {
                    return $c;
                }

                return new ColumnDefinition(
                    key: $c->key,
                    label: $label,
                    required: $c->required,
                    aliases: $c->aliases,
                    validators: $c->validators,
                    dedup: $c->dedup,
                );
            }, $columns);
        }

        // 2. Order.
        return self::ordered($columns);
    }

    /**
     * Reorder a resolved column set by the wicket_import_csv_column_order
     * filter. No-op when the filter returns empty. Unlisted keys append in
     * their current order; unknown keys in the order list are ignored.
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return list<ColumnDefinition>
     */
    private static function ordered(array $columns): array
    {
        $order = apply_filters('wicket_import_csv_column_order', [], $columns);
        if (!is_array($order) || $order === []) {
            return $columns;
        }

        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }

        $ordered = [];
        $seen = [];
        foreach ($order as $key) {
            if (is_string($key) && isset($byKey[$key]) && !isset($seen[$key])) {
                $ordered[] = $byKey[$key];
                $seen[$key] = true;
            }
        }

        // Append any column the order list omitted so nothing is dropped.
        foreach ($columns as $column) {
            if (!isset($seen[$column->key])) {
                $ordered[] = $column;
                $seen[$column->key] = true;
            }
        }

        return $ordered;
    }

    /**
     * Resolve the full column set for a context: the wicket_import_csv_columns
     * filter, merged with the core identity baseline, then client presentation
     * overrides (label + order) applied.
     *
     * Single source of truth shared by UploadController::resolveColumns() and
     * forRows(), so the CSV template, validation, admin tables, and exports
     * all resolve columns the same way.
     *
     * @param string $context 'bulk' (default) or 'individual'.
     *
     * @return list<ColumnDefinition>
     */
    public static function resolvedColumns(string $context = 'bulk'): array
    {
        /** @var list<ColumnDefinition> $columns */
        $columns = apply_filters('wicket_import_csv_columns', [], ['context' => $context]);

        // Core always contributes the baseline identity columns and layers the
        // extension's domain columns on top, so export ordering matches the
        // upload/validation resolution. See DefaultColumns::mergeWith().
        $columns = DefaultColumns::mergeWith(is_array($columns) ? $columns : []);

        // Apply client presentation overrides (label + order). No-op when an
        // extension hooks neither filter. See applyClientOverrides().
        return self::applyClientOverrides($columns);
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
