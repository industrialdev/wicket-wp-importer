<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\BulkImport\Database\ImportStagingTable;
use WicketImporter\BulkImport\Database\PaymentStagingTable;
use WicketImporter\BulkImport\Subscriptions\PaymentMatcher;
use WicketImporter\Services\Logger;

/**
 * Generic bulk-import batch engine on Action Scheduler.
 *
 * Owns the per-batch lifecycle: start a run, then process it in bounded chunks
 * (WICKET_IMPORT_CHUNK_SIZE, filter wicket_import_chunk_size) via a
 * self-perpetuating AS action (hook wicket_import_process_chunk) until no
 * importable rows remain. Also writes the wicket_import_batches row so the
 * Import History tab renders real runs (G3).
 *
 * Phase 1 completion lands the batch in `pending_review` (the human gate
 * before Phase 2). The inline member path stays `completed` via
 * finishRunBySession; only the cheque/AS path arms the review gate.
 *
 * Single-chain model: one chunk action runs at a time per batch (each action
 * claims its own slice via ImportStagingTable::claimChunk, processes it, then
 * schedules the next). Concurrent AS runners are not assumed; if enabled
 * later, claimChunk must return claimed IDs rather than a count.
 *
 * The per-row WORK is the adapter's job: processRow() is a Slice-0 placeholder
 * that marks the row terminal. Slice 2 replaces it with the cheque pipeline
 * (resolver chain -> OrderCreator -> SubscriptionCreator) and the compensation
 * behavior on subscription failure (needs_review + retained order_id, no
 * auto-cancel).
 *
 * AS is a runtime dependency via WooCommerce Subscriptions (not vendored here),
 * so every as_* call is function_exists-guarded and a no-op outside a live
 * stack; the chain is then driven synchronously by tests.
 */
final class BatchProcessor
{
    /**
     * @var non-empty-string
     */
    private const TABLE = 'wicket_import_batches';

    /**
     * Action Scheduler hook a chunk action fires. Registered in
     * WicketImporter::plugin_setup(); the callback is processChunk().
     */
    public const CHUNK_HOOK = 'wicket_import_process_chunk';

    public function __construct(
        private readonly ?RowProcessor $rowProcessor = null,
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Extra chunks allowed beyond the expected chunk count before the
     * self-perpetuating chain is aborted (22.3). Guards against a runaway
     * loop where rows never reach a terminal status.
     */
    private const RESCHEDULE_HEADROOM = 5;

    /**
     * Insert a `wicket_import_batches` row for the run start. Returns the new
     * batch_id (a UUID) so the caller can pass it to finishRun.
     */
    public function startRun(string $sessionId, string $csvFilename, int $createdByUserId, int $totalRows, string $flow = 'member'): string
    {
        global $wpdb;

        $batchId = \wp_generate_uuid4();

        // created_at auto-defaults to CURRENT_TIMESTAMP; the History tab's
        // "Started" column reads it, so no explicit run-start column is needed.
        // phase1_started_at records when the AS chain actually began (same
        // moment as the insert on this path). batch_label is the human-readable
        // reporting key (spec Story 12: YYYYMMDD-HHMM, site timezone) written to
        // _batch_id meta on every order + subscription; generated once here so
        // every chunk re-reads the SAME label from the row. import_flow tags
        // the session's column contract (member | cheque) for the run-route
        // flow guards.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}" . self::TABLE . ' (batch_id, session_id, status, csv_filename, created_by_user_id, csv_row_count, phase1_total, phase1_started_at, batch_label, import_flow) VALUES (%s, %s, %s, %s, %d, %d, %d, %s, %s, %s)',
            $batchId,
            $sessionId,
            'running',
            $csvFilename,
            $createdByUserId,
            $totalRows,
            $totalRows,
            \current_time('mysql', true),
            \current_time('Ymd-Hi'),
            $flow === 'cheque' ? 'cheque' : 'member'
        ));

        $this->logger?->info('Batch run started.', [
            'batch_id' => $batchId,
            'session_id' => $sessionId,
            'total_rows' => $totalRows,
        ]);

        return $batchId;
    }

    /**
     * Update the batch row with the run's final stats.
     *
     * @param array<string,int> $stats phase1_succeeded, phase1_failed, phase1_needs_review,
     *                          phase2_total, phase2_succeeded, phase2_failed, phase2_needs_review.
     *                          Unknown keys are ignored.
     */
    public function finishRun(string $batchId, string $status, array $stats, array $extraColumns = []): void
    {
        global $wpdb;

        $allowed = [
            'phase1_succeeded', 'phase1_failed', 'phase1_needs_review',
            'phase2_total', 'phase2_succeeded', 'phase2_failed', 'phase2_needs_review',
        ];
        $data = ['status' => $status, 'finished_at' => \current_time('mysql', true)];
        $formats = ['%s', '%s'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $stats)) {
                $data[$k] = (int) $stats[$k];
                $formats[] = '%d';
            }
        }
        foreach ($extraColumns as $column => $value) {
            $data[$column] = $value;
            $formats[] = is_int($value) ? '%d' : '%s';
        }

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $data,
            ['batch_id' => $batchId],
            $formats,
            ['%s']
        );

        $this->logger?->info('Batch run finished.', array_merge(
            ['batch_id' => $batchId, 'status' => $status],
            array_intersect_key($stats, array_flip($allowed))
        ));
    }

    /**
     * Finalize a run's batches row by session_id (used by the inline member
     * /run path, which has no batch_id in hand). Computes the phase-1 tally
     * from the session's import summary and updates the row's status + stats.
     */
    public function finishRunBySession(string $sessionId, string $status): void
    {
        $stats = $this->tally($sessionId, new ImportStagingTable());

        global $wpdb;
        $allowed = [
            'phase1_succeeded', 'phase1_failed', 'phase1_needs_review',
            'phase2_total', 'phase2_succeeded', 'phase2_failed', 'phase2_needs_review',
        ];
        $data = ['status' => $status, 'finished_at' => \current_time('mysql', true)];
        $formats = ['%s', '%s'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $stats)) {
                $data[$k] = (int) $stats[$k];
                $formats[] = '%d';
            }
        }

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $data,
            ['session_id' => $sessionId],
            $formats,
            ['%s']
        );
    }

    /**
     * Resolve a batch_id to its session_id without loading the whole row.
     * Used by the admin clear-session handler, which only knows batch_id.
     */
    public function getSessionByBatch(string $batchId): ?string
    {
        global $wpdb;
        $sessionId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT session_id FROM {$wpdb->prefix}" . self::TABLE . ' WHERE batch_id = %s LIMIT 1',
                $batchId
            )
        );

        return (is_string($sessionId) && $sessionId !== '') ? $sessionId : null;
    }

    /**
     * Read a session's batch row (status + phase stats). The Review UI uses it
     * to decide whether the "Proceed to Phase 2" gate is armed (status
     * pending_review).
     *
     * @return array<string,mixed>|null The batch row (ARRAY_A), or null when none.
     */
    public function getBatchBySession(string $sessionId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE session_id = %s ORDER BY id DESC LIMIT 1',
                $sessionId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Kick off a batch run: insert the running batches row, then schedule the
     * first chunk. Returns the batch_id so the caller can track the run.
     */
    /**
     * Action Scheduler hook the Phase 2 (Slice 5) chunks fire. Separate from
     * CHUNK_HOOK so the two chains cannot share the same worker slot.
     */
    public const PHASE2_CHUNK_HOOK = 'wicket_import_process_phase2_chunk';

    public function startBatch(string $sessionId, string $csvFilename, int $createdByUserId, int $totalRows): string
    {
        // The run row inherits the session's flow from the upload row (the
        // upload endpoint is the only place the contract is chosen); callers
        // cannot re-tag a session by passing a different flow here.
        $flow = 'member';
        $uploadBatch = $this->getBatchBySession($sessionId);
        if ($uploadBatch !== null && ($uploadBatch['import_flow'] ?? '') === 'cheque') {
            $flow = 'cheque';
        }

        $batchId = $this->startRun($sessionId, $csvFilename, $createdByUserId, $totalRows, $flow);
        $this->scheduleNextChunk($batchId, $sessionId, 1);

        return $batchId;
    }

    /**
     * Start a Phase 2 run on the most recent batch for $sessionId (which was
     * left in pending_review by the Phase 1 chain). Transitions it to
     * phase2_running, writes phase2_started_at, schedules the first Phase 2
     * chunk, and returns the batch_id.
     *
     * @return string|null The batch_id on success, null when no pending_review
     *                     batch exists for the session.
     */
    public function startPhase2(string $sessionId, int $createdByUserId): ?string
    {
        global $wpdb;

        $batch = $this->getBatchBySession($sessionId);
        if ($batch === null || (string) ($batch['status'] ?? '') !== 'pending_review') {
            return null;
        }

        $batchId = (string) ($batch['batch_id'] ?? '');
        if ($batchId === '') {
            return null;
        }

        $total = (int) ($wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wicket_import_payment_records WHERE session_id = %s AND validation_status IN ('valid','warning')",
                $sessionId
            )
        ));

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'status' => 'phase2_running',
                'phase2_total' => $total,
                'phase2_started_at' => \current_time('mysql', true),
            ],
            ['batch_id' => $batchId],
            ['%s', '%d', '%s'],
            ['%s']
        );

        $this->scheduleNextPhase2Chunk($batchId, $sessionId, 1);

        return $batchId;
    }

    /**
     * Reset Phase 2 failed/needs_review rows for a session and re-schedule the
     * next chunk. Used by POST /import/batch/{id}/retry.
     */
    public function retryPhase2(string $sessionId, int $createdByUserId): ?string
    {
        global $wpdb;

        $batch = $this->getBatchBySession($sessionId);
        if ($batch === null || (string) ($batch['status'] ?? '') !== 'processing_complete') {
            return null;
        }

        $batchId = (string) ($batch['batch_id'] ?? '');
        if ($batchId === '') {
            return null;
        }

        (new PaymentStagingTable())->resetFailedForRetry($sessionId);

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'status' => 'phase2_running',
                'phase2_started_at' => \current_time('mysql', true),
                'finished_at' => null,
            ],
            ['batch_id' => $batchId],
            ['%s', '%s', '%s'],
            ['%s']
        );

        $this->scheduleNextPhase2Chunk($batchId, $sessionId, 1);

        return $batchId;
    }

    /**
     * Aggregate progress for a Phase 2 batch (status + tally by import_status).
     *
     * @return array{batch_id: string, status: string, counts: array<string,int>, total_rows: int}|null
     */
    public function getPhase2Progress(string $sessionId): ?array
    {
        $batch = $this->getBatchBySession($sessionId);
        if ($batch === null) {
            return null;
        }

        $summary = (new PaymentStagingTable())->getImportSummary($sessionId);
        $total = (int) ($batch['phase2_total'] ?? 0);

        return [
            'batch_id' => (string) ($batch['batch_id'] ?? ''),
            'status' => (string) ($batch['status'] ?? ''),
            'counts' => $summary,
            'total_rows' => $total,
        ];
    }

    /**
     * Process one chunk of a batch. The wicket_import_process_chunk AS callback.
     *
     * Claims up to WICKET_IMPORT_CHUNK_SIZE importable rows, runs processRow on
     * each (advancing it to a terminal status), then schedules the next chunk.
     * When claimChunk returns 0 the batch has no importable rows left and is
     * finished; a false return (DB error) fails the batch closed.
     */
    public function processChunk(string $batchId, string $sessionId, int $attempt = 1): void
    {
        // Reschedule cap (22.3): the chain must terminate within the expected
        // chunk count plus a small headroom. Past it, rows are not reaching a
        // terminal status; abort instead of looping forever.
        if ($attempt > $this->maxChunks($sessionId)) {
            $this->logger?->error('Reschedule cap exceeded; aborting the batch.', [
                'batch_id' => $batchId, 'session_id' => $sessionId, 'attempt' => $attempt,
            ]);
            $this->finishRun($batchId, 'failed', []);

            return;
        }

        $staging = new ImportStagingTable();
        $chunkSize = (int) apply_filters('wicket_import_chunk_size', WICKET_IMPORT_CHUNK_SIZE);
        $claimed = $staging->claimChunk($sessionId, $chunkSize);
        // Story 12: re-read the run's human-readable label from the batch row
        // (generated once in startRun) so every chunk stamps the SAME _batch_id.
        $batchLabel = (string) (($this->getBatchBySession($sessionId) ?: [])['batch_label'] ?? '');

        if ($claimed === false) {
            $this->logger?->error('claimChunk reported a DB error; failing the batch closed.', [
                'batch_id' => $batchId, 'session_id' => $sessionId,
            ]);
            $this->finishRun($batchId, 'failed', []);

            return;
        }

        if ($claimed === 0) {
            // No importable rows remain: Phase 1 is done. Land the batch in
            // pending_review so the Review UI arms the Phase 2 gate. The inline
            // member path keeps 'completed' (finishRunBySession); this is the
            // cheque/AS path only.
            $this->finishRun($batchId, 'pending_review', $this->tally($sessionId, $staging), [
                'phase1_completed_at' => \current_time('mysql', true),
                'conflicting_roles' => $this->conflictingRolesJson($sessionId, $staging),
            ]);

            return;
        }

        foreach ($staging->getProcessingBySession($sessionId) as $row) {
            $stagingId = (int) ($row['id'] ?? 0);
            try {
                // Story 12: the run's human-readable label rides on the row so
                // adapters can stamp _batch_id on orders/subscriptions without
                // widening the generic RowProcessor interface.
                $row['_batch_label'] = $batchLabel;
                $result = $this->processRow($row);
                $staging->updateImportResult($stagingId, $result->status, $result->message);
                if ($result->orderId !== null) {
                    $staging->updateOrderId($stagingId, $result->orderId);
                }
            } catch (\Throwable $e) {
                // Per-row isolation: a throw marks the row failed and the chunk continues.
                $this->logger?->error('processRow threw; marking the row failed and continuing.', [
                    'batch_id' => $batchId, 'row_id' => $stagingId, 'error' => $e->getMessage(),
                ]);
                $staging->updateImportResult($stagingId, 'failed', 'Chunk processing exception: ' . $e->getMessage());
            }
        }

        $this->scheduleNextChunk($batchId, $sessionId, $attempt);
    }

    /**
     * Delegate one row to the injected RowProcessor. Returns the RowProcessor's
     * outcome (status + optional order_id + message) for processChunk to apply.
     * With no RowProcessor configured the row fails closed (the engine was
     * constructed without an adapter).
     *
     * @param array<string,mixed> $row Staged row.
     */
    private function processRow(array $row): RowResult
    {
        if ($this->rowProcessor === null) {
            return RowResult::failed('No row processor configured for this batch.');
        }

        return $this->rowProcessor->process($row);
    }

    /**
     * Schedule the next chunk action via Action Scheduler. No-op when AS is not
     * available (unit tests, or a stack without WooCommerce Subscriptions), so
     * the engine is callable without a live scheduler; tests drive the chain
     * synchronously by calling processChunk directly.
     */
    private function scheduleNextChunk(string $batchId, string $sessionId, int $attempt): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        \as_schedule_single_action(time(), self::CHUNK_HOOK, [$batchId, $sessionId, $attempt + 1]);
    }

    /**
     * The maximum chunk actions a run may consume (22.3):
     * ceil(phase1_total / chunk size) + headroom.
     */
    private function maxChunks(string $sessionId): int
    {
        $batch = $this->getBatchBySession($sessionId);
        $total = (int) ($batch['phase1_total'] ?? 0);
        $chunkSize = (int) apply_filters('wicket_import_chunk_size', WICKET_IMPORT_CHUNK_SIZE);

        return (int) ceil($total / max(1, $chunkSize)) + self::RESCHEDULE_HEADROOM;
    }

    /**
     * The conflicting_roles JSON (22.4): the rows a human must reconcile
     * before Phase 2, as [{row_index, reason}] for the session's needs_review
     * rows (e.g. order created but subscription failed, D3 collision skips).
     * Null when none, so the column stays clean.
     */
    private function conflictingRolesJson(string $sessionId, ImportStagingTable $staging): ?string
    {
        $rows = $staging->getByImportStatus($sessionId, ['needs_review']);
        if ($rows === []) {
            return null;
        }

        $conflicts = array_values(array_map(static fn (array $row): array => [
            'row_index' => (int) ($row['row_index'] ?? 0),
            'reason' => (string) ($row['import_message'] ?? ''),
        ], $rows));

        return wp_json_encode($conflicts) ?: null;
    }

    /**
     * Process one Phase 2 chunk (Slice 5). The wicket_import_process_phase2_chunk
     * AS callback. Claims a bounded chunk of pending payment rows, runs the
     * PaymentMatcher per row, advances status, then self-schedules.
     */
    public function processPhase2Chunk(string $batchId, string $sessionId, int $attempt = 1): void
    {
        if ($attempt > $this->maxPhase2Chunks($sessionId)) {
            $this->logger?->error('Phase 2 reschedule cap exceeded; aborting.', [
                'batch_id' => $batchId, 'session_id' => $sessionId, 'attempt' => $attempt,
            ]);
            $this->finishRun($batchId, 'failed', []);

            return;
        }

        $staging = new PaymentStagingTable();
        $chunkSize = (int) apply_filters('wicket_import_chunk_size', WICKET_IMPORT_CHUNK_SIZE);
        $claimed = $staging->claimChunk($sessionId, $chunkSize);

        if ($claimed === false) {
            $this->logger?->error('Phase 2 claimChunk DB error; aborting.', [
                'batch_id' => $batchId, 'session_id' => $sessionId,
            ]);
            $this->finishRun($batchId, 'failed', []);

            return;
        }

        if ($claimed === 0) {
            // Phase 2 drained.
            $this->finishRun($batchId, 'processing_complete', [], [
                'phase2_completed_at' => \current_time('mysql', true),
            ]);

            return;
        }

        $matcher = new PaymentMatcher();
        $rows = $staging->getProcessingBySession($sessionId);
        foreach ($rows as $row) {
            $stagingId = (int) ($row['id'] ?? 0);
            try {
                $data = $this->decode($row['raw_data'] ?? null);
                // Order meta stores the human-readable batch_label (D-LOCKBOX-3 +
                // Story 12: the LABEL is the reporting key; the UUID is the join
                // key). The seam filter keys on _batch_id meta, so we pass the
                // label here.
                $batchLabel = (string) ($this->getBatchBySession($sessionId)['batch_label'] ?? '');
                $order = $matcher->resolveMatch($data, $batchLabel, $this->logger);
                if ($order === null) {
                    $staging->updateImportResult(
                        $stagingId,
                        'failed',
                        PaymentMatcher::reasonFor($data, 0, false)
                    );
                    continue;
                }
                $matched = $matcher->applyMatch($order, $data, $this->logger);
                $staging->updateMatch($stagingId, (int) $matched['order_id'], wp_json_encode($matched['subscription_ids']));
                $staging->updateImportResult($stagingId, 'imported', 'Payment matched; order processed.');
            } catch (\Throwable $e) {
                $this->logger?->error('Payment match threw; marking the row failed and continuing.', [
                    'batch_id' => $batchId, 'row_id' => $stagingId, 'error' => $e->getMessage(),
                ]);
                $staging->updateImportResult($stagingId, 'failed', 'Chunk processing exception: ' . $e->getMessage());
            }
        }

        $this->scheduleNextPhase2Chunk($batchId, $sessionId, $attempt);
    }

    private function scheduleNextPhase2Chunk(string $batchId, string $sessionId, int $attempt): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        \as_schedule_single_action(time(), self::PHASE2_CHUNK_HOOK, [$batchId, $sessionId, $attempt + 1]);
    }

    private function maxPhase2Chunks(string $sessionId): int
    {
        $batch = $this->getBatchBySession($sessionId);
        $total = (int) ($batch['phase2_total'] ?? 0);
        $chunkSize = (int) apply_filters('wicket_import_chunk_size', WICKET_IMPORT_CHUNK_SIZE);

        return (int) ceil($total / max(1, $chunkSize)) + self::RESCHEDULE_HEADROOM;
    }

    /**
     * Decode the raw_data JSON blob on a payment row. Centralized + forgiving
     * so a malformed blob never fatals the chunk.
     *
     * @return array<string,mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Map a session's import-status summary into finishRun's stats shape.
     *
     * @return array<string,int>
     */
    private function tally(string $sessionId, ImportStagingTable $staging): array
    {
        $s = $staging->getImportSummary($sessionId);

        return [
            'phase1_succeeded'    => ($s['imported'] ?? 0) + ($s['updated'] ?? 0),
            'phase1_failed'       => $s['failed'] ?? 0,
            'phase1_needs_review' => ($s['needs_review'] ?? 0) + ($s['email_conflict'] ?? 0) + ($s['skipped_active_membership'] ?? 0),
        ];
    }
}
