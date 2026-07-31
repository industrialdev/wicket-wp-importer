<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\BulkImport\Database\ImportStagingTable;
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
     * WicketImporter::setup(); the callback is processChunk().
     */
    public const CHUNK_HOOK = 'wicket_import_process_chunk';

    public function __construct(
        private readonly ?RowProcessor $rowProcessor = null,
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Insert a `wicket_import_batches` row for the run start. Returns the new
     * batch_id (a UUID) so the caller can pass it to finishRun.
     */
    public function startRun(string $sessionId, string $csvFilename, int $createdByUserId, int $totalRows): string
    {
        global $wpdb;

        $batchId = \wp_generate_uuid4();

        // created_at auto-defaults to CURRENT_TIMESTAMP; the History tab's
        // "Started" column reads it, so no explicit run-start column is needed.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}" . self::TABLE . " (batch_id, session_id, status, csv_filename, created_by_user_id, csv_row_count, phase1_total) VALUES (%s, %s, %s, %s, %d, %d, %d)",
            $batchId,
            $sessionId,
            'running',
            $csvFilename,
            $createdByUserId,
            $totalRows,
            $totalRows
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
    public function finishRun(string $batchId, string $status, array $stats): void
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
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE session_id = %s ORDER BY id DESC LIMIT 1",
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
    public function startBatch(string $sessionId, string $csvFilename, int $createdByUserId, int $totalRows): string
    {
        $batchId = $this->startRun($sessionId, $csvFilename, $createdByUserId, $totalRows);
        $this->scheduleNextChunk($batchId, $sessionId);

        return $batchId;
    }

    /**
     * Process one chunk of a batch. The wicket_import_process_chunk AS callback.
     *
     * Claims up to WICKET_IMPORT_CHUNK_SIZE importable rows, runs processRow on
     * each (advancing it to a terminal status), then schedules the next chunk.
     * When claimChunk returns 0 the batch has no importable rows left and is
     * finished; a false return (DB error) fails the batch closed.
     */
    public function processChunk(string $batchId, string $sessionId): void
    {
        $staging = new ImportStagingTable();
        $chunkSize = (int) apply_filters('wicket_import_chunk_size', WICKET_IMPORT_CHUNK_SIZE);
        $claimed = $staging->claimChunk($sessionId, $chunkSize);

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
            $this->finishRun($batchId, 'pending_review', $this->tally($sessionId, $staging));

            return;
        }

        foreach ($staging->getProcessingBySession($sessionId) as $row) {
            $stagingId = (int) ($row['id'] ?? 0);
            try {
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

        $this->scheduleNextChunk($batchId, $sessionId);
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
    private function scheduleNextChunk(string $batchId, string $sessionId): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        \as_schedule_single_action(time(), self::CHUNK_HOOK, [$batchId, $sessionId]);
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
