<?php
declare(strict_types=1);

namespace WicketImporter\BulkImport\Rest;

use WicketImporter\Support\ColumnOrder;
use WicketImporter\Support\CsvExporter;
use WicketImporter\Support\Json;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\ValueObjects\ColumnDefinition;
use WicketImporter\ValueObjects\CsvRow;
use WicketImporter\ValueObjects\ValidationResult;
use WicketImporter\ValueObjects\ValidationSummary;
use WicketImporter\WicketImporter as Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for the Phase 1 upload / validation / session endpoints
 * plus the Phase 2 destructive run endpoint.
 *
 * All routes live under the wicket/v1 namespace and require manage_options.
 *
 *   POST   /import/upload                      Parse, validate, stage a CSV.
 *   GET    /import/template                    Download CSV template (Task 8.3).
 *   GET    /import/session/{id}                Validation summary counts.
 *   GET    /import/session/{id}/flagged        Flagged rows with reasons.
 *   GET    /import/session/{id}/flagged-csv    Flagged rows CSV (AD14).
 *   GET    /import/session/{id}/results        All rows with import results.
 *   GET    /import/session/{id}/results-csv    Full results CSV (AD14).
 *   POST   /import/session/{id}/run            Conflict pre-pass + destructive import.
 *   DELETE /import/session/{id}                Clear the session.
 *
 * Size enforcement is delegated to FileParserService (single enforcement point,
 * via the wicket_import_max_file_size filter); this endpoint only maps the
 * outcome to HTTP 413 using ParseResult::hasSizeError().
 */
final class UploadController
{
	use SecuresRequests;

	/**
	 * UUID v4 (36 chars). wp_generate_uuid4() produces this shape.
	 */
	private const SESSION_ID_PATTERN = '(?P<id>[0-9a-fA-F-]{36})';

	public function __construct()
	{
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * Register all Phase 1 routes.
	 */
	public function registerRoutes(): void
	{
		$namespace = WICKET_IMPORT_REST_NAMESPACE;
		$permission = [ $this, 'restPermissionCheck' ];
		$sessionBase = '/import/session/' . self::SESSION_ID_PATTERN;

		register_rest_route( $namespace, '/import/upload', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handleUpload' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, '/import/template', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleTemplate' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, '/import/individual', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handleIndividual' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleSessionSummary' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase . '/run', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handleRun' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase, [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'handleSessionDelete' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase . '/flagged', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleFlagged' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase . '/flagged-csv', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleFlaggedCsv' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase . '/results', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleResults' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( $namespace, $sessionBase . '/results-csv', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleResultsCsv' ],
			'permission_callback' => $permission,
		] );
	}

	/**
	 * POST /import/upload — receive CSV, parse, validate, stage.
	 *
	 * Returns session_id + row counts. HTTP 400 (parse/no file), 413 (size),
	 * 415 (type). Size enforcement is delegated to FileParserService.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handleUpload( WP_REST_Request $request )
	{
		// Concurrency gate (plan DB-schema): the active-session check fires before
		// each upload so two uploads can't interleave pending rows. Reject with 409
		// until the prior session is cleared (DELETE /session/{id}) or finishes.
		if ( Plugin::get_instance()->StagingTable()->hasActiveSession() ) {
			return $this->error(
				'import_session_active',
				__( 'An import session is already in progress. Clear or complete it before starting a new upload.', 'wicket-wp-importer' ),
				409
			);
		}

		$file = $request->get_file_params()['file'] ?? null;

		if ( ! is_array( $file ) || ! isset( $file['error'] ) ) {
			return $this->error( 'import_no_file', __( 'No CSV file uploaded.', 'wicket-wp-importer' ), 400 );
		}

		// PHP-level upload errors: size overruns -> 413, everything else -> 400.
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			if ( in_array( $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
				return $this->error( 'import_too_large', __( 'The uploaded file exceeds the maximum size.', 'wicket-wp-importer' ), 413 );
			}
			return $this->error( 'import_upload_failed', __( 'The upload failed.', 'wicket-wp-importer' ), 400 );
		}

		// wp_handle_upload lives in wp-admin/includes/file.php (not always loaded in REST).
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Canonical uploader: streams the file into the uploads dir with a unique
		// sanitized name. test_type is disabled because CSV has no magic bytes:
		// finfo returns 'text/plain', so WP's mime map rejects valid CSVs (false
		// 415). The type gate is the extension check below, the only reliable
		// signal for plain-text files.
		$upload = wp_handle_upload( $file, [
			'test_form' => false,
			'test_type' => false,
		] );

		if ( isset( $upload['error'] ) ) {
			return $this->error( 'import_upload_failed', $upload['error'], 400 );
		}

		$path = $upload['file'];

		// Type gate: only .csv is accepted -> 415 otherwise.
		if ( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'csv' ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
			return $this->error( 'import_bad_type', __( 'Only .csv files are accepted.', 'wicket-wp-importer' ), 415 );
		}

		try {
			$columns = $this->resolveColumns();

			$plugin     = Plugin::get_instance();
			$parse      = $plugin->FileParser()->parseFile( $path, $columns );

			if ( $parse->hasError() ) {
				return $this->error(
					$parse->hasSizeError() ? 'import_too_large' : 'import_parse_failed',
					$parse->error ?? __( 'CSV parse failed.', 'wicket-wp-importer' ),
					$parse->hasSizeError() ? 413 : 400,
					[ 'missing_headers' => $parse->missingHeaders ]
				);
			}

			$summary = $plugin->Validation()->validateBatch( $parse->rows, $columns );

			$sessionId = wp_generate_uuid4();
			$plugin->StagingTable()->insertBatch(
				$this->buildStagedRows( $parse->rows, $summary ),
				$sessionId
			);

			return new WP_REST_Response( [
				'session_id'     => $sessionId,
				'total_rows'     => $summary->total,
				'valid_count'    => $summary->validCount,
				'flagged_count'  => count( $summary->flagged ),
				'duplicate_count' => count( $summary->duplicates ),
			], 200 );
		} finally {
			// Data is in the staging table; the uploaded copy is no longer needed.
			// A failed cleanup is non-fatal (uploads dir is GC'd by WP), but surface it.
			if ( $path !== '' && is_string( $path ) && file_exists( $path ) && ! @unlink( $path ) ) {
				Plugin::get_instance()->Logger()->warning( 'Failed to delete uploaded CSV after staging.', [ 'path' => $path ] );
			}
		}
	}

	/**
	 * GET /import/session/{id} — validation summary counts by status.
	 *
	 * @return WP_REST_Response
	 */
	public function handleSessionSummary( WP_REST_Request $request )
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		$counts    = Plugin::get_instance()->StagingTable()->getValidationSummary( $sessionId );

		return new WP_REST_Response( [
			'session_id' => $sessionId,
			'total'      => array_sum( $counts ),
			'counts'     => $counts,
		], 200 );
	}

	/**
	 * POST /import/session/{id}/run — conflict pre-pass + destructive import (Task 12.9).
	 *
	 * Orchestrates the two pipeline phases an admin triggers from the validation
	 * screen's "Proceed with Valid Rows" button:
	 *   1. runConflictCheck (12.3) — populates mdp_uuid for exact matches and
	 *      flags email_conflict rows so the destructive phase skips them.
	 *      Fires the wicket_import_check_conflict filter (AD12) so OBA's
	 *      4-tier email + Bar ID logic (Task 34) can override the verdict.
	 *   2. runImport (12.4) — for each valid+pending row: resolve the MDP
	 *      person (Scenario A create / Scenario B merge), fire the
	 *      wicket_import_post_person_resolved action (12.6), resolve the tier
	 *      via wicket_import_resolve_membership_tier (12.7), then call
	 *      ImportAdapter::create() which fires the pre-membership gate (12.5),
	 *      wicket_import_create_subscription, and wicket_import_post_membership_create
	 *      (12.8). Per-row errors are caught; the batch continues.
	 *
	 * Long-running: runImport raises PHP's time + abort limits and caps the
	 * session at WICKET_IMPORT_INLINE_MAX_ROWS (200). When that cap is hit the
	 * endpoint returns 413 import_too_many_rows so the client can guide the
	 * admin to a smaller batch.
	 *
	 * Note: runValidation (12.2) is NOT called here — it is a separate
	 * defensive re-pass invoked explicitly when an extension has changed its
	 * validators. The validation that produced the current staging verdicts
	 * happened at upload time and is what the admin reviewed on the
	 * validation screen.
	 *
	 * @return WP_REST_Response|WP_Error 200 with summary; 404 if the session
	 *         has no rows; 413 if the session exceeds the inline cap; 500 on
	 *         unexpected pipeline failure.
	 */
	public function handleRun( WP_REST_Request $request )
	{
		$sessionId = (string) ( $request['id'] ?? '' );

		$plugin  = Plugin::get_instance();
		$staging = $plugin->StagingTable();

		// Cheap pre-check: bail with 404 if the session has no staged rows at
		// all (caller passed a bogus or cleared session id). Validates session
		// existence without spending a full conflict-check round-trip.
		$totalRows = array_sum( $staging->getValidationSummary( $sessionId ) );
		if ( $totalRows === 0 ) {
			return $this->error(
				'import_session_not_found',
				__( 'No staged rows found for this session.', 'wicket-wp-importer' ),
				404
			);
		}

		$pipeline = $plugin->Pipeline();

		// Concurrency guard (Task 12.9): reject re-entry while a previous /run
		// on the same session is still in-flight. The atomic claim inside
		// runImport (UPDATE pending->processing) is the durable guard; this is
		// the fast-fail layer that returns 409 before the request thread
		// spends time on runConflictCheck + a 0-row claim. Defends against
		// client double-click, JS fetch retry on slow response, and any
		// session that crashed mid-run and left rows in 'processing'.
		if ( $staging->isSessionRunning( $sessionId ) ) {
			return $this->error(
				'import_session_active',
				__( 'A previous run for this session is still in progress. Wait for it to finish or check the results screen before retrying.', 'wicket-wp-importer' ),
				409
			);
		}

		// Phase 12.3: conflict pre-pass. Always safe to re-run; idempotent
		// (exact matches overwrite mdp_uuid with the same value; partial
		// matches re-write email_conflict with the same status).
		$conflictTally = $pipeline->runConflictCheck( $sessionId );

		// Phase 12.4: destructive row loop. Returns array{summary, duration_sec}
		// on success, WP_Error on the inline-cap guard or any unrecoverable
		// pre-loop failure.
		$result = $pipeline->runImport( $sessionId );

		if ( is_wp_error( $result ) ) {
			// Map the inline-cap guard to 413 so the UI can show a distinct
			// message ("split your batch") vs a generic 500.
			$status = ( $result->get_error_code() === 'import_too_many_rows' ) ? 413 : 500;
			return $this->error( $result->get_error_code(), $result->get_error_message(), $status );
		}

		return new WP_REST_Response( [
			'session_id'      => $sessionId,
			'summary'         => $result['summary'],
			'conflict_tally'  => $conflictTally,
			'duration_sec'    => $result['duration_sec'],
		], 200 );
	}

	/**
	 * GET /import/session/{id}/flagged — flagged rows with reasons.
	 *
	 * @return WP_REST_Response
	 */
	public function handleFlagged( WP_REST_Request $request )
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		$rows      = Plugin::get_instance()->StagingTable()->getFlaggedBySession( $sessionId );

		return new WP_REST_Response( [
			'session_id' => $sessionId,
			'rows'       => array_map( [ $this, 'shapeValidationRow' ], $rows ),
		], 200 );
	}

	/**
	 * DELETE /import/session/{id} — clear the session.
	 *
	 * @return WP_REST_Response
	 */
	public function handleSessionDelete( WP_REST_Request $request )
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		Plugin::get_instance()->StagingTable()->deleteSession( $sessionId );

		return new WP_REST_Response( [
			'deleted'    => true,
			'session_id' => $sessionId,
		], 200 );
	}

	/**
	 * GET /import/session/{id}/results — all rows with import results.
	 *
	 * @return WP_REST_Response
	 */
	public function handleResults( WP_REST_Request $request )
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		$rows      = Plugin::get_instance()->StagingTable()->getBySession( $sessionId );

		return new WP_REST_Response( [
			'session_id' => $sessionId,
			'rows'       => array_map( [ $this, 'shapeResultRow' ], $rows ),
		], 200 );
	}

	/**
	 * GET /import/template — download a CSV template with extension-registered
	 * column headers (Task 8.3).
	 *
	 * The template contains one row (the headers from wicket_import_csv_columns).
	 * Each column's label is used as the header cell so the user-facing CSV
	 * matches what the alias-aware parser will accept on re-upload. Uses
	 * CsvExporter (AD14 CSV injection prevention + sanitize_file_name).
	 */
	public function handleTemplate(): never
	{
		$columns = $this->resolveColumns();
		$headers = [];
		foreach ( $columns as $column ) {
			$headers[] = $column->label !== '' ? $column->label : $column->key;
		}

		// Core registers no columns (extensions supply them); an empty template
		// would download a one-empty-row CSV with no guidance. Fail explicitly so
		// the admin knows to enable an importer extension (peer-review S4).
		if ( $headers === [] ) {
			wp_die(
				esc_html__( 'No import columns are registered. Enable an importer extension (e.g. OBA) and reload this page.', 'wicket-wp-importer' ),
				esc_html__( 'Import template', 'wicket-wp-importer' ),
				[ 'response' => 400, 'back_link' => true ]
			);
		}

		( new CsvExporter() )->download( 'import-template.csv', [ $headers ] );
	}

	/**
	 * POST /import/individual — validate + stage + import a single row from the
	 * manual entry form (Task 11.3 + 11.4).
	 *
	 * Receives the form fields as JSON, maps them to column keys, builds a
	 * single CsvRow, runs the same ValidationService pipeline as a CSV upload.
	 * On validation failure returns 400 with per-field errors so the form can
	 * show them inline (better UX than redirecting to the validation screen for
	 * one row). On success it stages the row, runs the conflict pre-pass + the
	 * destructive import inline (single row = fast), and returns the session_id
	 * so JS redirects to the confirmation screen with the single-row result.
	 *
	 * @return WP_REST_Response|WP_Error 200 {session_id} | 400 {errors} | 500.
	 */
	public function handleIndividual( WP_REST_Request $request )
	{
		$fields = $request->get_json_params();
		if ( ! is_array( $fields ) || $fields === [] ) {
			return $this->error( 'import_no_fields', __( 'No form fields received.', 'wicket-wp-importer' ), 400 );
		}

		// Sanitize: keep scalar values only (arrays/objects aren't CSV cells).
		$rowData = [];
		foreach ( $fields as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$rowData[ $key ] = trim( (string) $value );
			}
		}

		$columns = $this->resolveColumns( 'individual' );
		$plugin  = Plugin::get_instance();

		// Build a single CsvRow and run the same validation pipeline as upload.
		$csvRow  = new CsvRow( 0, $rowData, array_values( $rowData ) );
		$summary = $plugin->Validation()->validateBatch( [ $csvRow ], $columns );

		$result = $summary->resultFor( 0 );
		if ( $result && $result->status !== ValidationResult::STATUS_VALID ) {
			// Map the flagged fields to a {field_key: message} shape the JS can
			// pin to the corresponding form input. Use the per-field message map
			// (not the combined message) so each input shows its own error.
			$fieldErrors = [];
			foreach ( $result->flaggedFields as $flagged ) {
				$fieldErrors[ $flagged ] = $result->fieldMessages[ $flagged ] ?? $result->message ?? '';
			}
			return new WP_Error(
				'import_individual_invalid',
				__( 'The form has validation errors.', 'wicket-wp-importer' ),
				[ 'status' => 400, 'field_errors' => $fieldErrors ]
			);
		}

		// Valid: stage the single row, then run the full pipeline inline.
		$sessionId = wp_generate_uuid4();
		$plugin->StagingTable()->insertBatch(
			[ [
				'row_index'         => 0,
				'raw_data'          => $rowData,
				'validation_status' => ValidationResult::STATUS_VALID,
				'import_status'     => 'pending',
			] ],
			$sessionId
		);

		$pipeline = $plugin->Pipeline();
		$pipeline->runConflictCheck( $sessionId );
		$importResult = $pipeline->runImport( $sessionId );

		if ( is_wp_error( $importResult ) ) {
			return $this->error( $importResult->get_error_code(), $importResult->get_error_message(), 500 );
		}

		return new WP_REST_Response( [
			'session_id' => $sessionId,
		], 200 );
	}

	/**
	 * GET /import/session/{id}/flagged-csv — flagged rows CSV (AD14).
	 */
	public function handleFlaggedCsv( WP_REST_Request $request ): never
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		$rows      = Plugin::get_instance()->StagingTable()->getFlaggedBySession( $sessionId );

		( new CsvExporter() )->download(
			sprintf( 'import-flagged-%s.csv', $sessionId ),
			$this->buildValidationCsv( $rows )
		);
	}

	/**
	 * GET /import/session/{id}/results-csv — full results CSV (AD14).
	 */
	public function handleResultsCsv( WP_REST_Request $request ): never
	{
		$sessionId = (string) ( $request['id'] ?? '' );
		$rows      = Plugin::get_instance()->StagingTable()->getBySession( $sessionId );

		( new CsvExporter() )->download(
			sprintf( 'import-results-%s.csv', $sessionId ),
			$this->buildResultsCsv( $rows )
		);
	}

	/**
	 * Resolve CSV columns via the wicket_import_csv_columns filter.
	 * Core registers none; extensions (OBA, cheque) supply them.
	 * Context lets extensions register a different required-set for the
	 * individual form (e.g. OBA makes email optional there).
	 *
	 * @param string $context 'bulk' (default) or 'individual'.
	 *
	 * @return list<ColumnDefinition>
	 */
	private function resolveColumns( string $context = 'bulk' ): array
	{
		/** @var list<ColumnDefinition> $columns */
		$columns = apply_filters( 'wicket_import_csv_columns', [], [ 'context' => $context ] );

		return $columns;
	}

	/**
	 * Map parsed rows + their validation results into staging-table row arrays.
	 *
	 * The authoritative per-row state lives in ValidationSummary::$results
	 * (per the Task 5 audit), not in the derived $flagged/$duplicates buckets.
	 *
	 * @param list<\WicketImporter\ValueObjects\CsvRow> $rows
	 */
	private function buildStagedRows( array $rows, ValidationSummary $summary ): array
	{
		$staged = [];

		foreach ( $rows as $csvRow ) {
			$result = $summary->resultFor( $csvRow->rowIndex );

			$staged[] = [
				'row_index'           => $csvRow->rowIndex,
				'raw_data'            => $csvRow->data,
				'validation_status'   => $result?->status ?? ValidationResult::STATUS_VALID,
				'validation_message'  => $result?->message,
				'flagged_fields'      => $result?->flaggedFields ?? [],
				'import_status'       => 'pending',
			];
		}

		return $staged;
	}

	/**
	 * Shape a staged row for the flagged endpoint (decode JSON blobs).
	 */
	private function shapeValidationRow( array $row ): array
	{
		return [
			'line'               => ( (int) ( $row['row_index'] ?? 0 ) ) + 1,
			'data'               => Json::decodeArray( $row['raw_data'] ?? null ),
			'validation_status'  => (string) ( $row['validation_status'] ?? '' ),
			'validation_message' => (string) ( $row['validation_message'] ?? '' ),
			'flagged_fields'     => Json::decodeArray( $row['flagged_fields'] ?? null ),
		];
	}

	/**
	 * Shape a staged row for the results endpoint (decode JSON blobs).
	 */
	private function shapeResultRow( array $row ): array
	{
		$orderId = $row['order_id'] ?? null;

		return [
			'line'               => ( (int) ( $row['row_index'] ?? 0 ) ) + 1,
			'data'               => Json::decodeArray( $row['raw_data'] ?? null ),
			'validation_status'  => (string) ( $row['validation_status'] ?? '' ),
			'import_status'      => (string) ( $row['import_status'] ?? '' ),
			'import_message'     => (string) ( $row['import_message'] ?? '' ),
			'mdp_uuid'           => isset( $row['mdp_uuid'] ) ? (string) $row['mdp_uuid'] : null,
			'order_id'           => $orderId !== null && $orderId !== '' ? (int) $orderId : null,
			'subscription_ids'   => Json::decodeArray( $row['subscription_ids'] ?? null ),
			'extension_metadata' => Json::decodeArray( $row['extension_metadata'] ?? null ),
		];
	}

	/**
	 * Build the full CSV row list (headers first) for a flagged export.
	 *
	 * @param list<array<string,mixed>> $rows Staged rows.
	 * @return list<list<string>>
	 */
	private function buildValidationCsv( array $rows ): array
	{
		$dataKeys = ColumnOrder::forRows( $rows );
		$headers  = array_merge( [ 'Line' ], $dataKeys, [ 'Status', 'Reason', 'Flagged Fields' ] );

		$out = [];
		foreach ( $rows as $row ) {
			$data = Json::decodeArray( $row['raw_data'] ?? null );
			$line = [ (string) ( ( (int) ( $row['row_index'] ?? 0 ) ) + 1 ) ];

			foreach ( $dataKeys as $key ) {
				$line[] = (string) ( $data[ $key ] ?? '' );
			}

			$line[] = (string) ( $row['validation_status'] ?? '' );
			$line[] = (string) ( $row['validation_message'] ?? '' );
			$line[] = implode( ', ', Json::decodeArray( $row['flagged_fields'] ?? null ) );

			$out[] = $line;
		}

		return array_merge( [ $headers ], $out );
	}

	/**
	 * Build the full CSV row list (headers first) for a results export.
	 *
	 * @param list<array<string,mixed>> $rows Staged rows.
	 * @return list<list<string>>
	 */
	private function buildResultsCsv( array $rows ): array
	{
		$dataKeys = ColumnOrder::forRows( $rows );
		$headers  = array_merge( [ 'Line' ], $dataKeys, [ 'Import Status', 'Message', 'MDP UUID', 'Order ID', 'Subscription IDs' ] );

		$out = [];
		foreach ( $rows as $row ) {
			$data = Json::decodeArray( $row['raw_data'] ?? null );
			$line = [ (string) ( ( (int) ( $row['row_index'] ?? 0 ) ) + 1 ) ];

			foreach ( $dataKeys as $key ) {
				$line[] = (string) ( $data[ $key ] ?? '' );
			}

			$orderId = $row['order_id'] ?? null;

			$line[] = (string) ( $row['import_status'] ?? '' );
			$line[] = (string) ( $row['import_message'] ?? '' );
			$line[] = (string) ( $row['mdp_uuid'] ?? '' );
			$line[] = ( $orderId !== null && $orderId !== '' ) ? (string) $orderId : '';
			$line[] = implode( ', ', Json::decodeArray( $row['subscription_ids'] ?? null ) );

			$out[] = $line;
		}

		return array_merge( [ $headers ], $out );
	}

	/**
	 * Build a WP_Error carrying an HTTP status (WP REST honours the 'status' data key).
	 */
	private function error( string $code, string $message, int $status, array $extra = [] ): WP_Error
	{
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}
}
