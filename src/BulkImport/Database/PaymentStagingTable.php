<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Database;

/**
 * Reads/writes the wicket_import_payment_records table (Phase 2 / Slice 5).
 *
 * Mirrors ImportStagingTable's Phase 1 surface (claimChunk, getByImportStatus,
 * updateImportResult, tally, etc.) so the BatchProcessor Phase 2 chain can
 * use a parallel chunking mechanism with the same single-chain model + the
 * threshold-guarded stale claim protection.
 */
final class PaymentStagingTable
{
    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wicket_import_payment_records';
    }

    /**
     * Insert a batch of payment rows for a session.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function insertBatch(array $rows, string $sessionId): void
    {
        global $wpdb;
        if ($rows === []) {
            return;
        }

        $values = [];
        $placeholders = [];
        foreach ($rows as $row) {
            $placeholders[] = '(%s, %d, %s, %s, %s, %s, %s)';
            $values[] = $sessionId;
            $values[] = (int) ($row['row_index'] ?? 0);
            $values[] = $this->encodeField($row['raw_data'] ?? null);
            $values[] = (string) ($row['validation_status'] ?? 'valid');
            $values[] = (string) ($row['validation_message'] ?? '');
            $values[] = (string) ($row['import_status'] ?? 'pending');
            $values[] = (string) ($row['import_message'] ?? '');
        }

        $sql = "INSERT INTO {$this->table_name} (session_id, row_index, raw_data, validation_status, validation_message, import_status, import_message) VALUES "
            . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, $values));
    }

    /**
     * Claim a bounded chunk of importable payment rows (single-chain,
     * ORDER BY row_index + LIMIT). Marks them 'processing' and stamps the
     * claim time so expireStaleClaims can guard against runaway workers.
     *
     * @return int|false Rows claimed (0 = nothing left), or false on DB error.
     */
    public function claimChunk(string $sessionId, int $limit): int|false
    {
        global $wpdb;
        $limit = max(1, $limit);

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name}
                 SET import_status = 'processing', processing_claimed_at = %s
                 WHERE session_id = %s
                   AND validation_status IN ('valid', 'warning')
                   AND import_status = 'pending'
                 ORDER BY row_index ASC
                 LIMIT %d",
                $this->utcNowMysql(),
                $sessionId,
                $limit
            )
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getProcessingBySession(string $sessionId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND import_status = 'processing' ORDER BY row_index ASC",
                $sessionId
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<string> $statuses
     *
     * @return list<array<string,mixed>>
     */
    public function getByImportStatus(string $sessionId, array $statuses): array
    {
        global $wpdb;
        $statuses = array_values(array_filter($statuses, 'is_string'));
        if ($statuses === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE session_id = %s AND import_status IN ({$placeholders}) ORDER BY row_index ASC, id ASC",
                $sessionId,
                ...$statuses
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    public function updateImportResult(int $id, string $importStatus, ?string $importMessage = null): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['import_status' => $importStatus, 'import_message' => (string) ($importMessage ?? ''), 'processed_at' => $this->utcNowMysql()],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function updateMatch(int $id, int $orderId, ?string $subscriptionIds = null): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            [
                'matched_order_id' => $orderId,
                'matched_subscription_ids' => $subscriptionIds,
                'processed_at' => $this->utcNowMysql(),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Reset failed/needs_review rows in a session to 'pending' so a retry can
     * re-process them. Returns the number reset.
     */
    public function resetFailedForRetry(string $sessionId): int
    {
        global $wpdb;
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'pending', processing_claimed_at = NULL, processed_at = NULL WHERE session_id = %s AND import_status IN ('failed', 'needs_review')",
                $sessionId
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * @return array<string,int>
     */
    public function getImportSummary(string $sessionId): array
    {
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT import_status, COUNT(*) as count FROM {$this->table_name} WHERE session_id = %s GROUP BY import_status",
                $sessionId
            ),
            ARRAY_A
        );

        $summary = [
            'pending' => 0,
            'imported' => 0,
            'failed' => 0,
            'needs_review' => 0,
            'processing' => 0,
        ];
        foreach ($results as $row) {
            $key = $row['import_status'];
            if (array_key_exists($key, $summary)) {
                $summary[$key] = (int) $row['count'];
            }
        }

        return $summary;
    }

    /**
     * Threshold-guarded stale claim reclaim (Slice 4 era, carried into Phase 2).
     * Rows claimed without a timestamp, or older than $threshold seconds, are
     * reclaimed to 'needs_review'. Returns the count reclaimed.
     */
    public function expireStaleClaims(int $threshold = 1800): int
    {
        global $wpdb;
        $threshold = max(60, $threshold);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $threshold);

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET import_status = 'needs_review', import_message = %s, processed_at = %s WHERE import_status = 'processing' AND (processing_claimed_at IS NULL OR processing_claimed_at < %s)",
                'Reclaimed after an interrupted run.',
                $this->utcNowMysql(),
                $cutoff
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    private function encodeField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $encoded = wp_json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    private function utcNowMysql(): string
    {
        if (function_exists('wicket_time_get_utc_datetime')) {
            return wicket_time_get_utc_datetime('now')->format('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
