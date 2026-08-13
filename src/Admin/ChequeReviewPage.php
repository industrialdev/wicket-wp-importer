<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

use WicketImporter\Support\Json;
use WicketImporter\Support\ReviewSuggester;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\WicketImporter as Plugin;

/**
 * Cheque Phase 1 Review UI (WWID-2026): the human gate between Phase 1 (bulk
 * create) and Phase 2 (payment matching, Slice 5).
 *
 * Renders, for one cheque batch: a summary (total / processed / failed /
 * needs_review), a divergence warning, the failed + needs_review rows in a
 * WP_List_Table, an error CSV export (AD14, via Support\CsvExporter), and a
 * "Proceed to Phase 2" button armed ONLY when the batch is in the
 * pending_review state (the gate; BatchProcessor lands Phase 1 completion
 * there). The button's handler is a stub: Phase 2 is unbuilt, so it redirects
 * back with a notice instead of mutating anything.
 *
 * DISTINCT from the OBA validation screen: this is the cheque Phase 1 -> 2
 * gate, mounted under its own tab. OBA's flagged/valid + Upload/Restart screen
 * lives in ImportAdminPage's upload tab.
 *
 * Pure helpers (summaryCounts, isGateEnabled, divergenceCount, shapeReviewRow,
 * suggestedFix) carry no WP calls so they unit-test directly; render() and
 * handleProceed() are the WP-bound surface.
 */
final class ChequeReviewPage
{
    use SecuresRequests;

    /**
     * UUID v4 (36 chars). Same pattern the REST endpoints use.
     */
    private const SESSION_ID_PATTERN = '/^[0-9a-fA-F-]{36}$/';

    /**
     * Render the review for the session_id in $_GET. Mounted as a tab callback.
     */
    public function render(): void
    {
        $this->requireCapability();

        $sessionId = sanitize_text_field(wp_unslash($_GET['session_id'] ?? ''));

        echo '<div class="wicket-importer" data-screen="cheque-review">';
        $this->renderBackLink();

        if ($sessionId === '' || preg_match(self::SESSION_ID_PATTERN, $sessionId) !== 1) {
            $this->renderEmptyState();
            echo '</div>';

            return;
        }

        $plugin = Plugin::get_instance();
        $batch = $plugin->BatchProcessor()->getBatchBySession($sessionId);

        if ($batch === null) {
            echo '<p>' . esc_html__('No cheque batch found for this session.', 'wicket-wp-importer') . '</p>';
            echo '</div>';

            return;
        }

        $summary = $plugin->StagingTable()->getImportSummary($sessionId);
        $rows = $plugin->StagingTable()->getByImportStatus($sessionId, ['failed', 'needs_review', 'email_conflict', 'skipped_active_membership']);

        $this->renderSummary($summary, $batch);
        $this->renderDivergenceNotice($rows);
        $this->renderLogTable($rows);
        $this->renderGate($batch, $sessionId);
        $this->renderExportLink($sessionId);

        echo '</div>';
    }

    // ---------------------------------------------------------------------
    // Render sections (WP-bound; not unit-tested).
    // ---------------------------------------------------------------------

    private function renderBackLink(): void
    {
        $historyUrl = admin_url('admin.php?page=wicket-wp-importer&tab=history');
        echo '<p><a href="' . esc_url($historyUrl) . '">&larr; '
            . esc_html__('Back to Import History', 'wicket-wp-importer')
            . '</a></p>';
    }

    private function renderEmptyState(): void
    {
        echo '<p>' . esc_html__('Select a cheque batch from Import History to review its Phase 1 results.', 'wicket-wp-importer') . '</p>';
    }

    private function renderSummary(array $summary, array $batch): void
    {
        $c = self::summaryCounts($summary, $batch);
        $rows = [
            __('Status', 'wicket-wp-importer') => esc_html((string) ($batch['status'] ?? '')),
            __('Total rows', 'wicket-wp-importer') => esc_html((string) $c['total']),
            __('Processed', 'wicket-wp-importer') => esc_html((string) $c['processed']),
            __('Failed', 'wicket-wp-importer') => '<span class="review-count--failed">' . esc_html((string) $c['failed']) . '</span>',
            __('Needs review', 'wicket-wp-importer') => '<span class="review-count--review">' . esc_html((string) $c['needs_review']) . '</span>',
        ];

        echo '<h2>' . esc_html__('Phase 1 review', 'wicket-wp-importer') . '</h2>';
        echo '<table class="widefat striped wicket-importer-review-summary"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderDivergenceNotice(array $rows): void
    {
        $divergent = self::divergenceCount($rows);
        if ($divergent === 0) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %d: divergent row count. */
                _n(
                    '%d row failed the total divergence check (CSV total differs from the calculated total).',
                    '%d rows failed the total divergence check (CSV total differs from the calculated total).',
                    $divergent,
                    'wicket-wp-importer'
                ),
                $divergent
            ))
        );
    }

    private function renderLogTable(array $rows): void
    {
        $table = new ReviewLogTable();
        $table->setRows(array_map([self::class, 'shapeReviewRow'], $rows));
        $table->prepare_items();

        echo '<h2>' . esc_html__('Failed and needs-review rows', 'wicket-wp-importer') . '</h2>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No failed or needs-review rows in this batch.', 'wicket-wp-importer') . '</p>';

            return;
        }

        $table->display();
    }

    private function renderGate(array $batch, string $sessionId): void
    {
        $enabled = self::isGateEnabled($batch);
        $status = (string) ($batch['status'] ?? '');

        echo '<div class="wicket-importer-review-gate">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wicket_import_cheque_proceed">';
        echo '<input type="hidden" name="session_id" value="' . esc_attr($sessionId) . '">';
        wp_nonce_field('wicket_import_cheque_proceed', '_wpnonce');

        printf(
            '<button type="submit" class="button button-primary"%s>%s</button>',
            $enabled ? '' : ' disabled',
            esc_html__('Proceed to Phase 2', 'wicket-wp-importer')
        );
        echo '</form>';

        // Always explain: Phase 2 is unbuilt (Slice 5), and the gate state.
        echo '<p class="description">';
        if (!$enabled) {
            echo esc_html(sprintf(
                /* translators: %s: batch status. */
                __('The gate is armed only when the batch is pending_review. This batch is: %s.', 'wicket-wp-importer'),
                $status === '' ? '—' : $status
            )) . ' ';
        }
        echo esc_html__('Phase 2 (payment matching) is not yet available; it ships with Slice 5.', 'wicket-wp-importer');
        echo '</p>';
        echo '</div>';
    }

    private function renderExportLink(string $sessionId): void
    {
        $url = rest_url('/wicket/v1/import/session/' . $sessionId . '/error-csv');
        // Anchors cannot send the X-WP-Nonce header; bake it into the URL.
        $nonceUrl = wp_nonce_url($url, 'wp_rest', '_wpnonce');

        echo '<p><a class="button" href="' . esc_url($nonceUrl) . '">'
            . esc_html__('Export errors (CSV)', 'wicket-wp-importer')
            . '</a></p>';
    }

    // ---------------------------------------------------------------------
    // admin-post handler: the "Proceed to Phase 2" form target.
    // ---------------------------------------------------------------------

    /**
     * Handle the Proceed-to-Phase-2 POST. Phase 2 is unbuilt (Slice 5), so this
     * is a fail-closed stub: verify nonce + capability, then redirect back to
     * the review with a notice. Never mutates the batch. Slice 5 replaces the
     * body with the real Phase 2 trigger.
     */
    public static function handleProceed(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'wicket-wp-importer'), 403);
        }

        check_admin_referer('wicket_import_cheque_proceed', '_wpnonce');

        $sessionId = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $redirect = admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review&session_id=' . rawurlencode($sessionId));

        // Slice 5 will trigger Phase 2 here; until then, surface the gap.
        wp_safe_redirect(add_query_arg('wicket_import_phase2', 'unavailable', $redirect));
        exit;
    }

    // ---------------------------------------------------------------------
    // Pure helpers (no WP calls; unit-tested directly).
    // ---------------------------------------------------------------------

    /**
     * Map an import summary + batch row into the four review counts.
     *
     * @param array<string,int>    $summary getImportSummary() output.
     * @param array<string,mixed>  $batch   The batches row.
     * @return array{total:int,processed:int,failed:int,needs_review:int}
     */
    public static function summaryCounts(array $summary, array $batch): array
    {
        $processed = (int) ($summary['imported'] ?? 0) + (int) ($summary['updated'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        // WWID-2200 (sibling): mirror BatchProcessor::tally, which folds
        // email_conflict and skipped_active_membership into the stored
        // phase1_needs_review column. Without this, a cheque batch with an
        // email-conflict row undercounted "needs review" and (in the total
        // fallback below) undercounted "total", making the conflict invisible
        // on this screen just like on the OBA confirmation summary.
        $needsReview = (int) ($summary['needs_review'] ?? 0)
            + (int) ($summary['email_conflict'] ?? 0)
            + (int) ($summary['skipped_active_membership'] ?? 0);
        $total = (int) ($batch['phase1_total'] ?? $batch['csv_row_count'] ?? 0);
        if ($total === 0) {
            // Fallback when the batches row has no row count yet.
            $total = $processed + $failed + $needsReview + (int) ($summary['pending'] ?? 0) + (int) ($summary['processing'] ?? 0);
        }

        return [
            'total'        => $total,
            'processed'    => $processed,
            'failed'       => $failed,
            'needs_review' => $needsReview,
        ];
    }

    /**
     * The Proceed-to-Phase-2 gate is armed only for a pending_review batch.
     *
     * @param array<string,mixed>|null $batch
     */
    public static function isGateEnabled(?array $batch): bool
    {
        return $batch !== null && ($batch['status'] ?? null) === 'pending_review';
    }

    /**
     * Count rows whose failure reason mentions a total divergence.
     *
     * @param list<array<string,mixed>> $rows Staged rows.
     */
    public static function divergenceCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (stripos((string) ($row['import_message'] ?? ''), 'diverg') !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Shape a staged row into the presentation array ReviewLogTable renders.
     *
     * @param array<string,mixed> $row Staged row.
     * @return array<string,mixed>
     */
    public static function shapeReviewRow(array $row): array
    {
        $data = Json::decodeArray($row['raw_data'] ?? null);
        $orderId = $row['order_id'] ?? null;
        $status = (string) ($row['import_status'] ?? '');
        $reason = (string) ($row['import_message'] ?? '');

        return [
            'line'     => (string) (((int) ($row['row_index'] ?? 0)) + 2),
            'data'     => self::renderDataCell($data),
            'status'   => $status,
            'reason'   => $reason,
            'order_id' => ($orderId !== null && $orderId !== '') ? (string) $orderId : '',
            'fix'      => ReviewSuggester::suggestedFix($status, $reason),
        ];
    }

    /**
     * Render the decoded row data as a compact "key: value; ..." string.
     * Generic (AD1): no client identifier is hardcoded; whatever the CSV
     * carried (order_total, check_id, a client's bar_id) shows here.
     *
     * @param array<string,mixed> $data
     */
    private static function renderDataCell(array $data): string
    {
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $pairs[] = $key . ': ' . $value;
            }
        }

        return implode('; ', $pairs);
    }
}
