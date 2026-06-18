<?php
declare(strict_types=1);

namespace WicketImporter\BulkImport\Rest;

use WicketImporter\Support\CsvExporter;
use WicketImporter\Support\SecuresRequests;
use WicketImporter\ValueObjects\ColumnDefinition;
use WicketImporter\ValueObjects\ValidationResult;
use WicketImporter\ValueObjects\ValidationSummary;
use WicketImporter\WicketImporter as Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for the Phase 1 upload / validation / session endpoints.
 *
 * All routes live under the wicket/v1 namespace and require manage_options.
 *
 *   POST   /import/upload                      Parse, validate, stage a CSV.
 *   GET    /import/session/{id}                Validation summary counts.
 *   GET    /import/session/{id}/flagged        Flagged rows with reasons.
 *   GET    /import/session/{id}/flagged-csv    Flagged rows CSV (AD14).
 *   GET    /import/session/{id}/results        All rows with import results.
 *   GET    /import/session/{id}/results-csv    Full results CSV (AD14).
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

		register_rest_route( $namespace, $sessionBase, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handleSessionSummary' ],
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
	 *
	 * @return list<ColumnDefinition>
	 */
	private function resolveColumns(): array
	{
		/** @var list<ColumnDefinition> $columns */
		$columns = apply_filters( 'wicket_import_csv_columns', [], [ 'flow' => 'bulk' ] );

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
			'data'               => $this->decodeJson( $row['raw_data'] ?? null ),
			'validation_status'  => (string) ( $row['validation_status'] ?? '' ),
			'validation_message' => (string) ( $row['validation_message'] ?? '' ),
			'flagged_fields'     => $this->decodeJson( $row['flagged_fields'] ?? null ),
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
			'data'               => $this->decodeJson( $row['raw_data'] ?? null ),
			'validation_status'  => (string) ( $row['validation_status'] ?? '' ),
			'import_status'      => (string) ( $row['import_status'] ?? '' ),
			'import_message'     => (string) ( $row['import_message'] ?? '' ),
			'mdp_uuid'           => isset( $row['mdp_uuid'] ) ? (string) $row['mdp_uuid'] : null,
			'order_id'           => $orderId !== null && $orderId !== '' ? (int) $orderId : null,
			'subscription_ids'   => $this->decodeJson( $row['subscription_ids'] ?? null ),
			'extension_metadata' => $this->decodeJson( $row['extension_metadata'] ?? null ),
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
		$dataKeys = $this->dataKeysForRows( $rows );
		$headers  = array_merge( [ 'Line' ], $dataKeys, [ 'Status', 'Reason', 'Flagged Fields' ] );

		$out = [];
		foreach ( $rows as $row ) {
			$data = $this->decodeJson( $row['raw_data'] ?? null );
			$line = [ (string) ( ( (int) ( $row['row_index'] ?? 0 ) ) + 1 ) ];

			foreach ( $dataKeys as $key ) {
				$line[] = (string) ( $data[ $key ] ?? '' );
			}

			$line[] = (string) ( $row['validation_status'] ?? '' );
			$line[] = (string) ( $row['validation_message'] ?? '' );
			$line[] = implode( ', ', $this->decodeJson( $row['flagged_fields'] ?? null ) );

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
		$dataKeys = $this->dataKeysForRows( $rows );
		$headers  = array_merge( [ 'Line' ], $dataKeys, [ 'Import Status', 'Message', 'MDP UUID', 'Order ID', 'Subscription IDs' ] );

		$out = [];
		foreach ( $rows as $row ) {
			$data = $this->decodeJson( $row['raw_data'] ?? null );
			$line = [ (string) ( ( (int) ( $row['row_index'] ?? 0 ) ) + 1 ) ];

			foreach ( $dataKeys as $key ) {
				$line[] = (string) ( $data[ $key ] ?? '' );
			}

			$orderId = $row['order_id'] ?? null;

			$line[] = (string) ( $row['import_status'] ?? '' );
			$line[] = (string) ( $row['import_message'] ?? '' );
			$line[] = (string) ( $row['mdp_uuid'] ?? '' );
			$line[] = ( $orderId !== null && $orderId !== '' ) ? (string) $orderId : '';
			$line[] = implode( ', ', $this->decodeJson( $row['subscription_ids'] ?? null ) );

			$out[] = $line;
		}

		return array_merge( [ $headers ], $out );
	}

	/**
	 * Ordered union of raw_data keys across all rows (drives CSV column order).
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return list<string>
	 */
	private function dataKeysForRows( array $rows ): array
	{
		$keys = [];

		foreach ( $rows as $row ) {
			foreach ( array_keys( $this->decodeJson( $row['raw_data'] ?? null ) ) as $key ) {
				if ( ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Decode a JSON blob from the staging table into an array (empty on miss).
	 */
	private function decodeJson( ?string $value ): array
	{
		if ( $value === null || $value === '' ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Build a WP_Error carrying an HTTP status (WP REST honours the 'status' data key).
	 */
	private function error( string $code, string $message, int $status, array $extra = [] ): WP_Error
	{
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}
}
