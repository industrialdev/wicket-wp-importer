<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

use HyperFields\HyperFields;
use WicketImporter\Support\ColumnOrder;
use WicketImporter\Support\CsvStorage;
use WicketImporter\Support\Json;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\ValueObjects\ValidationResult;
use WicketImporter\WicketImporter as Plugin;

/**
 * Admin page for the importer (Task 7).
 *
 * Registered as a submenu under the Wicket parent (parent_slug: wicket-settings)
 * per AD9. Hosted by a HyperFields\AdminPage instance, which provides the
 * sticky white header (H1), the URL-based nav tabs (Upload / Import History)
 * and notice relocation that matches the rest of the Wicket/HyperFields admin.
 *
 * Tabs are pure navigation (?tab=upload|history). The upload tab body also
 * dispatches mid-flow wizard screens selected by $_GET['screen']:
 *
 *   upload        CSV dropzone (Task 8) + individual form (Task 11) + meta slots
 *   validation    Summary bar + flagged rows table + Proceed/Restart (Task 9)
 *   confirmation  Results summary + per-row table + meta re-fire (Task 10)
 *
 * Validation and confirmation are wizard deep-links (?screen=...&session_id=...)
 * that render inside the upload tab (Upload stays the active tab). No submenu
 * pollution under the Wicket parent.
 *
 * Meta slots come from the wicket_import_upload_page_meta filter and render on
 * BOTH the upload screen and the confirmation screen (re-fired so post-import
 * state is reflected, e.g. OBA's "Next Bar ID").
 *
 * @see atlas/packages/wicket-wp-importer/architecture.md (REST endpoints documented there)
 */
class ImportAdminPage
{
    use SecuresRequests;

    public const SCREEN_UPLOAD = 'upload';
    public const SCREEN_VALIDATION = 'validation';
    public const SCREEN_CONFIRMATION = 'confirmation';

    /**
     * Screens the router accepts. Anything else falls back to upload.
     * History is intentionally absent: it's a tab (?tab=history), not a screen.
     */
    private const ALLOWED_SCREENS = [
        self::SCREEN_UPLOAD,
        self::SCREEN_VALIDATION,
        self::SCREEN_CONFIRMATION,
    ];

    /**
     * UUID v4 (36 chars). Same pattern the REST endpoints use.
     */
    private const SESSION_ID_PATTERN = '/^[0-9a-fA-F-]{36}$/';

    public function __construct()
    {
        // Priority 21 (after the WPSettings lib's 'Settings' submenu at p20) so that:
        //   1. The parent 'wicket-settings' top-level menu (Wicket_Admin, admin_menu
        //      p10) has run before us - prevents the hookname-resolution race that
        //      caused 'Sorry, you are not allowed to access this page' (registration
        //      vs access-check resolved different page_type prefixes when the parent
        //      wasn't in $admin_page_hooks yet).
        //   2. The Settings submenu registers first. WP auto-inserts a duplicate
        //      parent link ('Wicket') only on the FIRST add_submenu_page call whose
        //      menu_slug differs from the parent slug. Settings uses slug===parent
        //      (no auto-insert), and by the time we register, the parent already
        //      has a submenu - so the duplicate 'Wicket' child never appears, and
        //      the order is Settings then Importer.
        add_action('admin_menu', [$this, 'registerMenu'], 21);
        // Escape hatch for a stuck import session (see handleClearSession).
        add_action('admin_post_wicket_import_clear_session', [self::class, 'handleClearSession']);
    }

    /**
     * Register the admin menu page (Task 7.3) via a HyperFields AdminPage host.
     *
     * Under the Wicket parent (wicket-settings) per AD9. manage_options gated.
     * The host owns the sticky header + nav tabs; the tab callbacks render the
     * importer content (upload callback also owns the wizard screen dispatch).
     */
    public function registerMenu(): void
    {
        $page = HyperFields::makeAdminPage(__('Wicket Importer', 'wicket-wp-importer'), 'wicket-wp-importer')
            ->setMenuTitle(__('Importer', 'wicket-wp-importer'))
            ->setParentSlug('wicket-settings')
            ->setCapability('manage_options')
            ->addTab('upload', __('Upload', 'wicket-wp-importer'), function (): void {
                $this->renderUploadTab();
            })
            ->addTab('history', __('Import History', 'wicket-wp-importer'), function (): void {
                $this->renderHistoryTab();
            });

        /*
         * The Cheque Review tab is the lockbox workspace. Only sites that
         * opted into payment matching (wicket_import_phase2_enabled, the same
         * gate as the Import type selector) get it; member-only sites never
         * see the empty cheque surface. Opened bare, the tab lists cheque
         * batches (pending first) instead of a dead-end message.
         */
        if (ChequeReviewPage::isPhase2Available()) {
            $page = $page->addTab('cheque-review', __('Cheque Review', 'wicket-wp-importer'), function (): void {
                (new ChequeReviewPage())->render();
            });
        }

        $page->register();
    }

    /**
     * Upload tab body. Hosts the screen dispatcher (?screen= validation/
     * confirmation are wizard takeovers; default is the upload screen).
     * Upload is always the active tab here regardless of ?screen.
     */
    private function renderUploadTab(): void
    {
        $this->requireCapability();

        $screen = $this->currentScreen();
        $sessionId = $this->currentSessionId();

        // admin.js scopes the whole page to .wicket-importer; keep it.
        echo '<div class="wicket-importer" data-screen="' . esc_attr($screen) . '">';
        $this->renderScreenNotices();

        if (self::SCREEN_VALIDATION === $screen) {
            $this->renderValidationScreen($sessionId);
        } elseif (self::SCREEN_CONFIRMATION === $screen) {
            $this->renderConfirmationScreen($sessionId);
        } else {
            $this->renderUploadScreen($this->currentFlow());
        }

        echo '</div>';
    }

    /**
     * History tab body. Delegates to the existing history screen router
     * (list view vs per-batch detail). Kept inside a .wicket-importer scope
     * for admin.js + admin.css consistency with the upload tab.
     */
    private function renderHistoryTab(): void
    {
        $this->requireCapability();

        echo '<div class="wicket-importer" data-screen="history">';
        $this->renderHistoryScreen();
        echo '</div>';
    }

    // ---------------------------------------------------------------------
    // Screens
    // ---------------------------------------------------------------------

    /**
     * Upload screen (Task 7.2).
     *
     * Layout: Upload Type toggle (CSV / Manual) at top, then conditional UI.
     * Renders the wicket_import_upload_page_meta slots above the toggle so
     * extension-provided context (e.g. OBA's "Next Bar ID") is visible.
     *
     * Two container stubs follow:
     *   #wicket-import-csv       Task 8 fills this (drag-drop + fetch POST)
     *   #wicket-import-manual    Task 11 fills this (individual form fields)
     *
     * The toggle is client-side show/hide (no page reload); admin.js binds it.
     * Default visible section is CSV.
     */
    private function renderUploadScreen(string $flow = ''): void
    {
        /*
         * Manual (individual) entry mode. Defaults on for backwards
         * compatibility. A child theme returns false to hide the manual
         * upload option and its form, leaving CSV as the only input method.
         * admin.js returns early when the toggle radios are absent, so CSV
         * stays visible with no dead binding.
         */
        $manualEnabled = (bool) apply_filters('wicket_import_manual_entry_enabled', true);

        /*
         * Cheque/lockbox import type (spec Story 1: "upload a CSV through the
         * admin UI to initiate bulk order creation"). Rendered only when the
         * site answers wicket_import_phase2_enabled — the same client opt-in
         * the payment-matching flow uses — so member-only sites never see it.
         * The radio switches the upload endpoint (member /upload vs cheque
         * /cheque/upload) and keeps the wizard on the cheque flow (?flow=cheque).
         */
        $chequeAvailable = ChequeReviewPage::isPhase2Available();

        /*
         * Stuck-session escape hatch. When a prior upload left pending rows
         * behind (run never started or crashed pre-claim), every new upload
         * 409s until the 24h TTL expires, and the only message pointed at a
         * DELETE endpoint no screen exposed. Surface the blocker here with a
         * link to its History detail, where "Clear session" lives.
         */
        $blockingSession = Plugin::get_instance()->StagingTable()->hasActiveSession();
        if ($blockingSession !== null) {
            $batch = Plugin::get_instance()->BatchProcessor()->getBatchBySession($blockingSession);
            $manageUrl = $this->historyScreenUrl();
            if ($batch !== null) {
                $manageUrl = add_query_arg('batch_id', $batch['batch_id'], $manageUrl);
            }
            ?>
			<div class="notice notice-warning inline wicket-importer-blocked-session">
				<p>
					<?php esc_html_e('An import session is already in progress. New uploads are blocked until it is completed or cleared.', 'wicket-wp-importer'); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url($manageUrl); ?>">
						<?php esc_html_e('Review or clear this session', 'wicket-wp-importer'); ?>
					</a>
				</p>
			</div>
			<?php
        }

        $this->renderPageMetaSlots();
        ?>
		<?php if ($manualEnabled): ?>
		<div class="wicket-importer-upload-type">
			<label class="wicket-importer-toggle">
				<input type="radio" name="wicket_import_upload_type" value="csv" checked>
				<span><?php esc_html_e('CSV File', 'wicket-wp-importer'); ?></span>
			</label>
			<label class="wicket-importer-toggle">
				<input type="radio" name="wicket_import_upload_type" value="manual">
				<span><?php esc_html_e('Manual Entry', 'wicket-wp-importer'); ?></span>
			</label>
		</div>
		<?php endif; ?>

		<?php if ($chequeAvailable): ?>
		<fieldset class="wicket-importer-import-type wicket-importer-delimiter">
			<legend><?php esc_html_e('Import type', 'wicket-wp-importer'); ?></legend>
			<label class="wicket-importer-toggle">
				<input type="radio" name="wicket_import_import_type" value="member" <?php checked($flow !== 'cheque'); ?>>
				<span><?php esc_html_e('Member import', 'wicket-wp-importer'); ?></span>
			</label>
			<label class="wicket-importer-toggle">
				<input type="radio" name="wicket_import_import_type" value="cheque" <?php checked($flow, 'cheque'); ?>>
				<span><?php esc_html_e('Cheque renewals (lockbox)', 'wicket-wp-importer'); ?></span>
			</label>
			<p class="description"><?php esc_html_e('Cheque renewals stage On Hold orders in bulk and match the lockbox payment file afterwards.', 'wicket-wp-importer'); ?></p>
		</fieldset>
		<?php endif; ?>

		<div id="wicket-import-csv" class="wicket-importer-upload-section">
			<?php
            $rest = $this->restBase();
        /*
         * Worked-example download (optional). Default empty: only the live
         * Download CSV template button shows. A child theme sets this to a
         * URL of a sample CSV with realistic data rows so admins see how
         * to fill the file. The live button stays the source of truth.
         */
        $sampleUrl = (string) apply_filters('wicket_import_sample_template_url', '');
        /*
         * CSV upload section (Task 8). Drag-and-drop zone + click-to-browse +
         * file preview, bound by admin.js. The confirm button POSTs to
         * /import/upload via fetch and redirects to the validation screen.
         */
        $defaultDelimiter = (string) apply_filters('wicket_import_csv_delimiter', ',');
        $defaultDelimiter = in_array($defaultDelimiter, [',', ';'], true) ? $defaultDelimiter : ',';
        ?>
			<fieldset class="wicket-importer-delimiter">
				<legend><?php esc_html_e('CSV delimiter', 'wicket-wp-importer'); ?></legend>
				<label class="wicket-importer-toggle">
					<input type="radio" name="wicket_import_csv_delimiter" value="," <?php checked($defaultDelimiter, ','); ?>>
					<span><?php esc_html_e('Comma', 'wicket-wp-importer'); ?> (,)</span>
				</label>
				<label class="wicket-importer-toggle">
					<input type="radio" name="wicket_import_csv_delimiter" value=";" <?php checked($defaultDelimiter, ';'); ?>>
					<span><?php esc_html_e('Semicolon', 'wicket-wp-importer'); ?> (;)</span>
				</label>
			</fieldset>
			<div
				id="wicket-import-dropzone"
				class="wicket-importer-dropzone"
				tabindex="0"
				role="button"
				aria-label="<?php esc_attr_e('Upload CSV file', 'wicket-wp-importer'); ?>"
			>
				<input
					type="file"
					id="wicket-import-file-input"
					class="wicket-importer-file-input"
					accept=".csv,text/csv"
					hidden
				>
				<p class="wicket-importer-dropzone-prompt">
					<span class="wicket-importer-dropzone-icon" aria-hidden="true">⬆</span><br>
					<?php esc_html_e('Drop CSV file here, or click to browse', 'wicket-wp-importer'); ?>
				</p>
			</div>

			<div id="wicket-import-file-preview" class="wicket-importer-file-preview" hidden>
				<div class="wicket-importer-file-meta">
					<strong class="wicket-importer-file-name"></strong>
					<span class="wicket-importer-file-size"></span>
					<span class="wicket-importer-file-rows"></span>
				</div>
				<button
					type="button"
					class="button button-primary wicket-importer-upload-btn"
					data-upload-url="<?php echo esc_url($rest . '/upload'); ?>"
					<?php if ($chequeAvailable): ?>
					data-cheque-upload-url="<?php echo esc_url($rest . '/cheque/upload'); ?>"
					<?php endif; ?>
					data-validation-url="<?php echo esc_url($this->validationScreenUrl()); ?>"
				>
					<?php esc_html_e('Validate & Upload', 'wicket-wp-importer'); ?>
				</button>
				<button
					type="button"
					class="button wicket-importer-clear-btn"
					aria-label="<?php esc_attr_e('Clear selected file', 'wicket-wp-importer'); ?>"
				>×</button>
			</div>

			<div class="wicket-importer-actions">
				<a class="button wicket-importer-template-btn" href="<?php echo esc_url(wp_nonce_url($rest . '/template', 'wp_rest', '_wpnonce')); ?>">
					<?php esc_html_e('Download CSV template', 'wicket-wp-importer'); ?>
				</a>
				<?php if ($sampleUrl !== ''): ?>
					<a class="button wicket-importer-sample-btn" href="<?php echo esc_url($sampleUrl); ?>" download>
						<?php esc_html_e('Download sample file', 'wicket-wp-importer'); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ($manualEnabled): ?>
		<div id="wicket-import-manual" class="wicket-importer-upload-section" hidden>
			<?php $this->renderIndividualForm(); ?>
		</div>
		<?php endif; ?>
		<?php
    }

    /**
     * Validation screen skeleton (Task 7.1 container; Task 9 fills the table).
     *
     * Shows the validation summary counts so the screen is functional before
     * Task 9 adds the flagged-rows table. Proceed / Restart buttons carry
     * data-session-id + data-run-url / data-clear-url attributes so Task 8's
     * admin.js can bind without re-deriving the endpoints.
     */
    private function renderValidationScreen(?string $sessionId): void
    {
        $rest = $this->restBase();
        /*
         * Cheque/lockbox wizard pass: the run button targets the cheque
         * bulk-create endpoint (Action Scheduler) and lands on the Cheque
         * Review tab instead of the inline member confirmation screen.
         * The flow comes from the session's batches row (written at upload
         * time), not from ?flow= — a bookmarked or hand-edited URL can no
         * longer swap the engine a session feeds (peer review 2026-08-21).
         */
        $batch = Plugin::get_instance()->BatchProcessor()->getBatchBySession((string) $sessionId);
        $isCheque = ($batch['import_flow'] ?? '') === 'cheque';

        // No session: bounce to upload. The JS flow always passes a session_id,
        // but a direct hit / stale link should not render an empty screen.
        if ($sessionId === null) {
            $this->renderMissingSession();

            return;
        }

        $counts = Plugin::get_instance()->StagingTable()->getValidationSummary($sessionId);
        $total = array_sum($counts);
        if ($total === 0) {
            $this->renderMissingSession($sessionId);

            return;
        }

        $valid = $counts['valid'] ?? 0;
        $flagged = ($counts['invalid'] ?? 0) + ($counts['warning'] ?? 0);
        $duplicate = $counts['duplicate'] ?? 0;
        ?>
		<div class="wicket-importer-validation" data-session-id="<?php echo esc_attr($sessionId); ?>">

			<div class="wicket-importer-summary-bar">
				<span class="wicket-importer-summary-stat is-valid">
					<?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: valid row count */
                            _n('%d valid', '%d valid', $valid, 'wicket-wp-importer'),
                            $valid
                        )
                    );
        ?>
				</span>
				<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
				<span class="wicket-importer-summary-stat is-flagged">
					<?php
        echo esc_html(
            sprintf(
                _n('%d flagged', '%d flagged', $flagged, 'wicket-wp-importer'),
                $flagged
            )
        );
        ?>
				</span>
				<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
				<span class="wicket-importer-summary-stat is-duplicate">
					<?php
        echo esc_html(
            sprintf(
                _n('%d duplicate', '%d duplicates', $duplicate, 'wicket-wp-importer'),
                $duplicate
            )
        );
        ?>
				</span>
			</div>

			<?php
            // Task 9.2: flagged-rows table. Rendered server-side from the same
            // getFlaggedBySession() data the REST /flagged endpoint exposes, so the
            // validation screen is correct on first paint (no separate fetch).
            $this->renderFlaggedTable($sessionId);
        ?>

			<div class="wicket-importer-actions">
				<?php if ($isCheque): ?>
				<p class="description"><?php esc_html_e('The cheque import runs in the background (Action Scheduler). Watch its progress in Import History, then review it under Cheque Review.', 'wicket-wp-importer'); ?></p>
				<?php endif; ?>
				<button
					type="button"
					class="button button-primary wicket-importer-proceed"
					data-session-id="<?php echo esc_attr($sessionId); ?>"
					data-run-url="<?php echo esc_url($rest . ($isCheque ? '/cheque/session/' : '/session/') . $sessionId . '/run'); ?>"
					<?php if ($isCheque): ?>
					data-redirect="<?php echo esc_url(admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review&session_id=' . rawurlencode($sessionId))); ?>"
					<?php endif; ?>
				>
					<?php echo esc_html($isCheque
					? __('Start Cheque Import', 'wicket-wp-importer')
					: __('Proceed with Valid Rows', 'wicket-wp-importer'));
        ?>
				</button>
				<button
					type="button"
					class="button wicket-importer-restart"
					data-session-id="<?php echo esc_attr($sessionId); ?>"
					data-clear-url="<?php echo esc_url($rest . '/session/' . $sessionId); ?>"
					data-upload-url="<?php echo esc_url($isCheque ? add_query_arg('flow', 'cheque', $this->uploadScreenUrl()) : $this->uploadScreenUrl()); ?>"
				>
					<?php esc_html_e('Restart Upload', 'wicket-wp-importer'); ?>
				</button>
				<a
					class="button"
					href="<?php echo esc_url(wp_nonce_url($rest . '/session/' . $sessionId . '/flagged-csv', 'wp_rest', '_wpnonce')); ?>"
				>
					<?php esc_html_e('Download flagged rows', 'wicket-wp-importer'); ?>
				</a>
			</div>
		</div>
		<?php
    }

    /**
     * Confirmation screen skeleton (Task 7.1 container; Task 10 fills the table).
     *
     * Re-fires wicket_import_upload_page_meta (7.1: "re-applied on confirmation
     * screen so the meta slot reflects updated state after import") so a slot
     * like "Next Bar ID" shows its post-increment value. Shows the import
     * summary counts from the staging table; Task 10 adds the per-row table.
     */
    private function renderConfirmationScreen(?string $sessionId): void
    {
        $rest = $this->restBase();

        if ($sessionId === null) {
            $this->renderMissingSession();

            return;
        }

        $counts = Plugin::get_instance()->StagingTable()->getImportSummary($sessionId);
        $total = array_sum($counts);
        if ($total === 0) {
            $this->renderMissingSession($sessionId);

            return;
        }

        $succeeded = ($counts['imported'] ?? 0) + ($counts['updated'] ?? 0);
        $failed = $counts['failed'] ?? 0;
        // WWID-2200: email_conflict and skipped_active_membership are
        // actionable states an admin must review. BatchProcessor::tally folds
        // exactly these two into phase1_needs_review (the stored per-batch
        // tally at src/BulkImport/Subscriptions/BatchProcessor.php), so the
        // history view already counts them under needs_review. Mirror that
        // here so the confirmation summary agrees with the rest of the plugin
        // instead of inventing a new bucket.
        $review = ($counts['needs_review'] ?? 0)
            + ($counts['email_conflict'] ?? 0)
            + ($counts['skipped_active_membership'] ?? 0);
        // Plain skipped = the adapter deliberately skipped the row (benign,
        // e.g. an extension short-circuit). Keep it as its own neutral stat
        // so it stays visible without overstating it as needing review.
        // Rows still 'pending' here failed validation and were never claimed
        // for import, so they're permanently excluded too — fold them in so
        // succeeded + failed + skipped + review accounts for every row.
        $skipped = ($counts['skipped'] ?? 0) + ($counts['pending'] ?? 0);

        // Re-fire meta so post-import state (e.g. Next Bar ID) is reflected.
        $this->renderPageMetaSlots();
        ?>
		<div class="wicket-importer-confirmation" data-session-id="<?php echo esc_attr($sessionId); ?>">

			<div class="wicket-importer-summary-bar">
				<span class="wicket-importer-summary-stat">
					<?php
                    echo esc_html(
                        sprintf(
                            _n('%d processed', '%d processed', $total, 'wicket-wp-importer'),
                            $total
                        )
                    );
        ?>
				</span>
				<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
				<span class="wicket-importer-summary-stat is-valid">
					<?php
        echo esc_html(
            sprintf(
                _n('%d succeeded', '%d succeeded', $succeeded, 'wicket-wp-importer'),
                $succeeded
            )
        );
        ?>
				</span>
				<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
				<span class="wicket-importer-summary-stat is-flagged">
					<?php
        echo esc_html(
            sprintf(
                _n('%d failed', '%d failed', $failed, 'wicket-wp-importer'),
                $failed
            )
        );
        ?>
				</span>
				<?php if ($skipped > 0) : ?>
					<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
					<span class="wicket-importer-summary-stat is-skipped">
						<?php
            echo esc_html(
                sprintf(
                    _n('%d skipped', '%d skipped', $skipped, 'wicket-wp-importer'),
                    $skipped
                )
            );
			    ?>
					</span>
				<?php endif; ?>
				<?php if ($review > 0) : ?>
					<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
					<span class="wicket-importer-summary-stat is-review">
						<?php
            echo esc_html(
                sprintf(
                    _n('%d needs review', '%d need review', $review, 'wicket-wp-importer'),
                    $review
                )
            );
				    ?>
					</span>
				<?php endif; ?>
			</div>

			<?php
            // Task 10.2: per-row results table. Server-side render from
            // getBySession (matches REST /results shape) + extension columns
            // via wicket_import_confirmation_columns.
            $this->renderResultsTable($sessionId);
        ?>

			<div class="wicket-importer-actions">
				<a
					class="button"
					href="<?php echo esc_url(wp_nonce_url($rest . '/session/' . $sessionId . '/results-csv', 'wp_rest', '_wpnonce')); ?>"
				>
					<?php esc_html_e('Download full results', 'wicket-wp-importer'); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url($this->uploadScreenUrl()); ?>">
					<?php esc_html_e('Create new Import', 'wicket-wp-importer'); ?>
				</a>
			</div>
		</div>
		<?php
    }

    /**
     * Flagged-rows table (Task 9.2).
     *
     * Server-side render of every flagged row (validation_status in
     * invalid/duplicate/warning/conflict). Mirrors the shape of the REST
     * /flagged endpoint (line, data, validation_status, validation_message,
     * flagged_fields) so the screen is correct on first paint without a
     * separate fetch. Each cell of the row's data is rendered; flagged keys
     * get a highlighted class + the field-level message surfaced.
     *
     * @param string $sessionId
     */
    private function renderFlaggedTable(string $sessionId): void
    {
        $rows = Plugin::get_instance()->StagingTable()->getFlaggedBySession($sessionId);

        if ($rows === []) {
            ?>
			<div id="wicket-import-flagged-table" class="wicket-importer-placeholder">
				<?php esc_html_e('No flagged rows. Every row is valid.', 'wicket-wp-importer'); ?>
			</div>
			<?php
            return;
        }

        // Column order: registered wicket_import_csv_columns first, then any
        // extra row keys (shared helper, same logic as the CSV exports).
        $columns = ColumnOrder::forRows($rows);
        ?>
		<div id="wicket-import-flagged-table" class="wicket-importer-table-wrap">
			<table class="widefat striped wicket-importer-flagged-table">
				<caption class="screen-reader-text"><?php esc_html_e('Rows that failed validation, with the offending fields and reasons.', 'wicket-wp-importer'); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('Line', 'wicket-wp-importer'); ?></th>
						<?php foreach ($columns as $key) : ?>
							<th scope="col"><?php echo esc_html($key); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e('Status', 'wicket-wp-importer'); ?></th>
						<th scope="col"><?php esc_html_e('Reason', 'wicket-wp-importer'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($rows as $row) :
				    $rowIndex = (int) ($row['row_index'] ?? 0);
				    $data = Json::decodeArray($row['raw_data'] ?? null);
				    $status = (string) ($row['validation_status'] ?? '');
				    $message = (string) ($row['validation_message'] ?? '');
				    $flaggedSet = array_flip(Json::decodeArray($row['flagged_fields'] ?? null));
				    ?>
					<tr>
						<th scope="row"><?php echo esc_html((string) ($rowIndex + 2)); ?></th>
						<?php foreach ($columns as $key) :
						    $value = $data[$key] ?? '';
						    $flagged = isset($flaggedSet[$key]);
						    $cls = $flagged ? 'wicket-importer-cell-flagged' : '';
						    ?>
							<td class="<?php echo esc_attr($cls); ?>"><?php echo esc_html((string) $value); ?></td>
						<?php endforeach; ?>
						<td><?php $this->renderStatusBadge($status); ?></td>
						<td><?php echo esc_html($message); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
    }

    /**
     * Per-row results table (Task 10.2).
     *
     * Server-side render of every row in the session after import. Core
     * columns: Line, Name, Email, MDP UUID, Status, Message. Extension
     * columns arrive via the wicket_import_confirmation_columns filter; each
     * entry is ['label'=>str, 'extractor'=>fn(array $row)=>mixed,
     * 'link_extractor'=>?fn(array $row)=>?string]. The extractor receives the
     * shaped row (raw_data decoded + extension_metadata decoded) and typically
     * reads from extension_metadata (e.g. OBA's Bar ID / tier / View-in-MDP
     * URL, all written during wicket_import_post_membership_create).
     *
     * @param string $sessionId
     */
    private function renderResultsTable(string $sessionId): void
    {
        $rows = Plugin::get_instance()->StagingTable()->getBySession($sessionId);

        if ($rows === []) {
            ?>
			<div id="wicket-import-results-table" class="wicket-importer-placeholder">
				<?php esc_html_e('No rows in this session.', 'wicket-wp-importer'); ?>
			</div>
			<?php
            return;
        }

        /**
         * AD13 / Task 10.2: let extensions add columns to the confirmation
         * table. The extractor receives the shaped row (raw_data +
         * extension_metadata decoded) and returns the cell value; link_extractor
         * optionally wraps it in an anchor. Core registers no columns.
         *
         * @param array $cols Each entry: ['label'=>string, 'extractor'=>callable, 'link_extractor'=>?callable]
         */
        $extColumns = apply_filters('wicket_import_confirmation_columns', []);
        $extColumns = is_array($extColumns) ? $extColumns : [];
        ?>
		<div id="wicket-import-results-table" class="wicket-importer-table-wrap">
			<table class="widefat striped wicket-importer-results-table">
				<caption class="screen-reader-text"><?php esc_html_e('Import results: one row per staged record with its outcome.', 'wicket-wp-importer'); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('Line', 'wicket-wp-importer'); ?></th>
						<th scope="col"><?php esc_html_e('Name', 'wicket-wp-importer'); ?></th>
						<th scope="col"><?php esc_html_e('Email', 'wicket-wp-importer'); ?></th>
						<?php foreach ($extColumns as $col) :
						    $label = isset($col['label']) ? (string) $col['label'] : '';
						    if ($label === '') {
						        continue;
						    }
						    ?>
							<th scope="col"><?php echo esc_html($label); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e('MDP UUID', 'wicket-wp-importer'); ?></th>
						<th scope="col"><?php esc_html_e('Status', 'wicket-wp-importer'); ?></th>
						<th scope="col"><?php esc_html_e('Message', 'wicket-wp-importer'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($rows as $row) :
				    $rowIndex = (int) ($row['row_index'] ?? 0);
				    $data = Json::decodeArray($row['raw_data'] ?? null);
				    // Name extraction uses first_name/last_name keys (the core column
				    // contract). Extensions using different keys (e.g. given_name /
				    // family_name) would render an empty Name cell here; they should
				    // register a wicket_import_confirmation_columns extractor instead.
				    $name = trim((string) ($data['first_name'] ?? '') . ' ' . (string) ($data['last_name'] ?? ''));
				    $email = (string) ($data['email'] ?? '');
				    $status = (string) ($row['import_status'] ?? '');
				    $displayStatus = $this->effectiveImportStatus($status, (string) ($row['validation_status'] ?? ''));
				    $message = (string) ($row['import_message'] ?? '');
				    $uuid = isset($row['mdp_uuid']) ? (string) $row['mdp_uuid'] : '';

				    // Shaped row handed to extension extractors: the raw CSV data
				    // plus the decoded extension_metadata blob, matching the REST
				    // /results endpoint shape so extractors behave identically.
				    $shaped = [
				        'data'               => $data,
				        'validation_status'  => (string) ($row['validation_status'] ?? ''),
				        'import_status'      => $status,
				        'import_message'     => $message,
				        'mdp_uuid'           => $uuid !== '' ? $uuid : null,
				        'extension_metadata' => Json::decodeArray($row['extension_metadata'] ?? null),
				    ];
				    ?>
					<tr>
						<th scope="row"><?php echo esc_html((string) ($rowIndex + 2)); ?></th>
						<td><?php echo esc_html($name); ?></td>
						<td><?php echo esc_html($email); ?></td>
						<?php foreach ($extColumns as $col) :
						    if (empty($col['label'])) {
						        continue;
						    }
						    $value = '';
						    $link = '';
						    try {
						        if (isset($col['extractor']) && is_callable($col['extractor'])) {
						            $extracted = $col['extractor']($shaped);
						            $value = (is_scalar($extracted) || $extracted === null) ? (string) ($extracted ?? '') : '';
						        }
						        if (isset($col['link_extractor']) && is_callable($col['link_extractor'])) {
						            $extractedLink = $col['link_extractor']($shaped);
						            $link = (is_scalar($extractedLink) || $extractedLink === null) ? (string) ($extractedLink ?? '') : '';
						        }
						    } catch (\Throwable $e) {
						        // Isolate extension callback failures so one buggy extractor
						        // can't fatal the whole confirmation screen.
						        Plugin::get_instance()->Logger()->warning(
						            'Confirmation column extractor threw; skipping cell.',
						            ['label' => $col['label'] ?? '', 'error' => $e->getMessage()]
						        );
						    }
						    ?>
							<td><?php
						        if ($value !== '' && $link !== '') {
						            printf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($link), esc_html($value));
						        } else {
						            echo esc_html($value);
						        }
						    ?></td>
						<?php endforeach; ?>
						<td><?php echo $uuid !== '' ? esc_html($uuid) : '&mdash;'; ?></td>
						<td><?php $this->renderStatusBadge($displayStatus); ?></td>
						<td><?php echo esc_html($message); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
    }

    /**
     * Individual / manual entry form (Task 11.1 + 11.2).
     *
     * Renders core fields (First/Last Name, Email, Phone, Address, Membership
     * Tier selector) plus extension-injected fields via the
     * wicket_import_individual_form_fields filter. The form POSTs via fetch
     * to POST /import/individual (Task 11.3); on success JS redirects to the
     * confirmation screen with the single-row session_id (Task 11.4).
     */
    private function renderIndividualForm(): void
    {
        $rest = $this->restBase();

        // Core fields. The field 'name' matches the column key the extension
        // registers via wicket_import_csv_columns so the submitted row flows
        // through the same validation pipeline as a CSV row.
        $coreFields = [
            ['name' => 'first_name', 'label' => __('First Name', 'wicket-wp-importer'), 'type' => 'text', 'required' => true],
            ['name' => 'last_name',  'label' => __('Last Name', 'wicket-wp-importer'),  'type' => 'text', 'required' => true],
            ['name' => 'email',      'label' => __('Email', 'wicket-wp-importer'),      'type' => 'email', 'required' => false],
            ['name' => 'phone',      'label' => __('Phone', 'wicket-wp-importer'),      'type' => 'tel', 'required' => false],
            ['name' => 'address',    'label' => __('Address', 'wicket-wp-importer'),    'type' => 'text', 'required' => false],
            ['name' => 'city',       'label' => __('City', 'wicket-wp-importer'),       'type' => 'text', 'required' => false],
            ['name' => 'state',      'label' => __('State', 'wicket-wp-importer'),      'type' => 'text', 'required' => false],
            ['name' => 'zip',        'label' => __('ZIP', 'wicket-wp-importer'),        'type' => 'text', 'required' => false],
        ];

        /**
         * Task 11.2: let extensions inject form fields. Each entry:
         * ['name'=>str, 'label'=>str, 'type'=>'text'|'email'|'tel'|'date'|'select'|'textarea',
         *  'required'=>bool, 'options'=>[['value'=>str,'label'=>str],...] for select].
         * The 'name' must match a column key the extension registers via
         * wicket_import_csv_columns(context='individual') so the row validates.
         *
         * @param array $fields  Extension fields (core fields are rendered separately).
         * @param array $context ['context' => 'individual']
         */
        $extFields = apply_filters('wicket_import_individual_form_fields', [], ['context' => 'individual']);
        $extFields = is_array($extFields) ? $extFields : [];

        // Membership Tier selector (core field, but guarded: wicket-wp-memberships
        // may not be active, and the CPT slug is extension-supplied via the filter
        // so core doesn't hardcode a sibling plugin's post type).
        $tierPostType = apply_filters('wicket_import_membership_tier_post_type', 'wicket_mship_tier');
        $tiers = [];
        if (is_string($tierPostType) && $tierPostType !== '' && post_type_exists($tierPostType)) {
            $tiers = get_posts([
                'post_type'      => $tierPostType,
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ]);
        }
        ?>
		<form id="wicket-import-individual-form" class="wicket-importer-individual-form" method="post">
			<div class="wicket-importer-form-grid">
				<?php foreach ($coreFields as $field) : ?>
					<?php $this->renderFormField($field); ?>
				<?php endforeach; ?>

				<?php if ($tiers) : ?>
					<div class="wicket-importer-form-field">
						<label for="wicket-import-tier"><?php esc_html_e('Membership Tier', 'wicket-wp-importer'); ?></label>
						<select name="membership_tier" id="wicket-import-tier">
							<option value=""><?php esc_html_e('— Select —', 'wicket-wp-importer'); ?></option>
							<?php foreach ($tiers as $tier) : ?>
								<option value="<?php echo esc_attr((string) $tier->ID); ?>"><?php echo esc_html($tier->post_title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php foreach ($extFields as $field) : ?>
					<?php $this->renderFormField($field); ?>
				<?php endforeach; ?>
			</div>

			<div class="wicket-importer-actions">
				<button
					type="submit"
					class="button button-primary wicket-importer-individual-submit"
					data-individual-url="<?php echo esc_url($rest . '/individual'); ?>"
					data-confirmation-url="<?php echo esc_url($this->confirmationScreenUrl()); ?>"
				><?php esc_html_e('Validate & Upload Member', 'wicket-wp-importer'); ?></button>
			</div>
		</form>
		<?php
    }

    /**
     * Render a single form field (Task 11.1 helper, reused by extension fields).
     *
     * @param array $field ['name'=>str, 'label'=>str, 'type'=>str, 'required'=>bool, 'options'=>?, 'placeholder'=>?]
     */
    private function renderFormField(array $field): void
    {
        $name = (string) ($field['name'] ?? '');
        $label = (string) ($field['label'] ?? $name);
        $type = (string) ($field['type'] ?? 'text');
        $required = !empty($field['required']);
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
        $placeholder = (string) ($field['placeholder'] ?? '');
        $id = 'wicket-import-field-' . sanitize_key($name);

        if ($name === '') {
            return;
        }
        ?>
		<div class="wicket-importer-form-field">
			<label for="<?php echo esc_attr($id); ?>">
				<?php echo esc_html($label); ?>
				<?php if ($required) : ?>
					<span class="required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<?php if ($type === 'select' && $options) : ?>
				<select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>"<?php echo $required ? ' required' : ''; ?>>
					<option value=""><?php esc_html_e('— Select —', 'wicket-wp-importer'); ?></option>
					<?php foreach ($options as $opt) : ?>
						<option value="<?php echo esc_attr((string) ($opt['value'] ?? '')); ?>"><?php echo esc_html((string) ($opt['label'] ?? '')); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ($type === 'textarea') : ?>
				<textarea name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>"<?php echo $required ? ' required' : ''; ?><?php echo $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : ''; ?> rows="3"></textarea>
			<?php else : ?>
				<input
					type="<?php echo esc_attr($type); ?>"
					name="<?php echo esc_attr($name); ?>"
					id="<?php echo esc_attr($id); ?>"
					<?php echo $required ? ' required' : ''; ?>
					<?php echo $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
				>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Admin URL for the confirmation screen (Task 11.4 redirect target).
     */
    private function confirmationScreenUrl(): string
    {
        return esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_CONFIRMATION));
    }

    /**
     * Resolve the import_status to actually display for a row.
     *
     * import_status defaults to 'pending' for every staged row and only
     * moves to a terminal status when the row is eligible for import
     * (validation_status IN ('valid', 'warning')) and gets claimed — see
     * claimChunk()/claimImportableInSession(). A row that failed validation
     * is never claimed, so its import_status stays 'pending' forever, even
     * after the batch is finished. Relabel that case as 'skipped' so it
     * isn't confused with a row genuinely still queued for processing.
     */
    private function effectiveImportStatus(string $importStatus, string $validationStatus): string
    {
        if ($importStatus !== 'pending') {
            return $importStatus;
        }

        $eligible = [ValidationResult::STATUS_VALID, ValidationResult::STATUS_WARNING];

        return in_array($validationStatus, $eligible, true) ? $importStatus : 'skipped';
    }

    /**
     * Status badge for a validation / import status string (Task 9.2).
     *
     * Shared by both the validation table (validation_status) and the
     * confirmation results table (import_status) once Task 10 lands — kept
     * here so both surfaces use one status -> label/variant mapping.
     *
     * @param string $status validation_status or import_status value. For
     *                       import_status, pass it through
     *                       {@see effectiveImportStatus()} first.
     */
    private function renderStatusBadge(string $status): void
    {
        $labels = [
            'invalid'                  => [__('Invalid', 'wicket-wp-importer'), 'error'],
            'duplicate'                => [__('Duplicate', 'wicket-wp-importer'), 'warning'],
            'warning'                  => [__('Warning', 'wicket-wp-importer'), 'warning'],
            'email_conflict'           => [__('Email conflict', 'wicket-wp-importer'), 'error'],
            'skipped_active_membership'=> [__('Active member', 'wicket-wp-importer'), 'warning'],
            'needs_review'             => [__('Needs review', 'wicket-wp-importer'), 'warning'],
            'failed'                   => [__('Failed', 'wicket-wp-importer'), 'error'],
            'imported'                 => [__('Imported', 'wicket-wp-importer'), 'success'],
            'updated'                  => [__('Updated', 'wicket-wp-importer'), 'success'],
            'skipped'                  => [__('Skipped', 'wicket-wp-importer'), 'neutral'],
            'pending'                  => [__('Pending', 'wicket-wp-importer'), 'neutral'],
            'processing'               => [__('Processing', 'wicket-wp-importer'), 'info'],
        ];

        $label = $labels[$status] ?? [$status, 'info'];
        printf(
            '<span class="wicket-importer-badge wicket-importer-badge-%s">%s</span>',
            esc_attr($label[1]),
            esc_html($label[0])
        );
    }

    /**
     * Server-rendered notices container. Task 8 JS injects inline errors here.
     */
    private function renderScreenNotices(): void
    {
        ?>
		<div class="wicket-importer-notices" role="status" aria-live="polite"></div>
		<?php
    }

    /**
     * Render the wicket_import_upload_page_meta slots (Task 7.2).
     *
     * Each returned entry is ['label' => string, 'value' => string]. Rendered
     * as a row of stat boxes. Empty when no extension supplies meta (core
     * registers none per AD8). Called on BOTH the upload screen and the
     * confirmation screen (Task 7.1 re-fire).
     */
    private function renderPageMetaSlots(): void
    {
        /**
         * AD8 / Task 7.2: let extensions inject UI metadata slots displayed
         * above the upload (and re-fired on confirmation). Core renders the
         * slot; the extension provides the value. Example: OBA's "Next Bar ID".
         *
         * @param array $meta Each entry: ['label' => string, 'value' => string]
         */
        $meta = apply_filters('wicket_import_upload_page_meta', []);

        if (!is_array($meta) || $meta === []) {
            return;
        }

        ?>
		<div class="wicket-importer-meta">
			<?php foreach ($meta as $slot) :
			    $label = isset($slot['label']) ? (string) $slot['label'] : '';
			    $value = isset($slot['value']) ? (string) $slot['value'] : '';
			    if ($label === '' && $value === '') {
			        continue;
			    }
			    ?>
				<div class="wicket-importer-meta-slot">
					<?php if ($label !== '') : ?>
						<span class="wicket-importer-meta-label"><?php echo esc_html($label); ?></span>
					<?php endif; ?>
					<span class="wicket-importer-meta-value"><?php echo esc_html($value); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
    }

    /**
     * Rendered when a screen is hit without a resolvable session. Keeps the
     * page usable (a Create New Import link) rather than blank.
     */
    private function renderMissingSession(?string $sessionId = null): void
    {
        ?>
		<div class="wicket-importer-missing-session notice inline notice-warning">
			<p>
				<?php
                if ($sessionId !== null) {
                    esc_html_e('No rows found for that session. It may have been cleared or completed.', 'wicket-wp-importer');
                } else {
                    esc_html_e('No active import session. Start a new upload.', 'wicket-wp-importer');
                }
        ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url($this->uploadScreenUrl()); ?>">
					<?php esc_html_e('Start new Import', 'wicket-wp-importer'); ?>
				</a>
			</p>
		</div>
		<?php
    }

    /**
     * History screen router: dispatches to the list view or the per-batch
     * detail view based on $_GET['batch_id']. Unknown batch_id pattern falls
     * back to the list (fail open rather than 404).
     */
    private function renderHistoryScreen(): void
    {
        $notice = isset($_GET['wicket_import_notice']) ? sanitize_key(wp_unslash($_GET['wicket_import_notice'])) : '';
        if ($notice === 'session_cleared') {
            ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e('Import session cleared. New uploads are unblocked.', 'wicket-wp-importer'); ?></p>
			</div>
			<?php
        } elseif ($notice === 'session_clear_failed') {
            ?>
			<div class="notice notice-error">
				<p><?php esc_html_e('Could not clear that session. The batch may have been removed already.', 'wicket-wp-importer'); ?></p>
			</div>
			<?php
        }

        $batchId = isset($_GET['batch_id']) ? sanitize_text_field(wp_unslash($_GET['batch_id'])) : '';
        if ($batchId !== '' && preg_match(self::SESSION_ID_PATTERN, $batchId) === 1) {
            $this->renderHistoryDetail($batchId);

            return;
        }
        $this->renderHistoryList();
    }

    /**
     * Paginated list of past import batches with status / date / file / user
     * filters. Each row links to ?screen=history&batch_id=<uuid>.
     */
    private function renderHistoryList(): void
    {
        global $wpdb;

        $perPage = 20;
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $perPage;

        $batchesTable = $wpdb->prefix . 'wicket_import_batches';
        $usersTable = $wpdb->users;

        // Filters from querystring.
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $file = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        $userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;

        // Sort (M8): whitelisted column + direction only; never interpolate
        // raw querystring into the ORDER BY.
        $sortCols = ['created_at' => 'b.created_at', 'status' => 'b.status', 'csv_filename' => 'b.csv_filename'];
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : '';
        $orderCol = $sortCols[$orderby] ?? 'b.created_at';
        $dir = strtoupper(isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : '') === 'ASC' ? 'ASC' : 'DESC';

        // Build WHERE.
        $where = ' WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND b.status = %s';
            $params[] = $status;
        }
        if ($from !== '') {
            $where .= ' AND b.created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= ' AND b.created_at <= %s';
            $params[] = $to . ' 23:59:59';
        }
        if ($file !== '') {
            $where .= ' AND b.csv_filename LIKE %s';
            $params[] = '%' . $wpdb->esc_like($file) . '%';
        }
        if ($userId > 0) {
            $where .= ' AND b.created_by_user_id = %d';
            $params[] = $userId;
        }

        // Distinct users who have created at least one batch (for the dropdown).
        // P6: cap the dropdown (the JOIN+DISTINCT is unbounded otherwise).
        $userRows = $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.display_name
			 FROM {$usersTable} u
			 INNER JOIN {$batchesTable} b ON b.created_by_user_id = u.ID
			 ORDER BY u.display_name ASC
			 LIMIT 500"
        );

        // Total for pagination.
        $countSql = "SELECT COUNT(*) FROM {$batchesTable} b {$where}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($countSql, $params)) : $wpdb->get_var($countSql));
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Page query.
        $sql = "SELECT b.*, u.display_name AS user_display_name
			FROM {$batchesTable} b
			LEFT JOIN {$usersTable} u ON u.ID = b.created_by_user_id
			{$where}
			ORDER BY {$orderCol} {$dir}
			LIMIT %d OFFSET %d";
        $pageParams = array_merge($params, [$perPage, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $pageParams));

        // Build base URL preserving current filters so pagination links survive.
        $filterArgs = [];
        if ($status !== '') {
            $filterArgs['status'] = $status;
        }
        if ($from !== '') {
            $filterArgs['from'] = $from;
        }
        if ($to !== '') {
            $filterArgs['to'] = $to;
        }
        if ($file !== '') {
            $filterArgs['file'] = $file;
        }
        if ($userId > 0) {
            $filterArgs['user'] = $userId;
        }
        if (isset($sortCols[$orderby])) {
            $filterArgs['orderby'] = $orderby;
            if ($dir === 'ASC') {
                $filterArgs['order'] = 'asc';
            }
        }
        $baseUrl = add_query_arg(
            $filterArgs,
            admin_url('admin.php?page=wicket-wp-importer&tab=history')
        );

        // M8: sortable column headers (created_at / file name / status),
        // built on the whitelisted ORDER BY above so pagination keeps the sort.
        $sortUrl = static function (string $col) use ($baseUrl, $orderby, $dir): string {
            $nextDir = ($orderby === $col && $dir === 'DESC') ? 'asc' : 'desc';

            return add_query_arg(['orderby' => $col, 'order' => $nextDir], $baseUrl);
        };
        $sortArrow = static fn (string $col): string => $orderby === $col ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
        ?>
		<div class="wicket-importer-history">
			<form method="get" class="wicket-importer-history-filters">
				<input type="hidden" name="page" value="wicket-wp-importer">
				<input type="hidden" name="tab" value="history">

				<label>
					<span><?php esc_html_e('Status', 'wicket-wp-importer'); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e('Any', 'wicket-wp-importer'); ?></option>
						<?php foreach (['pending', 'running', 'pending_review', 'phase2_running', 'processing_complete', 'completed', 'failed', 'cleared'] as $opt) : ?>
							<option value="<?php echo esc_attr($opt); ?>" <?php selected($status, $opt); ?>>
								<?php echo esc_html(ucfirst($opt)); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e('From', 'wicket-wp-importer'); ?></span>
					<input type="date" name="from" value="<?php echo esc_attr($from); ?>">
				</label>

				<label>
					<span><?php esc_html_e('To', 'wicket-wp-importer'); ?></span>
					<input type="date" name="to" value="<?php echo esc_attr($to); ?>">
				</label>

				<label>
					<span><?php esc_html_e('File', 'wicket-wp-importer'); ?></span>
					<input type="text" name="file" value="<?php echo esc_attr($file); ?>" placeholder="<?php esc_attr_e('filename.csv', 'wicket-wp-importer'); ?>">
				</label>

				<label>
					<span><?php esc_html_e('User', 'wicket-wp-importer'); ?></span>
					<select name="user">
						<option value="0"><?php esc_html_e('Any', 'wicket-wp-importer'); ?></option>
						<?php foreach ($userRows as $u) : ?>
							<option value="<?php echo esc_attr((string) $u->ID); ?>" <?php selected($userId, (int) $u->ID); ?>>
								<?php echo esc_html($u->display_name); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="submit" class="button"><?php esc_html_e('Filter', 'wicket-wp-importer'); ?></button>
				<a class="button" href="<?php echo esc_url($this->historyScreenUrl()); ?>"><?php esc_html_e('Reset', 'wicket-wp-importer'); ?></a>
			</form>

			<?php if (!$rows) : ?>
				<p><?php esc_html_e('No imports match the current filters.', 'wicket-wp-importer'); ?></p>
			<?php else : ?>
				<table class="widefat striped wicket-importer-history-table">
					<thead>
						<tr>
							<th><a href="<?php echo esc_url($sortUrl('created_at')); ?>"><?php esc_html_e('Started', 'wicket-wp-importer'); ?><?php echo esc_html($sortArrow('created_at')); ?></a></th>
							<th><a href="<?php echo esc_url($sortUrl('csv_filename')); ?>"><?php esc_html_e('File', 'wicket-wp-importer'); ?><?php echo esc_html($sortArrow('csv_filename')); ?></a></th>
							<th><?php esc_html_e('User', 'wicket-wp-importer'); ?></th>
							<th><a href="<?php echo esc_url($sortUrl('status')); ?>"><?php esc_html_e('Status', 'wicket-wp-importer'); ?><?php echo esc_html($sortArrow('status')); ?></a></th>
							<th><?php esc_html_e('Rows', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Progress', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Duration', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Finished', 'wicket-wp-importer'); ?></th>
							<th class="wicket-importer-history-actions-col"><?php esc_html_e('Actions', 'wicket-wp-importer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $row) :
						    $detailUrl = add_query_arg('batch_id', $row->batch_id, $baseUrl);
						    $progress = self::progressLabel([
						        'status'              => (string) $row->status,
						        'phase1_succeeded'    => (int) $row->phase1_succeeded,
						        'phase1_failed'       => (int) $row->phase1_failed,
						        'phase1_needs_review' => (int) $row->phase1_needs_review,
						        'phase2_total'        => (int) $row->phase2_total,
						        'phase2_succeeded'    => (int) $row->phase2_succeeded,
						    ]);
						    $started = mysql2date('Y-m-d H:i', $row->created_at);
						    $finished = $row->finished_at ? mysql2date('Y-m-d H:i', $row->finished_at) : '—';
						    $duration = self::formatDuration((string) $row->created_at, (string) $row->finished_at);
						    ?>
							<tr>
								<td><?php echo esc_html($started); ?></td>
								<td>
									<a href="<?php echo esc_url($detailUrl); ?>">
										<?php echo esc_html($row->csv_filename ?: '—'); ?>
									</a>
								</td>
								<td><?php echo esc_html($row->user_display_name ?: '—'); ?></td>
								<td><?php echo esc_html($row->status); ?></td>
								<td><?php echo esc_html((string) ((int) $row->csv_row_count)); ?></td>
								<td><?php echo esc_html($progress); ?></td>
								<td><?php echo esc_html($duration); ?></td>
								<td><?php echo esc_html($finished); ?></td>
								<td class="wicket-importer-history-actions-col">
									<?php if ($row->status === 'running') : ?>
										<form
											method="post"
											action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
											class="wicket-importer-clear-session-form"
											onsubmit="return confirm('<?php echo esc_js(__('Clear this session? The staged rows will be deleted and the import can no longer be run.', 'wicket-wp-importer')); ?>');"
										>
											<input type="hidden" name="action" value="wicket_import_clear_session">
											<input type="hidden" name="batch_id" value="<?php echo esc_attr($row->batch_id); ?>">
											<?php wp_nonce_field('wicket_import_clear_session', '_wpnonce'); ?>
											<button type="submit" class="button button-link-delete"><?php esc_html_e('Clear session', 'wicket-wp-importer'); ?></button>
										</form>
									<?php else : ?>
										<?php if (in_array($row->status, ['pending_review', 'phase2_running', 'processing_complete'], true)) : ?>
											<a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review&session_id=' . rawurlencode((string) $row->session_id))); ?>"><?php esc_html_e('Phase 2 review', 'wicket-wp-importer'); ?></a>
										<?php endif; ?>
										<?php if (in_array($row->status, ['pending_review', 'processing_complete', 'completed', 'failed'], true)) : ?>
											<a class="button button-small" href="<?php echo esc_url(wp_nonce_url($this->restBase() . '/session/' . rawurlencode((string) $row->session_id) . '/results-csv', 'wp_rest', '_wpnonce')); ?>"><?php esc_html_e('Report', 'wicket-wp-importer'); ?></a>
										<?php endif; ?>
										<?php if (!in_array($row->status, ['pending_review', 'phase2_running', 'processing_complete', 'completed', 'failed'], true)) : ?>
											&mdash;
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
                $pagination = paginate_links([
                    'base'      => add_query_arg('paged', '%#%', $baseUrl),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $totalPages,
                    'current'   => $paged,
                    'type'      => 'plain',
                ]);
			    if ($pagination) : ?>
					<div class="wicket-importer-history-pagination tablenav">
						<div class="tablenav-pages"><?php echo wp_kses_post($pagination); ?></div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Human-readable progress sentence for a History list row, derived from
     * the batch's status + phase counts. Replaces the old "Phase 1 / Phase 2"
     * count columns, which read as raw tallies and told the admin nothing
     * about where the import actually stands.
     *
     * Pure (no WP calls) so the unit suite covers it directly. Phase names
     * follow the flow: Phase 1 = member import (person + membership),
     * Phase 2 = cheque/lockbox subscription batch (cheque flow only).
     *
     * @param array<string,mixed> $batch status + phase1_* + phase2_total keys.
     */
    public static function progressLabel(array $batch): string
    {
        $settledPhase1 = (int) ($batch['phase1_succeeded'] ?? 0)
            + (int) ($batch['phase1_failed'] ?? 0)
            + (int) ($batch['phase1_needs_review'] ?? 0);
        $hasPhase2 = (int) ($batch['phase2_total'] ?? 0) > 0;

        switch ((string) ($batch['status'] ?? '')) {
            case 'running':
                // A run is in flight only once rows have settled; a running
                // batch with no settled rows was staged but never run (the
                // stuck-session case the clear button exists for).
                return $settledPhase1 > 0
                    ? __('Import in progress', 'wicket-wp-importer')
                    : __('Awaiting import run', 'wicket-wp-importer');
            case 'pending_review':
                return $hasPhase2
                    ? __('Phase 1 complete, awaiting lockbox', 'wicket-wp-importer')
                    : __('Import needs review', 'wicket-wp-importer');
            case 'completed':
                return $hasPhase2
                    ? __('Lockbox complete', 'wicket-wp-importer')
                    : __('Members imported', 'wicket-wp-importer');
            case 'failed':
                return __('Import failed', 'wicket-wp-importer');
            case 'phase2_running':
                return sprintf(
                    __('Phase 2 in progress (%1$d / %2$d payments matched)', 'wicket-wp-importer'),
                    (int) ($batch['phase2_succeeded'] ?? 0),
                    (int) ($batch['phase2_total'] ?? 0)
                );
            case 'processing_complete':
                return __('Lockbox run complete', 'wicket-wp-importer');
            case 'cleared':
                return $settledPhase1 > 0
                    ? __('Cleared mid-run', 'wicket-wp-importer')
                    : __('Cleared before run', 'wicket-wp-importer');
            default:
                return ucfirst((string) ($batch['status'] ?? ''));
        }
    }

    /**
     * admin-post handler: clear a stuck import session (History detail
     * "Clear session" button).
     *
     * A session whose staged rows are still pending blocks all new uploads
     * (hasActiveSession 409 gate) until the 24h TTL cron expires it. When the
     * validation screen that hosted the only Clear button is gone (admin left
     * the flow, browser closed), this is the escape hatch. Deletes the staged
     * rows + retained source CSV and marks the batches row terminal
     * ('cleared') so History stops showing it as running.
     */
    public static function handleClearSession(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'wicket-wp-importer'), 403);
        }

        check_admin_referer('wicket_import_clear_session', '_wpnonce');

        $historyUrl = admin_url('admin.php?page=wicket-wp-importer&tab=history');

        $batchId = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        $batchProcessor = Plugin::get_instance()->BatchProcessor();
        $batch = ($batchId !== '')
            ? $batchProcessor->getBatchBySession($batchProcessor->getSessionByBatch($batchId) ?? '')
            : null;

        // Server-side guard: the button only renders for 'running' batches, but
        // the POST itself must re-check. A stale tab or resubmit must not wipe
        // the audit trail (results, CSVs) of a completed import.
        if ($batch === null || $batch['status'] !== 'running') {
            wp_safe_redirect(add_query_arg('wicket_import_notice', 'session_clear_failed', $historyUrl));
            exit;
        }

        $sessionId = (string) $batch['session_id'];

        $plugin = Plugin::get_instance();
        // Finalize the batches row BEFORE deleting the rows so the stored phase
        // stats tally from real data instead of zeroing out.
        $plugin->BatchProcessor()->finishRunBySession($sessionId, 'cleared');
        $plugin->StagingTable()->deleteSession($sessionId);
        CsvStorage::delete($sessionId);

        (new \WicketImporter\Services\Logger())->info('Import session cleared by admin.', [
            'batch_id'   => $batchId,
            'session_id' => $sessionId,
            'user_id'    => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg('wicket_import_notice', 'session_cleared', $historyUrl));
        exit;
    }

    /**
     * Per-batch detail (drill-down). Header summary + paginated per-row
     * staged_records table.
     */
    private function renderHistoryDetail(string $batchId): void
    {
        global $wpdb;

        $perPage = 20;
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $perPage;

        $batchesTable = $wpdb->prefix . 'wicket_import_batches';
        $stagedTable = $wpdb->prefix . 'wicket_import_staged_records';
        $usersTable = $wpdb->users;

        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, u.display_name AS user_display_name
			 FROM {$batchesTable} b
			 LEFT JOIN {$usersTable} u ON u.ID = b.created_by_user_id
			 WHERE b.batch_id = %s",
            $batchId
        ));

        if (!$batch) {
            ?>
			<div class="wicket-importer-history">
				<p><?php esc_html_e('Batch not found.', 'wicket-wp-importer'); ?></p>
				<p><a class="button" href="<?php echo esc_url($this->historyScreenUrl()); ?>"><?php esc_html_e('Back to history', 'wicket-wp-importer'); ?></a></p>
			</div>
			<?php
            return;
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$stagedTable} WHERE session_id = %s",
            $batch->session_id
        ));
        $totalPages = max(1, (int) ceil($count / $perPage));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, row_index, validation_status, validation_message, import_status, import_message, mdp_uuid, raw_data
			 FROM {$stagedTable}
			 WHERE session_id = %s
			 ORDER BY row_index ASC, id ASC
			 LIMIT %d OFFSET %d",
            $batch->session_id,
            $perPage,
            $offset
        ));

        $baseUrl = $this->historyScreenUrl();
        $detailBaseUrl = add_query_arg('batch_id', $batchId, $baseUrl);
        ?>
		<div class="wicket-importer-history">
			<p>
				<a href="<?php echo esc_url($baseUrl); ?>">&larr; <?php esc_html_e('Back to history', 'wicket-wp-importer'); ?></a>
			</p>

			<table class="widefat striped wicket-importer-history-summary">
				<tbody>
					<tr><th><?php esc_html_e('Started', 'wicket-wp-importer'); ?></th><td><?php echo esc_html(mysql2date('Y-m-d H:i', $batch->created_at)); ?></td></tr>
					<tr><th><?php esc_html_e('File', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->csv_filename ?: '—'); ?></td></tr>
					<tr><th><?php esc_html_e('User', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->user_display_name ?: '—'); ?></td></tr>
					<tr><th><?php esc_html_e('Status', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->status); ?></td></tr>
					<tr><th><?php esc_html_e('Rows', 'wicket-wp-importer'); ?></th><td><?php echo esc_html((string) (int) $batch->csv_row_count); ?></td></tr>
					<tr><th><?php esc_html_e('Phase 1 (ok / fail / review)', 'wicket-wp-importer'); ?></th>
						<td><?php echo esc_html(sprintf('%d / %d / %d', (int) $batch->phase1_succeeded, (int) $batch->phase1_failed, (int) $batch->phase1_needs_review)); ?></td></tr>
					<tr><th><?php esc_html_e('Phase 2 (ok / fail / review)', 'wicket-wp-importer'); ?></th>
						<td><?php echo esc_html(sprintf('%d / %d / %d', (int) $batch->phase2_succeeded, (int) $batch->phase2_failed, (int) $batch->phase2_needs_review)); ?></td></tr>
					<tr><th><?php esc_html_e('Phase 2 started', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->phase2_started_at ? mysql2date('Y-m-d H:i', $batch->phase2_started_at) : '—'); ?></td></tr>
					<tr><th><?php esc_html_e('Phase 2 finished', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->phase2_completed_at ? mysql2date('Y-m-d H:i', $batch->phase2_completed_at) : '—'); ?></td></tr>
					<tr><th><?php esc_html_e('Finished', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->finished_at ? mysql2date('Y-m-d H:i', $batch->finished_at) : '—'); ?></td></tr>
					<tr><th><?php esc_html_e('Duration', 'wicket-wp-importer'); ?></th><td><?php echo esc_html(self::formatDuration((string) $batch->created_at, (string) $batch->finished_at)); ?></td></tr>
				</tbody>
			</table>

			<?php $sourceRest = $this->restBase(); ?>
			<div class="wicket-importer-batch-actions">
				<a class="button" href="<?php echo esc_url(wp_nonce_url($sourceRest . '/session/' . $batch->session_id . '/source-csv', 'wp_rest', '_wpnonce')); ?>">
					<?php esc_html_e('Download source CSV', 'wicket-wp-importer'); ?>
				</a>
				<a class="button" href="<?php echo esc_url(wp_nonce_url($sourceRest . '/session/' . $batch->session_id . '/results-csv', 'wp_rest', '_wpnonce')); ?>">
					<?php esc_html_e('Download report CSV', 'wicket-wp-importer'); ?>
				</a>
				<a class="button" href="<?php echo esc_url(wp_nonce_url($sourceRest . '/session/' . $batch->session_id . '/error-csv?context=cheque', 'wp_rest', '_wpnonce')); ?>">
					<?php esc_html_e('Export errors (CSV)', 'wicket-wp-importer'); ?>
				</a>
				<?php if (in_array($batch->status, ['pending_review', 'phase2_running', 'processing_complete'], true)) : ?>
					<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wicket-wp-importer&tab=cheque-review&session_id=' . rawurlencode((string) $batch->session_id))); ?>">
						<?php $batch->status === 'pending_review' ? esc_html_e('Start Phase 2', 'wicket-wp-importer') : esc_html_e('Open Phase 2 review', 'wicket-wp-importer'); ?>
					</a>
				<?php endif; ?>
				<?php if ($batch->status === 'running') : ?>
					<form
						method="post"
						action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
						class="wicket-importer-clear-session-form"
						onsubmit="return confirm('<?php echo esc_js(__('Clear this session? The staged rows will be deleted and the import can no longer be run.', 'wicket-wp-importer')); ?>');"
					>
						<input type="hidden" name="action" value="wicket_import_clear_session">
						<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch->batch_id); ?>">
						<?php wp_nonce_field('wicket_import_clear_session', '_wpnonce'); ?>
						<button type="submit" class="button button-link-delete"><?php esc_html_e('Clear session', 'wicket-wp-importer'); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e('Rows', 'wicket-wp-importer'); ?> (<?php echo (int) $count; ?>)</h2>

			<?php if (!$rows) : ?>
				<p><?php esc_html_e('No rows for this batch.', 'wicket-wp-importer'); ?></p>
			<?php else : ?>
				<table class="widefat striped wicket-importer-history-rows">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e('Validation', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Import', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('MDP UUID', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Raw data', 'wicket-wp-importer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $row) : ?>
							<tr>
								<td><?php echo (int) $row->row_index; ?></td>
								<td>
									<?php $this->renderStatusBadge((string) $row->validation_status); ?>
									<?php if ($row->validation_message) : ?>
										<div class="wicket-importer-history-msg"><?php echo esc_html($row->validation_message); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<?php $this->renderStatusBadge($this->effectiveImportStatus((string) $row->import_status, (string) $row->validation_status)); ?>
									<?php if ($row->import_message) : ?>
										<div class="wicket-importer-history-msg"><?php echo esc_html($row->import_message); ?></div>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html($row->mdp_uuid ?: '—'); ?></td>
								<td><code class="wicket-importer-history-raw"><?php echo esc_html(self::truncateForDisplay((string) $row->raw_data, 200)); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
                $pagination = paginate_links([
                    'base'      => add_query_arg('paged', '%#%', $detailBaseUrl),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $totalPages,
                    'current'   => $paged,
                    'type'      => 'plain',
                ]);
        if ($pagination) : ?>
					<div class="wicket-importer-history-pagination tablenav">
						<div class="tablenav-pages"><?php echo wp_kses_post($pagination); ?></div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Format (from -> to) duration as "Xm Ys" / "—" when missing or invalid.
     */
    private static function formatDuration(string $from, string $to): string
    {
        if (!$from || !$to) {
            return '—';
        }
        $start = strtotime($from);
        $end = strtotime($to);
        if (!$start || !$end || $end < $start) {
            return '—';
        }
        $secs = $end - $start;
        if ($secs < 60) {
            return $secs . 's';
        }
        $mins = (int) floor($secs / 60);
        $rem = $secs % 60;

        return $mins . 'm ' . $rem . 's';
    }

    /**
     * Truncate a long string for display in a table cell (raw_data is longtext).
     * Prefers a line-break boundary near the limit so multi-line cells don't
     * chop mid-word.
     */
    private static function truncateForDisplay(string $s, int $limit): string
    {
        // B16: byte-based strlen/substr can split a multibyte UTF-8 sequence,
        // yielding invalid UTF-8 that esc_html()/wp_check_invalid_utf8() then
        // blanks entirely (the whole Raw-data cell renders empty). Operate on
        // characters, not bytes.
        if (mb_strlen($s, 'UTF-8') <= $limit) {
            return $s;
        }
        $cut = mb_substr($s, 0, $limit, 'UTF-8');
        $nl = mb_strrpos($cut, "\n", 0, 'UTF-8');
        if ($nl !== false && $nl > $limit / 2) {
            $cut = mb_substr($cut, 0, $nl, 'UTF-8');
        }

        return $cut . '…';
    }

    /**
     * Current screen from $_GET['screen']. Defaults to upload.
     */
    private function currentScreen(): string
    {
        $screen = isset($_GET['screen']) ? sanitize_key(wp_unslash($_GET['screen'])) : '';

        return in_array($screen, self::ALLOWED_SCREENS, true) ? $screen : self::SCREEN_UPLOAD;
    }

    /**
     * Current flow from $_GET['flow']: 'cheque' when the wizard pass is a
     * cheque/lockbox upload, '' for the member flow. Presentational only:
     * it picks run-endpoint URL, button labels, and redirects on the wizard
     * screens. The REST endpoints stay the authority for what a session can
     * do (the upload endpoint already validates each flow's column contract).
     */
    private function currentFlow(): string
    {
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash($_GET['flow'])) : '';

        return $flow === 'cheque' ? 'cheque' : '';
    }

    /**
     * Current session_id from $_GET['session_id']. Null when absent or malformed.
     */
    private function currentSessionId(): ?string
    {
        if (!isset($_GET['session_id'])) {
            return null;
        }
        // Nit#5: preserve case (sanitize_key lowercases); the REST route regex
        // accepts A-F, and SESSION_ID_PATTERN below enforces the UUID format.
        $id = sanitize_text_field(wp_unslash($_GET['session_id']));

        return preg_match(self::SESSION_ID_PATTERN, $id) === 1 ? $id : null;
    }

    /**
     * REST namespace base URL for endpoint linking. Used by the data-* URL
     * attributes so admin.js doesn't hardcode endpoints.
     */
    private function restBase(): string
    {
        return esc_url_raw(rest_url(WICKET_IMPORT_REST_NAMESPACE . '/import'));
    }

    /**
     * Admin URL for the upload screen (clean params). Used by Restart / Create
     * New buttons to drop the session param and return to a fresh upload.
     */
    private function uploadScreenUrl(): string
    {
        return esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_UPLOAD));
    }

    /**
     * Admin URL for the validation screen. The session_id is appended by JS
     * after upload (it isn't known at render time). Shipped as a base URL with
     * a %s placeholder the JS fills, OR built without a session for fallback.
     */
    private function validationScreenUrl(): string
    {
        return esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_VALIDATION));
    }

    /**
     * Admin URL for the history tab. Used by the upload-screen link and
     * the detail-page "back" link.
     */
    private function historyScreenUrl(): string
    {
        return esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&tab=history'));
    }
}
