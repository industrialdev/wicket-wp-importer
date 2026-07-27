<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

use WicketImporter\Services\Logger;

/**
 * Generic subscriptions-state engine: manages the `wicket_import_batches` row
 * for a /run. G3 (the "batches table has a writer" gap) — the Import History
 * tab can now render real runs.
 *
 * The per-row processing loop remains the caller's responsibility
 * (`WicketImporter\BulkImport\ImportAdapter` for the OBA bulk path; the
 * cheque loop, once it lands, is a configured adapter under
 * `WicketImporter\BulkImport\Subscriptions\Cheque`). This class only owns
 * the batches-table state for the run: start (insert a running row) and
 * finish (update with the run's final stats).
 */
final class BatchProcessor
{
    /**
     * @var non-empty-string
     */
    private const TABLE = 'wicket_import_batches';

    public function __construct(
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
        $runAt = \current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}" . self::TABLE . " (batch_id, session_id, status, csv_filename, created_by_user_id, csv_row_count, phase1_total, run_at) VALUES (%s, %s, %s, %s, %d, %d, %d, %s)",
            $batchId,
            $sessionId,
            'running',
            $csvFilename,
            $createdByUserId,
            $totalRows,
            $totalRows,
            $runAt
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
}
