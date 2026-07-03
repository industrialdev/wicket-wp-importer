<?php
declare(strict_types=1);

namespace WicketImporter\Admin;

use WicketImporter\Support\ColumnOrder;
use WicketImporter\Support\Json;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\WicketImporter as Plugin;

/**
 * Admin page for the importer (Task 7).
 *
 * Registered as a submenu under the Wicket parent (parent_slug: wicket-settings)
 * per AD9. Renders one of three screens on a single page, selected by
 * $_GET['screen'] and session state:
 *
 *   upload        CSV dropzone (Task 8) + individual form (Task 11) + meta slots
 *   validation    Summary bar + flagged rows table + Proceed/Restart (Task 9)
 *   confirmation  Results summary + per-row table + meta re-fire (Task 10)
 *
 * The page chrome (wrap, heading, REST config) is shared; each screen renders
 * into a container the follow-up tasks target. Meta slots come from the
 * wicket_import_upload_page_meta filter and render on BOTH the upload screen
 * and the confirmation screen (re-fired so post-import state is reflected,
 * e.g. OBA's "Next Bar ID").
 *
 * @see docs/engineering/rest-endpoints.md
 */
class ImportAdminPage
{
	use SecuresRequests;

	public const SCREEN_UPLOAD        = 'upload';
	public const SCREEN_VALIDATION    = 'validation';
	public const SCREEN_CONFIRMATION  = 'confirmation';

	/**
	 * Screens the router accepts. Anything else falls back to upload.
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
		add_action( 'admin_menu', [ $this, 'registerMenu' ], 21 );
	}

	/**
	 * Register the admin menu page (Task 7.3).
	 *
	 * Under the Wicket parent (wicket-settings) per AD9. manage_options gated.
	 */
	public function registerMenu(): void
	{
		add_submenu_page(
			'wicket-settings',
			__( 'Importer', 'wicket-wp-importer' ),
			__( 'Importer', 'wicket-wp-importer' ),
			'manage_options',
			'wicket-wp-importer',
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Render the admin page (Task 7.1).
	 *
	 * Dispatches to the screen selected by $_GET['screen'] (default: upload).
	 * Shared chrome wraps every screen so Tasks 8-11 only own their container.
	 */
	public function renderPage(): void
	{
		$this->requireCapability();

		$screen    = $this->currentScreen();
		$sessionId = $this->currentSessionId();

		$this->renderChromeOpen( $screen );
		$this->renderScreenNotices();

		switch ( $screen ) {
			case self::SCREEN_VALIDATION:
				$this->renderValidationScreen( $sessionId );
				break;
			case self::SCREEN_CONFIRMATION:
				$this->renderConfirmationScreen( $sessionId );
				break;
			case self::SCREEN_UPLOAD:
			default:
				$this->renderUploadScreen();
				break;
		}

		$this->renderChromeClose();
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
				<span><?php esc_html_e( 'CSV File', 'wicket-wp-importer' ); ?></span>
			</label>
			<label class="wicket-importer-toggle">
				<input type="radio" name="wicket_import_upload_type" value="manual">
				<span><?php esc_html_e( 'Manual Entry', 'wicket-wp-importer' ); ?></span>
			</label>
		</div>

		<div id="wicket-import-csv" class="wicket-importer-upload-section">
			<?php
			$rest = $this->restBase();
			/**
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
				aria-label="<?php esc_attr_e( 'Upload CSV file', 'wicket-wp-importer' ); ?>"
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
					<?php esc_html_e( 'Drop CSV file here, or click to browse', 'wicket-wp-importer' ); ?>
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
					data-upload-url="<?php echo esc_url( $rest . '/upload' ); ?>"
					data-validation-url="<?php echo esc_url( $this->validationScreenUrl() ); ?>"
				>
					<?php esc_html_e( 'Validate & Upload', 'wicket-wp-importer' ); ?>
				</button>
				<button
					type="button"
					class="button wicket-importer-clear-btn"
					aria-label="<?php esc_attr_e( 'Clear selected file', 'wicket-wp-importer' ); ?>"
				>×</button>
			</div>

			<div class="wicket-importer-actions">
				<a class="button wicket-importer-template-btn" href="<?php echo esc_url( wp_nonce_url( $rest . '/template', 'wp_rest', '_wpnonce' ) ); ?>">
					<?php esc_html_e( 'Download CSV template', 'wicket-wp-importer' ); ?>
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
	private function renderValidationScreen( ?string $sessionId ): void
	{
		$rest = $this->restBase();

		// No session: bounce to upload. The JS flow always passes a session_id,
		// but a direct hit / stale link should not render an empty screen.
		if ( $sessionId === null ) {
			$this->renderMissingSession();
			return;
		}

		$counts = Plugin::get_instance()->StagingTable()->getValidationSummary( $sessionId );
		$total  = array_sum( $counts );
		if ( $total === 0 ) {
			$this->renderMissingSession( $sessionId );
			return;
		}

		$valid     = $counts['valid'] ?? 0;
		$flagged   = ( $counts['invalid'] ?? 0 ) + ( $counts['warning'] ?? 0 );
		$duplicate = $counts['duplicate'] ?? 0;
		?>
		<div class="wicket-importer-validation" data-session-id="<?php echo esc_attr( $sessionId ); ?>">

			<div class="wicket-importer-summary-bar">
				<span class="wicket-importer-summary-stat is-valid">
					<?php
					echo esc_html(
						sprintf(
						/* translators: %d: valid row count */
							_n( '%d valid', '%d valid', $valid, 'wicket-wp-importer' ),
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
							_n( '%d flagged', '%d flagged', $flagged, 'wicket-wp-importer' ),
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
							_n( '%d duplicate', '%d duplicates', $duplicate, 'wicket-wp-importer' ),
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
			$this->renderFlaggedTable( $sessionId );
			?>

			<div class="wicket-importer-actions">
				<button
					type="button"
					class="button button-primary wicket-importer-proceed"
					data-session-id="<?php echo esc_attr( $sessionId ); ?>"
					data-run-url="<?php echo esc_url( $rest . '/session/' . $sessionId . '/run' ); ?>"
				>
					<?php esc_html_e( 'Proceed with Valid Rows', 'wicket-wp-importer' ); ?>
				</button>
				<button
					type="button"
					class="button wicket-importer-restart"
					data-session-id="<?php echo esc_attr( $sessionId ); ?>"
					data-clear-url="<?php echo esc_url( $rest . '/session/' . $sessionId ); ?>"
					data-upload-url="<?php echo esc_url( $this->uploadScreenUrl() ); ?>"
				>
					<?php esc_html_e( 'Restart Upload', 'wicket-wp-importer' ); ?>
				</button>
				<a
					class="button"
					href="<?php echo esc_url( wp_nonce_url( $rest . '/session/' . $sessionId . '/flagged-csv', 'wp_rest', '_wpnonce' ) ); ?>"
				>
					<?php esc_html_e( 'Download flagged rows', 'wicket-wp-importer' ); ?>
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
	private function renderConfirmationScreen( ?string $sessionId ): void
	{
		$rest = $this->restBase();

		if ( $sessionId === null ) {
			$this->renderMissingSession();
			return;
		}

		$counts = Plugin::get_instance()->StagingTable()->getImportSummary( $sessionId );
		$total  = array_sum( $counts );
		if ( $total === 0 ) {
			$this->renderMissingSession( $sessionId );
			return;
		}

		$succeeded = ( $counts['imported'] ?? 0 ) + ( $counts['updated'] ?? 0 );
		$failed    = $counts['failed'] ?? 0;
		$review    = $counts['needs_review'] ?? 0;

		// Re-fire meta so post-import state (e.g. Next Bar ID) is reflected.
		$this->renderPageMetaSlots();
		?>
		<div class="wicket-importer-confirmation" data-session-id="<?php echo esc_attr( $sessionId ); ?>">

			<div class="wicket-importer-summary-bar">
				<span class="wicket-importer-summary-stat">
					<?php
					echo esc_html(
						sprintf(
							_n( '%d processed', '%d processed', $total, 'wicket-wp-importer' ),
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
							_n( '%d succeeded', '%d succeeded', $succeeded, 'wicket-wp-importer' ),
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
							_n( '%d failed', '%d failed', $failed, 'wicket-wp-importer' ),
							$failed
						)
					);
					?>
				</span>
				<?php if ( $review > 0 ) : ?>
					<span class="wicket-importer-summary-sep" aria-hidden="true">&middot;</span>
					<span class="wicket-importer-summary-stat is-review">
						<?php
						echo esc_html(
							sprintf(
								_n( '%d needs review', '%d need review', $review, 'wicket-wp-importer' ),
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
			$this->renderResultsTable( $sessionId );
			?>

			<div class="wicket-importer-actions">
				<a
					class="button"
					href="<?php echo esc_url( wp_nonce_url( $rest . '/session/' . $sessionId . '/results-csv', 'wp_rest', '_wpnonce' ) ); ?>"
				>
					<?php esc_html_e( 'Download full results', 'wicket-wp-importer' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( $this->uploadScreenUrl() ); ?>">
					<?php esc_html_e( 'Create new Import', 'wicket-wp-importer' ); ?>
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
	private function renderFlaggedTable( string $sessionId ): void
	{
		$rows = Plugin::get_instance()->StagingTable()->getFlaggedBySession( $sessionId );

		if ( $rows === [] ) {
			?>
			<div id="wicket-import-flagged-table" class="wicket-importer-placeholder">
				<?php esc_html_e( 'No flagged rows. Every row is valid.', 'wicket-wp-importer' ); ?>
			</div>
			<?php
			return;
		}

		// Column order: registered wicket_import_csv_columns first, then any
		// extra row keys (shared helper, same logic as the CSV exports).
		$columns = ColumnOrder::forRows( $rows );
		?>
		<div id="wicket-import-flagged-table" class="wicket-importer-table-wrap">
			<table class="widefat striped wicket-importer-flagged-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Rows that failed validation, with the offending fields and reasons.', 'wicket-wp-importer' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'wicket-wp-importer' ); ?></th>
						<?php foreach ( $columns as $key ) : ?>
							<th scope="col"><?php echo esc_html( $key ); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e( 'Status', 'wicket-wp-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'wicket-wp-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$rowIndex   = (int) ( $row['row_index'] ?? 0 );
					$data       = Json::decodeArray( $row['raw_data'] ?? null );
					$status     = (string) ( $row['validation_status'] ?? '' );
					$message    = (string) ( $row['validation_message'] ?? '' );
					$flaggedSet = array_flip( Json::decodeArray( $row['flagged_fields'] ?? null ) );
				?>
					<tr>
						<th scope="row"><?php echo esc_html( (string) ( $rowIndex + 1 ) ); ?></th>
						<?php foreach ( $columns as $key ) :
							$value   = $data[ $key ] ?? '';
							$flagged = isset( $flaggedSet[ $key ] );
							$cls     = $flagged ? 'wicket-importer-cell-flagged' : '';
						?>
							<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( (string) $value ); ?></td>
						<?php endforeach; ?>
						<td><?php $this->renderStatusBadge( $status ); ?></td>
						<td><?php echo esc_html( $message ); ?></td>
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
	private function renderResultsTable( string $sessionId ): void
	{
		$rows = Plugin::get_instance()->StagingTable()->getBySession( $sessionId );

		if ( $rows === [] ) {
			?>
			<div id="wicket-import-results-table" class="wicket-importer-placeholder">
				<?php esc_html_e( 'No rows in this session.', 'wicket-wp-importer' ); ?>
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
		$extColumns = apply_filters( 'wicket_import_confirmation_columns', [] );
		$extColumns = is_array( $extColumns ) ? $extColumns : [];
		?>
		<div id="wicket-import-results-table" class="wicket-importer-table-wrap">
			<table class="widefat striped wicket-importer-results-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Import results: one row per staged record with its outcome.', 'wicket-wp-importer' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'wicket-wp-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'wicket-wp-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'wicket-wp-importer' ); ?></th>
						<?php foreach ( $extColumns as $col ) :
							$label = isset( $col['label'] ) ? (string) $col['label'] : '';
							if ( $label === '' ) { continue; }
						?>
							<th scope="col"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e( 'MDP UUID', 'wicket-wp-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'wicket-wp-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'wicket-wp-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$rowIndex = (int) ( $row['row_index'] ?? 0 );
					$data    = Json::decodeArray( $row['raw_data'] ?? null );
					// Name extraction uses first_name/last_name keys (the core column
					// contract). Extensions using different keys (e.g. given_name /
					// family_name) would render an empty Name cell here; they should
					// register a wicket_import_confirmation_columns extractor instead.
					$name    = trim( (string) ( $data['first_name'] ?? '' ) . ' ' . (string) ( $data['last_name'] ?? '' ) );
					$email   = (string) ( $data['email'] ?? '' );
					$status  = (string) ( $row['import_status'] ?? '' );
					$message = (string) ( $row['import_message'] ?? '' );
					$uuid    = isset( $row['mdp_uuid'] ) ? (string) $row['mdp_uuid'] : '';

					// Shaped row handed to extension extractors: the raw CSV data
					// plus the decoded extension_metadata blob, matching the REST
					// /results endpoint shape so extractors behave identically.
					$shaped = [
						'data'               => $data,
						'validation_status'  => (string) ( $row['validation_status'] ?? '' ),
						'import_status'      => $status,
						'import_message'     => $message,
						'mdp_uuid'           => $uuid !== '' ? $uuid : null,
						'extension_metadata' => Json::decodeArray( $row['extension_metadata'] ?? null ),
					];
				?>
					<tr>
						<th scope="row"><?php echo esc_html( (string) ( $rowIndex + 1 ) ); ?></th>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $email ); ?></td>
						<?php foreach ( $extColumns as $col ) :
							if ( empty( $col['label'] ) ) { continue; }
							$value = '';
							$link  = '';
							try {
								if ( isset( $col['extractor'] ) && is_callable( $col['extractor'] ) ) {
									$extracted = $col['extractor']( $shaped );
									$value = ( is_scalar( $extracted ) || $extracted === null ) ? (string) ( $extracted ?? '' ) : '';
								}
								if ( isset( $col['link_extractor'] ) && is_callable( $col['link_extractor'] ) ) {
									$extractedLink = $col['link_extractor']( $shaped );
									$link = ( is_scalar( $extractedLink ) || $extractedLink === null ) ? (string) ( $extractedLink ?? '' ) : '';
								}
							} catch ( \Throwable $e ) {
								// Isolate extension callback failures so one buggy extractor
								// can't fatal the whole confirmation screen.
								Plugin::get_instance()->Logger()->warning(
									'Confirmation column extractor threw; skipping cell.',
									[ 'label' => $col['label'] ?? '', 'error' => $e->getMessage() ]
								);
							}
						?>
							<td><?php
							if ( $value !== '' && $link !== '' ) {
								printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $link ), esc_html( $value ) );
							} else {
								echo esc_html( $value );
							}
							?></td>
						<?php endforeach; ?>
						<td><?php echo $uuid !== '' ? esc_html( $uuid ) : '&mdash;'; ?></td>
						<td><?php $this->renderStatusBadge( $status ); ?></td>
						<td><?php echo esc_html( $message ); ?></td>
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
			[ 'name' => 'first_name', 'label' => __( 'First Name', 'wicket-wp-importer' ), 'type' => 'text', 'required' => true ],
			[ 'name' => 'last_name',  'label' => __( 'Last Name', 'wicket-wp-importer' ),  'type' => 'text', 'required' => true ],
			[ 'name' => 'email',      'label' => __( 'Email', 'wicket-wp-importer' ),      'type' => 'email', 'required' => false ],
			[ 'name' => 'phone',      'label' => __( 'Phone', 'wicket-wp-importer' ),      'type' => 'tel', 'required' => false ],
			[ 'name' => 'address',    'label' => __( 'Address', 'wicket-wp-importer' ),    'type' => 'text', 'required' => false ],
			[ 'name' => 'city',       'label' => __( 'City', 'wicket-wp-importer' ),       'type' => 'text', 'required' => false ],
			[ 'name' => 'state',      'label' => __( 'State', 'wicket-wp-importer' ),      'type' => 'text', 'required' => false ],
			[ 'name' => 'zip',        'label' => __( 'ZIP', 'wicket-wp-importer' ),        'type' => 'text', 'required' => false ],
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
		$extFields = apply_filters( 'wicket_import_individual_form_fields', [], [ 'context' => 'individual' ] );
		$extFields = is_array( $extFields ) ? $extFields : [];

		// Membership Tier selector (core field, but guarded: wicket-wp-memberships
		// may not be active, and the CPT slug is extension-supplied via the filter
		// so core doesn't hardcode a sibling plugin's post type).
		$tierPostType = apply_filters( 'wicket_import_membership_tier_post_type', 'wicket_mship_tier' );
		$tiers = [];
		if ( is_string( $tierPostType ) && $tierPostType !== '' && post_type_exists( $tierPostType ) ) {
			$tiers = get_posts( [
				'post_type'      => $tierPostType,
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			] );
		}
		?>
		<form id="wicket-import-individual-form" class="wicket-importer-individual-form" method="post">
			<div class="wicket-importer-form-grid">
				<?php foreach ( $coreFields as $field ) : ?>
					<?php $this->renderFormField( $field ); ?>
				<?php endforeach; ?>

				<?php if ( $tiers ) : ?>
					<div class="wicket-importer-form-field">
						<label for="wicket-import-tier"><?php esc_html_e( 'Membership Tier', 'wicket-wp-importer' ); ?></label>
						<select name="membership_tier" id="wicket-import-tier">
							<option value=""><?php esc_html_e( '— Select —', 'wicket-wp-importer' ); ?></option>
							<?php foreach ( $tiers as $tier ) : ?>
								<option value="<?php echo esc_attr( (string) $tier->ID ); ?>"><?php echo esc_html( $tier->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php foreach ( $extFields as $field ) : ?>
					<?php $this->renderFormField( $field ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wicket-importer-actions">
				<button
					type="submit"
					class="button button-primary wicket-importer-individual-submit"
					data-individual-url="<?php echo esc_url( $rest . '/individual' ); ?>"
					data-confirmation-url="<?php echo esc_url( $this->confirmationScreenUrl() ); ?>"
				><?php esc_html_e( 'Validate & Upload Member', 'wicket-wp-importer' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render a single form field (Task 11.1 helper, reused by extension fields).
	 *
	 * @param array $field ['name'=>str, 'label'=>str, 'type'=>str, 'required'=>bool, 'options'=>?, 'placeholder'=>?]
	 */
	private function renderFormField( array $field ): void
	{
		$name        = (string) ( $field['name'] ?? '' );
		$label       = (string) ( $field['label'] ?? $name );
		$type        = (string) ( $field['type'] ?? 'text' );
		$required    = ! empty( $field['required'] );
		$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$placeholder = (string) ( $field['placeholder'] ?? '' );
		$id          = 'wicket-import-field-' . sanitize_key( $name );

		if ( $name === '' ) {
			return;
		}
		?>
		<div class="wicket-importer-form-field">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<?php if ( $type === 'select' && $options ) : ?>
				<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>"<?php echo $required ? ' required' : ''; ?>>
					<option value=""><?php esc_html_e( '— Select —', 'wicket-wp-importer' ); ?></option>
					<?php foreach ( $options as $opt ) : ?>
						<option value="<?php echo esc_attr( (string) ( $opt['value'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $opt['label'] ?? '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( $type === 'textarea' ) : ?>
				<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>"<?php echo $required ? ' required' : ''; ?><?php echo $placeholder !== '' ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?> rows="3"></textarea>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					<?php echo $required ? ' required' : ''; ?>
					<?php echo $placeholder !== '' ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>
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
		return esc_url_raw( admin_url( 'admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_CONFIRMATION ) );
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
	private function renderStatusBadge( string $status ): void
	{
		$labels = [
			'invalid'                  => [ __( 'Invalid', 'wicket-wp-importer' ), 'error' ],
			'duplicate'                => [ __( 'Duplicate', 'wicket-wp-importer' ), 'warning' ],
			'warning'                  => [ __( 'Warning', 'wicket-wp-importer' ), 'warning' ],
			'email_conflict'           => [ __( 'Email conflict', 'wicket-wp-importer' ), 'error' ],
			'skipped_active_membership'=> [ __( 'Active member', 'wicket-wp-importer' ), 'warning' ],
			'needs_review'             => [ __( 'Needs review', 'wicket-wp-importer' ), 'warning' ],
			'failed'                   => [ __( 'Failed', 'wicket-wp-importer' ), 'error' ],
			'imported'                 => [ __( 'Imported', 'wicket-wp-importer' ), 'success' ],
			'updated'                  => [ __( 'Updated', 'wicket-wp-importer' ), 'success' ],
			'skipped'                  => [ __( 'Skipped', 'wicket-wp-importer' ), 'neutral' ],
			'pending'                  => [ __( 'Pending', 'wicket-wp-importer' ), 'neutral' ],
			'processing'               => [ __( 'Processing', 'wicket-wp-importer' ), 'info' ],
		];

		$label = $labels[ $status ] ?? [ $status, 'info' ];
		printf(
			'<span class="wicket-importer-badge wicket-importer-badge-%s">%s</span>',
			esc_attr( $label[1] ),
			esc_html( $label[0] )
		);
	}



	// ---------------------------------------------------------------------
	// Shared chrome + helpers
	// ---------------------------------------------------------------------

	/**
	 * Page chrome wrapper open. The data-screen attribute lets admin.js bind
	 * screen-specific handlers without parsing the URL.
	 */
	private function renderChromeOpen( string $screen ): void
	{
		?>
		<div class="wrap wicket-importer" data-screen="<?php echo esc_attr( $screen ); ?>">
			<h1><?php esc_html_e( 'Wicket Import', 'wicket-wp-importer' ); ?></h1>
		<?php
	}

	private function renderChromeClose(): void
	{
		?>
		</div>
		<?php
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
		$meta = apply_filters( 'wicket_import_upload_page_meta', [] );

		if ( ! is_array( $meta ) || $meta === [] ) {
			return;
		}

		?>
		<div class="wicket-importer-meta">
			<?php foreach ( $meta as $slot ) :
				$label = isset( $slot['label'] ) ? (string) $slot['label'] : '';
				$value = isset( $slot['value'] ) ? (string) $slot['value'] : '';
				if ( $label === '' && $value === '' ) {
					continue;
				}
				?>
				<div class="wicket-importer-meta-slot">
					<?php if ( $label !== '' ) : ?>
						<span class="wicket-importer-meta-label"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
					<span class="wicket-importer-meta-value"><?php echo esc_html( $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Rendered when a screen is hit without a resolvable session. Keeps the
	 * page usable (a Create New Import link) rather than blank.
	 */
	private function renderMissingSession( ?string $sessionId = null ): void
	{
		?>
		<div class="wicket-importer-missing-session notice inline notice-warning">
			<p>
				<?php
				if ( $sessionId !== null ) {
					esc_html_e( 'No rows found for that session. It may have been cleared or completed.', 'wicket-wp-importer' );
				} else {
					esc_html_e( 'No active import session. Start a new upload.', 'wicket-wp-importer' );
				}
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->uploadScreenUrl() ); ?>">
					<?php esc_html_e( 'Start new Import', 'wicket-wp-importer' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Current screen from $_GET['screen']. Defaults to upload.
	 */
	private function currentScreen(): string
	{
		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : '';
		return in_array( $screen, self::ALLOWED_SCREENS, true ) ? $screen : self::SCREEN_UPLOAD;
	}

	/**
	 * Current session_id from $_GET['session_id']. Null when absent or malformed.
	 */
	private function currentSessionId(): ?string
	{
		if ( ! isset( $_GET['session_id'] ) ) {
			return null;
		}
		$id = sanitize_key( wp_unslash( $_GET['session_id'] ) );
		return preg_match( self::SESSION_ID_PATTERN, $id ) === 1 ? $id : null;
	}

	/**
	 * REST namespace base URL for endpoint linking. Used by the data-* URL
	 * attributes so admin.js doesn't hardcode endpoints.
	 */
	private function restBase(): string
	{
		return esc_url_raw( rest_url( WICKET_IMPORT_REST_NAMESPACE . '/import' ) );
	}

	/**
	 * Admin URL for the upload screen (clean params). Used by Restart / Create
	 * New buttons to drop the session param and return to a fresh upload.
	 */
	private function uploadScreenUrl(): string
	{
		return esc_url_raw( admin_url( 'admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_UPLOAD ) );
	}

	/**
	 * Admin URL for the validation screen. The session_id is appended by JS
	 * after upload (it isn't known at render time). Shipped as a base URL with
	 * a %s placeholder the JS fills, OR built without a session for fallback.
	 */
	private function validationScreenUrl(): string
	{
		return esc_url_raw( admin_url( 'admin.php?page=wicket-wp-importer&screen=' . self::SCREEN_VALIDATION ) );
	}
}
