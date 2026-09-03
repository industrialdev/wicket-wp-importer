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
     * Atomically claim a bounded chunk of importable rows for Action Scheduler
     * processing (Slice 0). Transitions up to $limit rows from 'pending' to
     * 'processing', in row order, scoped to a session.
     *
     * This is the chunk-safe counterpart to {@see claimImportableInSession()},
     * which claims the ENTIRE session (no LIMIT) and is correct only for the
     * inline single-request path. Under Action Scheduler each chunk action
     * claims its own bounded slice (ORDER BY row_index ASC LIMIT n) so two
     * chunks never claim the same rows.
     *
     * Single-chain model: BatchProcessor runs one chunk at a time per batch
     * (self-perpetuating AS actions), so at any moment the only 'processing'
     * rows in the session are the ones this chunk just claimed; the caller
     * fetches them via {@see getProcessingBySession()}. If concurrent AS
     * runners are later enabled, this must return the claimed IDs rather than
     * a count, so two chunks cannot process the same rows.
     *
     * @param string $session_id Session to claim from.
     * @param int    $limit      Max rows to claim (clamped to >= 1).
     *
     * @return int|false Rows claimed (0 = nothing left to claim), or false on DB error.
     */
    public function claimChunk(string $session_id, int $limit): int|false
    {
        global $wpdb;
        $limit = max(1, $limit);

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                 SET import_status = 'processing', processing_claimed_at = %s
                 WHERE session_id = %s
                   AND validation_status IN ('valid', 'warning')
                   AND import_status = 'pending'
                 ORDER BY row_index ASC
                 LIMIT %d",
                $this->utcNowMysql(),
                $session_id,
                $limit
            )
        );

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
     * Get rows in a session filtered to a set of import statuses.
     *
     * Used by the cheque Review UI and its error CSV export to render the
     * failed + needs_review rows a human reviews before Phase 2. Generic so a
     * future caller (e.g. the Phase 2 reconciler) can query any status set
     * without a new method.
     *
     * @param string        $session_id Session to read.
     * @param list<string>  $statuses   import_status values to include.
     *
     * @return list<array<string,mixed>> Rows (ARRAY_A), in row order.
     */
    public function getByImportStatus(string $session_id, array $statuses): array
    {
        global $wpdb;
        $statuses = array_values(array_filter($statuses, 'is_string'));
        if ($statuses === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT * FROM {$this->table_name} WHERE session_id = %s AND import_status IN ({$placeholders}) ORDER BY row_index ASC, id ASC";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $session_id, ...$statuses),
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
     * Write the WC order ID onto a staged row. Used by the cheque compensation
     * path so a needs_review row retains the order_id an admin must reconcile.
     */
    public function updateOrderId(int $id, int $orderId): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['order_id' => $orderId],
            ['id' => $id]
        );
    }

    /**
     * Write the created WC subscription IDs onto a staged row so the results
     * CSV and the REST /results endpoint can report them (WWID-2350). Both
     * flows land here: the inline member path (InlineSubscriptionCreator) and
     * the cheque path (BatchProcessor via RowResult). Empty list clears the
     * column; IDs are stored as a JSON array like the cheque match path does.
     *
     * @param list<int> $subscriptionIds
     */
    public function updateSubscriptionIds(int $id, array $subscriptionIds): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['subscription_ids' => wp_json_encode(array_values(array_map('intval', $subscriptionIds)))],
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
    /**
     * Distinct orders a session's Phase 1 created (WWID-2437 cleanup count).
     * Rows without an order (failures, needs_review without creation) are not
     * counted, so the checkbox only promises orders that exist.
     */
    public function countCreatedOrders(string $session_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) FROM {$this->table_name} WHERE session_id = %s AND order_id > 0",
                $session_id
            )
        );
    }

    public function deleteSession(string $session_id): void
    {
        global $wpdb;
        $wpdb->delete($this->table_name, ['session_id' => $session_id]);
    }

    /**
     * Count rows eligible for Phase 2 reconciliation: Phase 1 fully imported
     * with an order on file (D-LOCKBOX-4). The Phase 2 total for a run.
     */
    public function countPhase2Eligible(string $session_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = 'imported' AND order_id IS NOT NULL AND order_id > 0",
                $session_id
            )
        );
    }

    /**
     * Claim a bounded chunk of Phase 2 rows (single-chain, ORDER BY row_index
     * + LIMIT): imported rows with an order move to 'phase2_processing' and
     * get a claim timestamp for the threshold-guarded stale reclaim.
     *
     * @return int|false Rows claimed (0 = nothing left), or false on DB error.
     */
    public function claimPhase2Chunk(string $session_id, int $limit): int|false
    {
        global $wpdb;
        $limit = max(1, $limit);

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                 SET import_status = 'phase2_processing', processing_claimed_at = %s
                 WHERE session_id = %s
                   AND import_status = 'imported'
                   AND order_id IS NOT NULL
                   AND order_id > 0
                   AND payment_amount IS NULL
                 ORDER BY row_index ASC
                 LIMIT %d",
                $this->utcNowMysql(),
                $session_id,
                $limit
            )
        );
    }

    /**
     * Fetch this session's claimed-but-unprocessed Phase 2 rows.
     *
     * @return list<array<string,mixed>>
     */
    public function getPhase2ProcessingBySession(string $session_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND import_status = 'phase2_processing' ORDER BY row_index ASC",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Persist one Phase 2 reconciliation outcome: terminal import status,
     * reason, and the money triple (bank amount, order total, signed
     * discrepancy = bank - total). NULL amounts for rows that never
     * reconciled (order vanished, chunk exception).
     */
    public function updateReconciliation(
        int $id,
        string $import_status,
        ?string $import_message,
        ?float $paymentAmount,
        ?float $expectedAmount,
        ?float $discrepancyAmount
    ): void {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            [
                'import_status'      => $import_status,
                'import_message'     => $import_message,
                'payment_amount'     => $paymentAmount,
                'expected_amount'    => $expectedAmount,
                'discrepancy_amount' => $discrepancyAmount,
                'processing_claimed_at' => null,
                'processed_at'       => $this->utcNowMysql(),
            ],
            ['id' => $id],
            ['%s', '%s', '%f', '%f', '%f', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Reset Phase 2 failed rows to 'imported' so a retry re-reconciles them.
     * Only rows that carry an order_id are Phase 2 failures (Phase 1 failures
     * never created one). Shortfall needs_review rows are a human decision and
     * are NOT reset (D-LOCKBOX-4 retry semantics).
     */
    public function resetPhase2FailedForRetry(string $session_id): int
    {
        global $wpdb;
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                 SET import_status = 'imported', import_message = '',
                     payment_amount = NULL, expected_amount = NULL, discrepancy_amount = NULL,
                     processing_claimed_at = NULL, processed_at = NULL
                 WHERE session_id = %s AND import_status = 'failed' AND order_id IS NOT NULL AND order_id > 0",
                $session_id
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Tally Phase 2 outcomes for a session (D-LOCKBOX-4). A reconciled row is
     * any row that carries a payment_amount; failures are 'failed' rows with
     * an order (Phase 1 failures have none); shortfall rows are needs_review
     * with a payment_amount. Pending = eligible rows not yet claimed.
     *
     * @return array{succeeded:int,failed:int,needs_review:int,pending:int}
     */
    public function tallyPhase2(string $session_id): array
    {
        global $wpdb;

        $tally = static function (string $sql, string $status) use ($wpdb, $session_id): int {
            return (int) $wpdb->get_var($wpdb->prepare($sql, $session_id, $status));
        };

        return [
            'succeeded'    => $tally(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = %s AND payment_amount IS NOT NULL",
                'imported'
            ),
            'failed'       => $tally(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = %s AND order_id IS NOT NULL AND order_id > 0",
                'failed'
            ),
            'needs_review' => $tally(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = %s AND payment_amount IS NOT NULL",
                'needs_review'
            ),
            'pending'      => $tally(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = %s AND order_id IS NOT NULL AND order_id > 0 AND payment_amount IS NULL",
                'imported'
            ),
        ];
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
            "SELECT session_id FROM {$this->table_name} WHERE import_status = 'pending' AND validation_status IN ('valid', 'warning') LIMIT 1"
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
     * A row stays 'processing' while its chunk is in flight. With per-row
     * MDP/WC writes (cheque flow), a chunk can legitimately run long, so the
     * reclaim is threshold-guarded on processing_claimed_at: only rows claimed
     * more than wicket_import_stale_claim_seconds ago (default 1800) are
     * reclaimed. Rows claimed without a timestamp (legacy rows, or claims made
     * before the column existed) keep the old presence-based behavior — they
     * cannot be dated, so presence at cron time is still the signal.
     *
     * @return int Number of rows reclaimed.
     */
    public function expireStaleClaims(): int
    {
        global $wpdb;

        /** @var int $threshold Seconds after which a claim is considered dead. */
        $threshold = (int) apply_filters('wicket_import_stale_claim_seconds', 1800);
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(60, $threshold));

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'needs_review', import_message = %s, processed_at = %s WHERE import_status = 'processing' AND (processing_claimed_at IS NULL OR processing_claimed_at < %s)",
                'Reclaimed after an interrupted run.',
                $this->utcNowMysql(),
                $cutoff
            )
        );

        // Phase 2 claims (D-LOCKBOX-4): a dead 'phase2_processing' claim goes
        // back to 'imported' with its payment columns still NULL so the next
        // Phase 2 chunk (or retry) re-claims it.
        $phase2Affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'imported', import_message = '', processing_claimed_at = NULL, processed_at = NULL WHERE import_status = 'phase2_processing' AND (processing_claimed_at IS NULL OR processing_claimed_at < %s)",
                $cutoff
            )
        );

        return max(is_int($affected) ? $affected : 0, is_int($phase2Affected) ? $phase2Affected : 0);
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
            'phase2_processing'       => 0,
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
