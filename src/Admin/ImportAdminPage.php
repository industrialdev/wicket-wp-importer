<?php
declare(strict_types=1);

namespace WicketImporter\Admin;

use HyperFields\HyperFields;
use WicketImporter\Support\ColumnOrder;
use WicketImporter\Support\Json;
use WicketImporter\Support\SecuresRequests;
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
 * @see docs/engineering/rest-endpoints.md
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
            $this->renderUploadScreen();
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
    private function renderUploadScreen(): void
    {
        $this->renderPageMetaSlots();
        ?>
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

		<div id="wicket-import-csv" class="wicket-importer-upload-section">
			<?php
            $rest = $this->restBase();
        /*
         * CSV upload section (Task 8). Drag-and-drop zone + click-to-browse +
         * file preview, bound by admin.js. The confirm button POSTs to
         * /import/upload via fetch and redirects to the validation screen.
         */
        ?>
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
			</div>
		</div>

		<div id="wicket-import-manual" class="wicket-importer-upload-section" hidden>
			<?php $this->renderIndividualForm(); ?>
		</div>
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
				<button
					type="button"
					class="button button-primary wicket-importer-proceed"
					data-session-id="<?php echo esc_attr($sessionId); ?>"
					data-run-url="<?php echo esc_url($rest . '/session/' . $sessionId . '/run'); ?>"
				>
					<?php esc_html_e('Proceed with Valid Rows', 'wicket-wp-importer'); ?>
				</button>
				<button
					type="button"
					class="button wicket-importer-restart"
					data-session-id="<?php echo esc_attr($sessionId); ?>"
					data-clear-url="<?php echo esc_url($rest . '/session/' . $sessionId); ?>"
					data-upload-url="<?php echo esc_url($this->uploadScreenUrl()); ?>"
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
        $review = $counts['needs_review'] ?? 0;

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
						<th scope="row"><?php echo esc_html((string) ($rowIndex + 1)); ?></th>
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
						<th scope="row"><?php echo esc_html((string) ($rowIndex + 1)); ?></th>
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
     * Status badge for a validation / import status string (Task 9.2).
     *
     * Shared by both the validation table (validation_status) and the
     * confirmation results table (import_status) once Task 10 lands — kept
     * here so both surfaces use one status -> label/variant mapping.
     *
     * @param string $status validation_status or import_status value.
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
        $userRows = $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.display_name
			 FROM {$usersTable} u
			 INNER JOIN {$batchesTable} b ON b.created_by_user_id = u.ID
			 ORDER BY u.display_name ASC"
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
			ORDER BY b.created_at DESC
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
        $baseUrl = add_query_arg(
            $filterArgs,
            admin_url('admin.php?page=wicket-wp-importer&tab=history')
        );
        ?>
		<div class="wicket-importer-history">
			<form method="get" class="wicket-importer-history-filters">
				<input type="hidden" name="page" value="wicket-wp-importer">
				<input type="hidden" name="tab" value="history">

				<label>
					<span><?php esc_html_e('Status', 'wicket-wp-importer'); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e('Any', 'wicket-wp-importer'); ?></option>
						<?php foreach (['pending', 'running', 'completed', 'failed'] as $opt) : ?>
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
							<th><?php esc_html_e('Started', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('File', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('User', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Status', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Rows', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Phase 1', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Phase 2', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Duration', 'wicket-wp-importer'); ?></th>
							<th><?php esc_html_e('Finished', 'wicket-wp-importer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $row) :
						    $detailUrl = add_query_arg('batch_id', $row->batch_id, $baseUrl);
						    $phase1 = sprintf('%d / %d / %d', (int) $row->phase1_succeeded, (int) $row->phase1_failed, (int) $row->phase1_needs_review);
						    $phase2 = sprintf('%d / %d / %d', (int) $row->phase2_succeeded, (int) $row->phase2_failed, (int) $row->phase2_needs_review);
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
								<td><?php echo esc_html($phase1); ?></td>
								<td><?php echo esc_html($phase2); ?></td>
								<td><?php echo esc_html($duration); ?></td>
								<td><?php echo esc_html($finished); ?></td>
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
					<tr><th><?php esc_html_e('Finished', 'wicket-wp-importer'); ?></th><td><?php echo esc_html($batch->finished_at ? mysql2date('Y-m-d H:i', $batch->finished_at) : '—'); ?></td></tr>
					<tr><th><?php esc_html_e('Duration', 'wicket-wp-importer'); ?></th><td><?php echo esc_html(self::formatDuration((string) $batch->created_at, (string) $batch->finished_at)); ?></td></tr>
				</tbody>
			</table>

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
									<div><strong><?php echo esc_html($row->validation_status); ?></strong></div>
									<?php if ($row->validation_message) : ?>
										<div class="wicket-importer-history-msg"><?php echo esc_html($row->validation_message); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<div><strong><?php echo esc_html($row->import_status); ?></strong></div>
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
        if (strlen($s) <= $limit) {
            return $s;
        }
        $cut = substr($s, 0, $limit);
        $nl = strrpos($cut, "\n");
        if ($nl !== false && $nl > $limit / 2) {
            $cut = substr($cut, 0, $nl);
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
     * Current session_id from $_GET['session_id']. Null when absent or malformed.
     */
    private function currentSessionId(): ?string
    {
        if (!isset($_GET['session_id'])) {
            return null;
        }
        $id = sanitize_key(wp_unslash($_GET['session_id']));

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
