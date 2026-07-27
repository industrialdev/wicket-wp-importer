<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Database;

/**
 * CRUD for the wicket_import_staged_records table.
 * Session-based staging for import rows.
 */
class ImportStagingTable
{
    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wicket_import_staged_records';
    }

    /**
     * Build a value fragment for a single column, handling NULL correctly.
     * $wpdb->prepare( '%s', null ) produces '' not SQL NULL.
     */
    private function sqlValue(mixed $value, string $format): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $format;
    }

    /**
     * Bulk insert rows for a session.
     *
     * @param array  $rows      Array of associative arrays matching table columns.
     * @param string $session_id UUID for the session.
     */
    public function insertBatch(array $rows, string $session_id): void
    {
        global $wpdb;

        if (empty($rows)) {
            return;
        }

        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $row) {
                $batch_id = $row['batch_id'] ?? null;
                $raw_data = isset($row['raw_data']) ? wp_json_encode($row['raw_data']) : null;
                $val_msg = $row['validation_message'] ?? null;
                $flagged = isset($row['flagged_fields']) ? wp_json_encode($row['flagged_fields']) : null;
                $mdp_uuid = $row['mdp_uuid'] ?? null;
                $imp_msg = $row['import_message'] ?? null;
                $ext_meta = isset($row['extension_metadata']) ? wp_json_encode($row['extension_metadata']) : null;
                $order_id = $row['order_id'] ?? null;
                $sub_ids = $row['subscription_ids'] ?? null;

                // Build per-row placeholder string with NULL literals for null values
                $ph = '('
                    . '%s, '                          // session_id (always set)
                    . $this->sqlValue($batch_id, '%s') . ', '
                    . '%d, '                          // row_index
                    . $this->sqlValue($raw_data, '%s') . ', '
                    . '%s, '                          // validation_status
                    . $this->sqlValue($val_msg, '%s') . ', '
                    . $this->sqlValue($flagged, '%s') . ', '
                    . $this->sqlValue($mdp_uuid, '%s') . ', '
                    . '%s, '                          // import_status
                    . $this->sqlValue($imp_msg, '%s') . ', '
                    . $this->sqlValue($ext_meta, '%s') . ', '
                    . $this->sqlValue($order_id, '%d') . ', '
                    . $this->sqlValue($sub_ids, '%s') . ', '
                    . '%s'                            // created_at
                    . ')';

                $placeholders[] = $ph;

                // Only push non-null values into the $values array (nulls use NULL literal)
                $values[] = $session_id;
                if ($batch_id !== null) {
                    $values[] = $batch_id;
                }
                $values[] = $row['row_index'] ?? 0;
                if ($raw_data !== null) {
                    $values[] = $raw_data;
                }
                $values[] = $row['validation_status'] ?? 'pending';
                if ($val_msg !== null) {
                    $values[] = $val_msg;
                }
                if ($flagged !== null) {
                    $values[] = $flagged;
                }
                if ($mdp_uuid !== null) {
                    $values[] = $mdp_uuid;
                }
                $values[] = $row['import_status'] ?? 'pending';
                if ($imp_msg !== null) {
                    $values[] = $imp_msg;
                }
                if ($ext_meta !== null) {
                    $values[] = $ext_meta;
                }
                if ($order_id !== null) {
                    $values[] = $order_id;
                }
                if ($sub_ids !== null) {
                    $values[] = $sub_ids;
                }
                $values[] = $this->utcNowMysql();
            }

            $query = "INSERT INTO {$this->table_name}
				( session_id, batch_id, row_index, raw_data, validation_status, validation_message, flagged_fields, mdp_uuid, import_status, import_message, extension_metadata, order_id, subscription_ids, created_at )
				VALUES " . implode(', ', $placeholders);

            $wpdb->query($wpdb->prepare($query, $values));
        }
    }

    /**
     * Get all rows for a session.
     */
    public function getBySession(string $session_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s ORDER BY row_index ASC",
                $session_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get flagged (invalid/duplicate/warning) rows for a session.
     */
    public function getFlaggedBySession(string $session_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND validation_status IN ('invalid', 'duplicate', 'warning') ORDER BY row_index ASC",
                $session_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get rows in a session that passed validation AND are still pending
     * import: validation_status IN ('valid', 'warning') AND import_status = 'pending'.
     *
     * This is the canonical "ready to import" set. The pending filter is
     * load-bearing for re-run safety (Task 12.3 / 12.4): a re-run of the
     * pipeline must NOT re-process rows that already moved to a terminal
     * import_status (imported, updated, skipped, failed, email_conflict,
     * skipped_active_membership, needs_review) on a prior run.
     *
     * The only caller is runConflictCheck, which needs every column.
     *
     * @return list<array<string,mixed>>
     */
    public function getValidBySession(string $session_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND validation_status IN ('valid', 'warning') AND import_status = 'pending' ORDER BY row_index ASC",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Atomically claim all importable rows in a session by transitioning
     * import_status from 'pending' to 'processing'. Required for the
     * concurrency guard on POST /import/session/{id}/run (Task 12.9): two
     * parallel /run calls on the same session both read the same pending
     * rows and both drive ImportAdapter::create(), which races on
     * create_mdp_record + create_local_membership_record. The atomic UPDATE
     * ensures only one claim can transition each row; the loser's claim
     * returns 0 affected rows and short-circuits.
     *
     * Returns the number of rows claimed (0 = nothing to do). The claim
     * transitions through 'processing'; runImport's per-row updateImportResult
     * moves each row to its terminal status (imported / updated / skipped /
     * failed / etc.) as the row finishes.
     *
     * @return int Affected-row count.
     */
    public function claimImportableInSession(string $session_id): int|false
    {
        global $wpdb;
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'processing' WHERE session_id = %s AND validation_status IN ('valid', 'warning') AND import_status = 'pending'",
                $session_id
            )
        );

        // B7: $wpdb->query() returns false on failure. Surface it (int|false)
        // so runImport can 500 instead of masking a DB error as "0 claimed"
        // (which reads as "a concurrent run won" and returns an empty 200).
        return is_int($affected) ? $affected : false;
    }

    /**
     * Check if a session has any rows currently in 'processing' status.
     * True while a /run request is in-flight on the session. Used by
     * handleRun to return 409 import_session_active on re-entry.
     */
    public function isSessionRunning(string $session_id): bool
    {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = 'processing'",
                $session_id
            )
        );

        return $count > 0;
    }

    /**
     * Get the rows a session has claimed (in 'processing' status). Mirrors
     * getImportableRowsBySession but filters on 'processing' instead of
     * 'pending', used by runImport after claimImportableInSession.
     *
     * @return list<array<string,mixed>>
     */
    public function getProcessingBySession(string $session_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND import_status = 'processing' ORDER BY row_index ASC",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Update import result for a single row.
     */
    public function updateImportResult(int $id, string $import_status, ?string $import_message = null): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            [
                'import_status' => $import_status,
                'import_message' => $import_message,
                'processed_at' => $this->utcNowMysql(),
            ],
            ['id' => $id]
        );
    }

    /**
     * Update the MDP UUID for a single row.
     */
    public function updatePersonUuid(int $id, string $uuid): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['mdp_uuid' => $uuid],
            ['id' => $id]
        );
    }

    /**
     * Update validation result for a single row.
     */
    public function updateValidationResult(int $id, string $validation_status, ?string $validation_message = null, ?array $flagged_fields = null): void
    {
        global $wpdb;
        $data = [
            'validation_status'  => $validation_status,
            'validation_message' => $validation_message,
        ];
        if ($flagged_fields !== null) {
            $data['flagged_fields'] = wp_json_encode($flagged_fields);
        }
        $wpdb->update($this->table_name, $data, ['id' => $id]);
    }

    /**
     * Update extension metadata for a single row.
     */
    public function updateExtensionMetadata(int $id, array $metadata): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['extension_metadata' => wp_json_encode($metadata)],
            ['id' => $id]
        );
    }

    /**
     * Read extension metadata for a single row.
     *
     * The read-merge-write counterpart to {@see updateExtensionMetadata()}, so
     * extensions (e.g. OBA's Bar ID reader) can add their own keys without
     * clobbering keys another extension wrote.
     *
     * @return array<string,mixed> Decoded metadata, or [] when the row or column is empty.
     */
    public function getExtensionMetadata(int $id): array
    {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT extension_metadata FROM {$this->table_name} WHERE id = %d",
                $id
            )
        );

        $decoded = $raw !== null && $raw !== '' ? json_decode((string) $raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Delete all rows for a session.
     */
    public function deleteSession(string $session_id): void
    {
        global $wpdb;
        $wpdb->delete($this->table_name, ['session_id' => $session_id]);
    }

    /**
     * The session_id of any session with pending (unfinished) rows, or null when
     * none. Used by the upload concurrency gate so the 409 can name the blocking
     * session (S2) and the admin can clear the right one. A session whose rows
     * are all terminal does NOT block.
     */
    public function hasActiveSession(): ?string
    {
        global $wpdb;
        // 'pending'/'session_id' are literals (no user input), so no prepare needed.
        $session_id = $wpdb->get_var(
            "SELECT session_id FROM {$this->table_name} WHERE import_status = 'pending' LIMIT 1"
        );

        return (is_string($session_id) && $session_id !== '') ? $session_id : null;
    }

    /**
     * Mark staged rows still pending past the session TTL as 'expired'
     * (Task 38.3).
     *
     * Abandoned sessions (uploaded/validated but never run, or a run that
     * crashed before claiming) leave rows in 'pending' forever; those rows
     * keep hasActiveSession() true (blocking new uploads) and bloat the
     * table. The hourly cron scheduled on plugin activation calls this with
     * the filterable wicket_import_session_ttl_hours (default 24h).
     *
     * Only 'pending' rows are expired: a row in 'processing' may belong to
     * an in-flight /run (reclaimed separately by expireStaleClaims()), and
     * terminal statuses are already settled. Expired rows keep their raw_data
     * for audit/export.
     *
     * B1: created_at is stored UTC (utcNowMysql) and the cutoff is computed
     * UTC via the base plugin's wicket_time_get_utc_datetime helper, so the
     * comparison is UTC-vs-UTC (a site-local store vs DB NOW() would drift by
     * the UTC offset).
     *
     * @param int $ttlHours Max age in hours before a pending row is expired.
     * @return int Number of rows marked expired.
     */
    public function expireStaleSessions(int $ttlHours): int
    {
        global $wpdb;
        $ttlHours = max(1, $ttlHours);

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'expired', import_message = %s, processed_at = %s WHERE import_status = 'pending' AND created_at < %s",
                sprintf('Session expired (auto, >%dh inactive)', $ttlHours),
                $this->utcNowMysql(),
                $this->staleCutoffUtc($ttlHours)
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Reclaim rows stuck in 'processing' after an interrupted run (C5).
     *
     * claimImportableInSession() transitions pending -> processing; the row
     * loop then moves each to a terminal status as it finishes. A fatal/OOM
     * mid-loop leaves rows permanently 'processing': isSessionRunning() then
     * returns 409 forever, the TTL cron skips them (it targets 'pending'),
     * and getImportSummary() has no bucket for them.
     *
     * A legitimate /run completes in seconds-to-minutes; if the hourly cron
     * still sees 'processing' rows, the run that claimed them is dead. Reclaim
     * them to 'needs_review' (NOT 'pending') because we cannot tell whether
     * ImportAdapter::create() already ran for the row — re-running could mint
     * a duplicate membership. Needs-review forces a human check. No time
     * comparison is needed (hence no timezone concern): presence of
     * 'processing' at cron time is itself the signal.
     *
     * @return int Number of rows reclaimed.
     */
    public function expireStaleClaims(): int
    {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'needs_review', import_message = %s, processed_at = %s WHERE import_status = 'processing'",
                'Reclaimed after an interrupted run.',
                $this->utcNowMysql()
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Current time as a UTC 'Y-m-d H:i:s' string. Reuses the base plugin's
     * wicket_time_get_utc_datetime helper (AD15 rung 1); falls back to gmdate
     * when the helper is not loaded (e.g. the unit suite).
     */
    private function utcNowMysql(): string
    {
        if (function_exists('wicket_time_get_utc_datetime')) {
            return wicket_time_get_utc_datetime('now')->format('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * A UTC 'Y-m-d H:i:s' cutoff for "now minus N hours". Used by
     * expireStaleSessions() against the UTC-stored created_at.
     */
    private function staleCutoffUtc(int $ttlHours): string
    {
        $ttlHours = max(1, $ttlHours);
        if (function_exists('wicket_time_get_utc_datetime')) {
            return wicket_time_get_utc_datetime('now')->modify("-{$ttlHours} hours")->format('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', time() - $ttlHours * 3600);
    }

    /**
     * Count pending rows in a session.
     */
    public function countPendingInSession(string $session_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = 'pending'",
                $session_id
            )
        );
    }

    /**
     * Count rows in a session that the import phase would actually process:
     * validation_status IN ('valid', 'warning') AND import_status = 'pending'.
     *
     * This is the precise pre-flight count for ImportPipeline::runImport's inline
     * cap. countPendingInSession() over-counts because it includes rows that
     * failed validation and would be skipped when $skipFlagged is true.
     */
    public function countImportableInSession(string $session_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND validation_status IN ('valid', 'warning') AND import_status = 'pending'",
                $session_id
            )
        );
    }

    /**
     * Get validation summary counts for a session.
     */
    public function getValidationSummary(string $session_id): array
    {
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT validation_status, COUNT(*) as count FROM {$this->table_name} WHERE session_id = %s GROUP BY validation_status",
                $session_id
            ),
            ARRAY_A
        );

        $summary = [
            'valid'     => 0,
            'invalid'   => 0,
            'duplicate' => 0,
            'warning'   => 0,
            'pending'   => 0,
        ];

        foreach ($results as $row) {
            $key = $row['validation_status'];
            if (array_key_exists($key, $summary)) {
                $summary[$key] = (int) $row['count'];
            }
        }

        return $summary;
    }

    /**
     * Get import summary counts for a session.
     */
    public function getImportSummary(string $session_id): array
    {
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT import_status, COUNT(*) as count FROM {$this->table_name} WHERE session_id = %s GROUP BY import_status",
                $session_id
            ),
            ARRAY_A
        );

        $summary = [
            'pending'                 => 0,
            'imported'                => 0,
            'updated'                 => 0,
            'skipped'                 => 0,
            'failed'                  => 0,
            'email_conflict'          => 0,
            'skipped_active_membership' => 0,
            'phase1_complete'         => 0,
            'phase2_complete'         => 0,
            'needs_review'            => 0,
            'expired'                 => 0,
            'processing'              => 0,
        ];

        foreach ($results as $row) {
            $key = $row['import_status'];
            if (array_key_exists($key, $summary)) {
                $summary[$key] = (int) $row['count'];
            }
        }

        return $summary;
    }
}
