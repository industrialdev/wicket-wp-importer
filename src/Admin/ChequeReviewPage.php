<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

use WicketImporter\Support\Json;
use WicketImporter\Support\ReviewSuggester;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\WicketImporter as Plugin;

/**
 * Cheque Phase 1 Review UI (WWID-2026): the human gate between Phase 1 (bulk
 * create) and Phase 2 (reconciliation, D-LOCKBOX-4).
 *
 * Renders, for one cheque batch: a summary (total / processed / failed /
 * needs_review), the failed + needs_review rows in a WP_List_Table, an error
 * CSV export (AD14, via Support\CsvExporter), and a "Proceed to Phase 2"
 * button armed ONLY when the batch is in the pending_review state (the gate;
 * BatchProcessor lands Phase 1 completion there). The button's handler
 * (handleProceed) verifies the Phase 2 site gate, then arms Phase 2 via
 * BatchProcessor::startPhase2. Phase 2 reconciles the SAME records against
 * the orders Phase 1 created for them; there is no payment CSV upload.
 *
 * DISTINCT from the OBA validation screen: this is the cheque Phase 1 -> 2
 * gate, mounted under its own tab. OBA's flagged/valid + Upload/Restart screen
 * lives in ImportAdminPage's upload tab.
 *
 * Pure helpers (summaryCounts, isGateEnabled, shapeReviewRow, suggestedFix)
 * carry no WP calls so they unit-test directly; render() and handleProceed()
 * are the WP-bound surface.
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

        $this->renderPhase2Notice();

        if ($sessionId === '' || preg_match(self::SESSION_ID_PATTERN, $sessionId) !== 1) {
            // Bare tab: the cheque queue (pending batches first). No back
            // link here; the queue is the landing view.
            $this->renderEmptyState();
            echo '</div>';

            return;
        }

        $this->renderBackLink();

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

    /**
     * Surface a one-time admin notice when the Proceed-to-Phase-2 redirect
     * carried a status query var (started / not_ready / disabled_by_config).
     */
    private function renderPhase2Notice(): void
    {
        $status = (string) ($_GET['wicket_import_phase2'] ?? '');
        if ($status === '') {
            return;
        }
        $message = match ($status) {
            'started' => __('Phase 2 started. The reconciliation engine is processing records in the background.', 'wicket-wp-importer'),
            'not_ready' => __('Phase 2 could not start: no pending_review batch exists for this session yet.', 'wicket-wp-importer'),
            'disabled_by_config' => __('Phase 2 is disabled by site configuration. Enable it from the client theme to arm this gate.', 'wicket-wp-importer'),
            default => '',
        };
        if ($message === '') {
            return;
        }
        $class = $status === 'started' ? 'notice-success' : 'notice-warning';
        printf(
            '<div class="notice %s"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    private function renderEmptyState(): void
    {
        /*
         * No session picked: the tab doubles as the cheque queue. Pending
         * batches (the actionable ones) float to the top; the rest read as
         * history. Mirrors the Import History table columns so admins see one
         * consistent shape across tabs.
         */
        global $wpdb;

        $batchesTable = $wpdb->prefix . 'wicket_import_batches';
        $perPage = 20;
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $perPage;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$batchesTable} WHERE import_flow = 'cheque' AND status NOT IN ('cleared', 'abandoned')");
        $rows = $total > 0 ? $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, status, csv_filename, csv_row_count, phase1_succeeded, phase1_failed, phase1_needs_review, phase2_total, phase2_succeeded, created_at
             FROM {$batchesTable}
             WHERE import_flow = 'cheque'
               AND status NOT IN ('cleared', 'abandoned')
             ORDER BY (status = 'pending_review') DESC, (status = 'phase2_running') DESC, id DESC
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        )) : [];

        echo '<h2>' . esc_html__('Cheque batches', 'wicket-wp-importer') . '</h2>';
        echo '<p class="description">' . esc_html__('Open a batch to review its Phase 1 results and run payment matching.', 'wicket-wp-importer') . '</p>';

        if ($rows === []) {
            echo '<p>' . esc_html__('No cheque batches yet. Upload a cheque renewal CSV from the Upload tab to start one.', 'wicket-wp-importer') . '</p>';

            return;
        }

        $baseUrl = admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review');
        ?>
		<table class="wp-list-table widefat fixed striped wicket-importer-cheque-queue">
			<thead>
				<tr>
					<th><?php esc_html_e('Started', 'wicket-wp-importer'); ?></th>
					<th><?php esc_html_e('File', 'wicket-wp-importer'); ?></th>
					<th><?php esc_html_e('Rows', 'wicket-wp-importer'); ?></th>
					<th><?php esc_html_e('Progress', 'wicket-wp-importer'); ?></th>
					<th><?php esc_html_e('Actions', 'wicket-wp-importer'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row) :
                    $openUrl = $baseUrl . '&session_id=' . rawurlencode((string) $row->session_id);
                    $progress = ImportAdminPage::progressLabel([
                        'status'              => (string) $row->status,
                        'phase1_succeeded'    => (int) $row->phase1_succeeded,
                        'phase1_failed'       => (int) $row->phase1_failed,
                        'phase1_needs_review' => (int) $row->phase1_needs_review,
                        'phase2_total'        => (int) $row->phase2_total,
                        'phase2_succeeded'    => (int) $row->phase2_succeeded,
                    ]);
                    ?>
					<tr>
					<td><?php echo esc_html(mysql2date('Y-m-d H:i', $row->created_at)); ?></td>
					<td><a href="<?php echo esc_url($openUrl); ?>"><?php echo esc_html($row->csv_filename ?: '—'); ?></a></td>
					<td><?php echo esc_html((string) ((int) $row->csv_row_count)); ?></td>
					<td><?php echo esc_html($progress); ?></td>
					<td>
						<?php if ($row->status === 'pending_review') : ?>
							<a class="button button-small button-primary" href="<?php echo esc_url($openUrl); ?>"><?php esc_html_e('Start Phase 2', 'wicket-wp-importer'); ?></a>
						<?php else : ?>
							<a class="button button-small" href="<?php echo esc_url($openUrl); ?>"><?php esc_html_e('Open review', 'wicket-wp-importer'); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages > 1) {
            $pagination = paginate_links([
                'base'      => add_query_arg('paged', '%#%', $baseUrl),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $totalPages,
                'current'   => $paged,
                'type'      => 'plain',
            ]);
            if (is_string($pagination) && $pagination !== '') {
                echo '<div class="wicket-importer-history-pagination tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
            }
        }
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
        $available = self::isPhase2Available();
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

        // Always explain the gate state. Two reasons it can be disabled:
        //   1. Batch is not in pending_review (e.g. running, completed).
        //   2. The site has not enabled Phase 2 via the wicket_import_phase2_enabled
        //      filter (Phase 2 ships off by default; clients opt in).
        echo '<p class="description">';
        if (!$available) {
            echo esc_html__('Phase 2 (payment matching) is disabled by site configuration; enable it from the client theme to arm this gate.', 'wicket-wp-importer');
        } elseif (!$enabled) {
            echo esc_html(sprintf(
                /* translators: %s: batch status. */
                __('The gate is armed only when the batch is pending_review. This batch is: %s.', 'wicket-wp-importer'),
                $status === '' ? '—' : $status
            ));
        } else {
            echo esc_html__('The gate is armed: a fresh batch in pending_review can be moved to Phase 2.', 'wicket-wp-importer');
        }
        echo '</p>';
        echo '</div>';
    }

    private function renderExportLink(string $sessionId): void
    {
        $url = rest_url('/wicket/v1/import/session/' . $sessionId . '/error-csv?context=cheque');
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
     * Handle the Proceed-to-Phase-2 POST (Slice 5). Fail-closed gates first:
     * capability, nonce, and the wicket_import_phase2_enabled site gate
     * (Phase 2 ships off by default; mirrors the REST route gate). Then arms
     * the Phase 2 chain via BatchProcessor::startPhase2 and redirects back
     * to the review with the outcome (started / not_ready / disabled).
     */
    public static function handleProceed(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'wicket-wp-importer'), 403);
        }

        check_admin_referer('wicket_import_cheque_proceed', '_wpnonce');

        $sessionId = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $redirect = admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review&session_id=' . rawurlencode($sessionId));

        // Phase 2 ships off by default in core (mechanism/policy). Refuse the
        // proceed if the site hasn't enabled it via the wicket_import_phase2_enabled
        // filter, mirroring the REST route gate.
        if (!self::isPhase2Available()) {
            wp_safe_redirect(add_query_arg('wicket_import_phase2', 'disabled_by_config', $redirect));
            exit;
        }

        // Arm the Phase 2 chain directly (no REST roundtrip; admin-post already
        // verified capability + nonce). Returns null when no pending_review batch
        // exists for the session (mirrors the REST 409 path).
        $batchId = \WicketImporter\WicketImporter::get_instance()->BatchProcessor()->startPhase2(
            $sessionId,
            get_current_user_id()
        );

        if ($batchId === null) {
            wp_safe_redirect(add_query_arg('wicket_import_phase2', 'not_ready', $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg(['wicket_import_phase2' => 'started', 'batch_id' => $batchId], $redirect));
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
     * The Proceed-to-Phase-2 gate is armed only when the batch is in
     * pending_review AND the client has enabled Phase 2 for this site.
     *
     * Phase 2 is OFF by default in core (mechanism/policy: the matching
     * engine lives in the importer, but whether the importer OFFERS it to
     * a given client is a per-site decision). Each client enables it by
     * answering the wicket_import_phase2_enabled filter from its child
     * theme; a site that never opts in never sees the gate or the
     * /run-phase2 endpoint working.
     *
     * @param array<string,mixed>|null $batch
     */
    public static function isGateEnabled(?array $batch): bool
    {
        if ($batch === null || ($batch['status'] ?? null) !== 'pending_review') {
            return false;
        }
        return (bool) apply_filters('wicket_import_phase2_enabled', false);
    }

    /**
     * Whether the importer offers Phase 2 on this site at all. Independent of
     * the per-batch pending_review gate so the admin UI can show "Phase 2
     * disabled by site configuration" before the batch even lands.
     */
    public static function isPhase2Available(): bool
    {
        return (bool) apply_filters('wicket_import_phase2_enabled', false);
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
