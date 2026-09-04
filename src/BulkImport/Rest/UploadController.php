<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Rest;

use WicketImporter\Support\ColumnOrder;
use WicketImporter\Support\CsvExporter;
use WicketImporter\Support\CsvStorage;
use WicketImporter\Support\DefaultColumns;
use WicketImporter\Support\Json;
use WicketImporter\Support\ReviewSuggester;
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
 *   POST   /import/cheque/upload             Parse, validate, stage a cheque CSV (cheque column contract).
 *   GET    /import/template                    Download CSV template (Task 8.3).
 *   GET    /import/session/{id}                Validation summary counts.
 *   GET    /import/session/{id}/flagged        Flagged rows with reasons.
 *   GET    /import/session/{id}/flagged-csv    Flagged rows CSV (AD14).
 *   GET    /import/session/{id}/results        All rows with import results.
 *   GET    /import/session/{id}/results-csv    Full results CSV (AD14).
 *   GET    /import/session/{id}/error-csv      Failed + needs_review CSV (AD14).
 *   GET    /import/session/{id}/source-csv    Retained source CSV download.
 *   POST   /import/session/{id}/run            Conflict pre-pass + destructive import.
 *   POST   /import/cheque/session/{id}/run   Trigger the cheque bulk-create batch (Action Scheduler).
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
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register all Phase 1 routes.
     */
    public function registerRoutes(): void
    {
        $namespace = WICKET_IMPORT_REST_NAMESPACE;
        $permission = [$this, 'restPermissionCheck'];
        $sessionBase = '/import/session/' . self::SESSION_ID_PATTERN;

        register_rest_route($namespace, '/import/upload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleUpload'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, '/import/template', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleTemplate'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, '/import/individual', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleIndividual'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleSessionSummary'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRun'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, '/import/cheque/upload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleChequeUpload'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, '/import/cheque/session/' . self::SESSION_ID_PATTERN . '/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleChequeRun'],
            'permission_callback' => $permission,
        ]);

        // Slice 5 (Phase 2 / payment matching) routes.
        register_rest_route($namespace, $sessionBase . '/run-phase2', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRunPhase2'],
            'permission_callback' => $permission,
        ]);
        register_rest_route($namespace, $sessionBase . '/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleProgress'],
            'permission_callback' => $permission,
        ]);
        // WWID-2439: re-arm a stalled Phase 1 chain (wp-cron missed the next
        // chunk). Refuses non-stalled batches so a live chain can never get a
        // second, concurrent chunk scheduled.
        register_rest_route($namespace, $sessionBase . '/kick', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleKick'],
            'permission_callback' => $permission,
        ]);
        register_rest_route($namespace, $sessionBase . '/retry', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRetry'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase, [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'handleSessionDelete'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/flagged', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleFlagged'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/flagged-csv', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleFlaggedCsv'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/results', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleResults'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/results-csv', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleResultsCsv'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/source-csv', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleSourceCsv'],
            'permission_callback' => $permission,
        ]);

        register_rest_route($namespace, $sessionBase . '/error-csv', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleErrorCsv'],
            'permission_callback' => $permission,
        ]);
    }

    /**
     * POST /import/upload — receive CSV, parse, validate, stage.
     *
     * Returns session_id + row counts. HTTP 400 (parse/no file), 413 (size),
     * 415 (type). Size enforcement is delegated to FileParserService.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleUpload(WP_REST_Request $request)
    {
        return $this->ingestUpload($request, $this->resolveColumns(), capAtInlineMax: true, flow: 'member');
    }

    /**
     * POST /import/cheque/upload — parse, validate, and stage a cheque CSV.
     *
     * Same pipeline as the member upload (parse -> validate -> stage -> retain
     * the source CSV), but shaped by the cheque column contract
     * (wicket_import_cheque_columns; core defaults order_total + check_id, the
     * client adds its member identifier, e.g. OBA's bar_id). No inline row cap:
     * the cheque flow processes on Action Scheduler (built for scale), so the
     * inline 200-row ceiling does not apply.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleChequeUpload(WP_REST_Request $request)
    {
        return $this->ingestUpload($request, $this->resolveChequeColumns(), capAtInlineMax: false, flow: 'cheque');
    }

    /**
     * The shared upload staging pipeline (member + cheque uploads): active-session
     * gate, file checks, parse, validate, stage, batches row, CSV retention.
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return WP_REST_Response|WP_Error
     */
    private function ingestUpload(WP_REST_Request $request, array $columns, bool $capAtInlineMax, string $flow = 'member')
    {
        // Concurrency gate (plan DB-schema): the active-session check fires before
        // each upload so two uploads can't interleave pending rows. Reject with 409
        // until the prior session is cleared (DELETE /session/{id}) or finishes.
        $activeSession = Plugin::get_instance()->StagingTable()->hasActiveSession();
        if ($activeSession !== null) {
            return $this->error(
                'import_session_active',
                __('An import session is already in progress. Clear or complete it before starting a new upload.', 'wicket-wp-importer'),
                409,
                ['blocking_session_id' => $activeSession]
            );
        }

        [$error, $parse, $path, $originalName] = $this->receiveCsv($request, $columns);
        if ($error !== null) {
            return $error;
        }

        $sessionId = '';

        try {
            $plugin = Plugin::get_instance();

            // B18: reject an oversized file at UPLOAD, not at /run. Without this
            // a large CSV stages fully then /run returns 413 forever, and the
            // staged 'pending' rows keep hasActiveSession() true so every later
            // upload is rejected with 409 until someone finds the DELETE endpoint.
            // Inline/member path only: the cheque flow runs on Action Scheduler
            // (built for scale), so it is not capped here.
            if ($capAtInlineMax && count($parse->rows) > WICKET_IMPORT_INLINE_MAX_ROWS) {
                return $this->error(
                    'import_too_many_rows',
                    sprintf(
                        __('This file has %1$d rows; the importer accepts up to %2$d per batch. Split the file and upload again.', 'wicket-wp-importer'),
                        count($parse->rows),
                        WICKET_IMPORT_INLINE_MAX_ROWS
                    ),
                    413
                );
            }

            $summary = $plugin->Validation()->validateBatch($parse->rows, $columns);

            $sessionId = wp_generate_uuid4();
            $plugin->StagingTable()->insertBatch(
                $this->buildStagedRows($parse->rows, $summary),
                $sessionId
            );

            // Record the run in the batches table so the Import History tab
            // lists member imports (BatchProcessor owns the batches writer).
            // import_flow tags the session's column contract (member vs
            // cheque) so both /run routes can reject a cross-flow trigger
            // (peer review 2026-08-21: ?flow= alone must never decide which
            // engine a session feeds).
            $plugin->BatchProcessor()->startRun($sessionId, $originalName, get_current_user_id(), $summary->total, $flow);

            return new WP_REST_Response([
                'session_id'     => $sessionId,
                'total_rows'     => $summary->total,
                'valid_count'    => $summary->validCount,
                'flagged_count'  => count($summary->flagged),
                'duplicate_count' => count($summary->duplicates),
            ], 200);
        } finally {
            // Retain the original CSV: the staged rows cannot reconstruct it
            // (the cheque spec ignores extra columns), so move it to durable,
            // download-only storage keyed by session. A failed move is non-fatal.
            if ($sessionId !== '' && $path !== '' && is_string($path) && file_exists($path) && !CsvStorage::store($path, $sessionId)) {
                Plugin::get_instance()->Logger()->warning('Failed to retain the uploaded CSV for the session.', ['path' => $path, 'session_id' => $sessionId]);
            }
        }
    }

    /**
     * Shared file reception for CSV uploads: file param checks, PHP upload
     * errors, extension gate (S1), wp_handle_upload, and parse. One security
     * surface for every upload route (member, cheque).
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return array{0: WP_REST_Response|null, 1: ParseResult|null, 2: string, 3: string}
     *         [error response, parsed result, moved file path, original filename].
     *         Exactly one of the first two is non-null.
     */
    private function receiveCsv(WP_REST_Request $request, array $columns): array
    {
        $file = $request->get_file_params()['file'] ?? null;

        if (!is_array($file) || !isset($file['error'])) {
            return [$this->error('import_no_file', __('No CSV file uploaded.', 'wicket-wp-importer'), 400), null, '', ''];
        }

        // PHP-level upload errors: size overruns -> 413, everything else -> 400.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return [$this->error('import_too_large', __('The uploaded file exceeds the maximum size.', 'wicket-wp-importer'), 413), null, '', ''];
            }

            return [$this->error('import_upload_failed', __('The upload failed.', 'wicket-wp-importer'), 400), null, '', ''];
        }

        // S1: gate the extension on the SUBMITTED name BEFORE wp_handle_upload
        // moves the file into the web-accessible uploads dir. test_type stays
        // false (CSV has no magic bytes: finfo returns text/plain, so WP's mime
        // map would reject valid CSVs) — the extension is the only reliable
        // signal, so check it before the file lands on disk, not after.
        $originalName = (string) ($file['name'] ?? '');
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            return [$this->error('import_bad_type', __('Only .csv files are accepted.', 'wicket-wp-importer'), 415), null, '', ''];
        }

        // wp_handle_upload lives in wp-admin/includes/file.php (not always loaded in REST).
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'test_type' => false,
        ]);

        if (isset($upload['error'])) {
            return [$this->error('import_upload_failed', $upload['error'], 400), null, '', ''];
        }

        $path = (string) ($upload['file'] ?? '');

        $parse = Plugin::get_instance()->FileParser()->parseFile($path, $columns, $request->get_param('delimiter'));
        if ($parse->hasError()) {
            $error = $this->error(
                $parse->hasSizeError() ? 'import_too_large' : 'import_parse_failed',
                $parse->error ?? __('CSV parse failed.', 'wicket-wp-importer'),
                $parse->hasSizeError() ? 413 : 400,
                ['missing_headers' => $parse->missingHeaders]
            );

            return [$error, null, $path, $originalName];
        }

        return [null, $parse, $path, $originalName];
    }

    /**
     * GET /import/session/{id} — validation summary counts by status.
     *
     * @return WP_REST_Response
     */
    public function handleSessionSummary(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $counts = Plugin::get_instance()->StagingTable()->getValidationSummary($sessionId);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'total'      => array_sum($counts),
            'counts'     => $counts,
        ], 200);
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
     * @return WP_REST_Response|WP_Error 200 with summary; 404 if the session
     *         has no rows; 413 if the session exceeds the inline cap; 500 on
     *         unexpected pipeline failure.
     */
    public function handleRun(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');

        $plugin = Plugin::get_instance();
        $staging = $plugin->StagingTable();

        // Cheap pre-check: bail with 404 if the session has no staged rows at
        // all (caller passed a bogus or cleared session id). Validates session
        // existence without spending a full conflict-check round-trip.
        $totalRows = array_sum($staging->getValidationSummary($sessionId));
        if ($totalRows === 0) {
            return $this->error(
                'import_session_not_found',
                __('No staged rows found for this session.', 'wicket-wp-importer'),
                404
            );
        }

        // Flow guard (peer review 2026-08-21): the inline member pipeline must
        // never run rows staged under the cheque column contract. The batch row
        // tags the session at upload time; ?flow= on the admin screen is
        // presentational only and cannot reach this decision.
        $flowBatch = $plugin->BatchProcessor()->getBatchBySession($sessionId);
        if (($flowBatch['import_flow'] ?? 'member') === 'cheque') {
            return $this->error(
                'import_wrong_flow',
                __('This session is a cheque (lockbox) upload. Run it from the Cheque Review screen.', 'wicket-wp-importer'),
                409
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
        if ($staging->isSessionRunning($sessionId)) {
            return $this->error(
                'import_session_active',
                __('A previous run for this session is still in progress. Wait for it to finish or check the results screen before retrying.', 'wicket-wp-importer'),
                409
            );
        }

        // Phase 12.3: conflict pre-pass. Always safe to re-run; idempotent
        // (exact matches overwrite mdp_uuid with the same value; partial
        // matches re-write email_conflict with the same status).
        $conflictTally = $pipeline->runConflictCheck($sessionId);

        // Phase 12.4: destructive row loop. Returns array{summary, duration_sec}
        // on success, WP_Error on the inline-cap guard or any unrecoverable
        // pre-loop failure.
        $result = $pipeline->runImport($sessionId);

        if (is_wp_error($result)) {
            // Map the inline-cap guard to 413 so the UI can show a distinct
            // message ("split your batch") vs a generic 500.
            $plugin->BatchProcessor()->finishRunBySession($sessionId, 'failed');
            $status = ($result->get_error_code() === 'import_too_many_rows') ? 413 : 500;

            return $this->error($result->get_error_code(), $result->get_error_message(), $status);
        }

        $plugin->BatchProcessor()->finishRunBySession($sessionId, 'completed');

        return new WP_REST_Response([
            'session_id'      => $sessionId,
            'summary'         => $result['summary'],
            'conflict_tally'  => $conflictTally,
            'duration_sec'    => $result['duration_sec'],
        ], 200);
    }

    /**
     * GET /import/session/{id}/flagged — flagged rows with reasons.
     *
     * @return WP_REST_Response
     */
    public function handleFlagged(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $rows = Plugin::get_instance()->StagingTable()->getFlaggedBySession($sessionId);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'rows'       => array_map([$this, 'shapeValidationRow'], $rows),
        ], 200);
    }

    /**
     * DELETE /import/session/{id} — clear the session.
     *
     * Funneled through the same BatchProcessor::clearSession guard as the
     * History admin-post handler (WWID-2437): only pending, running, and
     * pending_review batches are clearable, so a hand-built call can no
     * longer wipe a Phase 2/completed batch's audit trail. The validation
     * screen's "Restart Upload" button keeps working for clearable states.
     *
     * @return WP_REST_Response
     */
    public function handleSessionDelete(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');

        $result = Plugin::get_instance()->BatchProcessor()->clearSession($sessionId);
        if (is_wp_error($result)) {
            return $this->error(
                $result->get_error_code(),
                __($result->get_error_message(), 'wicket-wp-importer'),
                409
            );
        }

        return new WP_REST_Response([
            'deleted'    => true,
            'session_id' => $sessionId,
        ] + $result, 200);
    }

    /**
     * GET /import/session/{id}/results — all rows with import results.
     *
     * @return WP_REST_Response
     */
    public function handleResults(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $rows = Plugin::get_instance()->StagingTable()->getBySession($sessionId);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'rows'       => array_map([$this, 'shapeResultRow'], $rows),
        ], 200);
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
        // WWID-2439: ?type=cheque serves the lockbox column set. The upload
        // screen swaps the template button per import type; before this, a
        // user who selected "Cheque renewals" and downloaded "the template"
        // got the member columns and uploaded a file the cheque flow rejects.
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $columns = $type === 'cheque' ? $this->resolveChequeColumns() : $this->resolveColumns();
        $headers = [];
        foreach ($columns as $column) {
            $headers[] = $column->label !== '' ? $column->label : $column->key;
        }

        // A vanilla install seeds the universal person columns (DefaultColumns),
        // so this guard only fires when an extension explicitly empties the
        // registry. Keep it defensive — an empty template is worse than a clear
        // error pointing at the responsible extension.
        if ($headers === []) {
            wp_die(
                esc_html__('No import columns are registered. An extension may have removed them; re-enable it or contact support, then reload this page.', 'wicket-wp-importer'),
                esc_html__('Import template', 'wicket-wp-importer'),
                ['response' => 400, 'back_link' => true]
            );
        }

        (new CsvExporter())->download('import-template.csv', [$headers]);
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
    public function handleIndividual(WP_REST_Request $request)
    {
        // S3: honor the same concurrency gate as bulk upload — a pending session
        // should block an individual add too.
        $activeSession = Plugin::get_instance()->StagingTable()->hasActiveSession();
        if ($activeSession !== null) {
            return $this->error(
                'import_session_active',
                __('An import session is already in progress. Clear or complete it before adding a member.', 'wicket-wp-importer'),
                409,
                ['blocking_session_id' => $activeSession]
            );
        }

        $fields = $request->get_json_params();
        if (!is_array($fields) || $fields === []) {
            return $this->error('import_no_fields', __('No form fields received.', 'wicket-wp-importer'), 400);
        }

        // Sanitize: keep scalar values only (arrays/objects aren't CSV cells).
        $rowData = [];
        foreach ($fields as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $rowData[$key] = trim((string) $value);
            }
        }

        $columns = $this->resolveColumns('individual');
        $plugin = Plugin::get_instance();

        // Build a single CsvRow and run the same validation pipeline as upload.
        $csvRow = new CsvRow(0, $rowData, array_values($rowData));
        $summary = $plugin->Validation()->validateBatch([$csvRow], $columns);

        $result = $summary->resultFor(0);
        if ($result && $result->status !== ValidationResult::STATUS_VALID) {
            // Map the flagged fields to a {field_key: message} shape the JS can
            // pin to the corresponding form input. Use the per-field message map
            // (not the combined message) so each input shows its own error.
            $fieldErrors = [];
            foreach ($result->flaggedFields as $flagged) {
                $fieldErrors[$flagged] = $result->fieldMessages[$flagged] ?? $result->message ?? '';
            }

            return new WP_Error(
                'import_individual_invalid',
                __('The form has validation errors.', 'wicket-wp-importer'),
                ['status' => 400, 'field_errors' => $fieldErrors]
            );
        }

        // Valid: stage the single row, then run the full pipeline inline.
        $sessionId = wp_generate_uuid4();
        $plugin->StagingTable()->insertBatch(
            [[
                'row_index'         => 0,
                'raw_data'          => $rowData,
                'validation_status' => ValidationResult::STATUS_VALID,
                'import_status'     => 'pending',
            ]],
            $sessionId
        );

        $pipeline = $plugin->Pipeline();
        $pipeline->runConflictCheck($sessionId);
        $importResult = $pipeline->runImport($sessionId);

        if (is_wp_error($importResult)) {
            return $this->error($importResult->get_error_code(), $importResult->get_error_message(), 500);
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
        ], 200);
    }

    /**
     * Ordered column keys for a session's exports/tables, by the session's
     * recorded flow (WWID-2441). Cheque sessions resolve the cheque contract;
     * everything else keeps the member bulk registry. Flow comes from the
     * batch row — a client-supplied ?context= can never swap the contract
     * (same reasoning as the /run flow guards).
     *
     * Cheque columns bypass client presentation overrides: the
     * labels/order filters describe the member import and cannot distinguish
     * flows, so applying them here would relabel cheque headers with member
     * presentation (OBA hooks both globally).
     *
     * @param array<array<string,mixed>> $rows
     * @return list<string>
     */
    private function keysForSession(string $sessionId, array $rows): array
    {
        $flowBatch = Plugin::get_instance()->BatchProcessor()->getBatchBySession($sessionId);
        if (($flowBatch['import_flow'] ?? 'member') !== 'cheque') {
            return ColumnOrder::forRows($rows);
        }

        return ColumnOrder::forRows($rows, $this->resolveChequeColumns(), false);
    }

    /**
     * GET /import/session/{id}/flagged-csv — flagged rows CSV (AD14).
     */
    public function handleFlaggedCsv(WP_REST_Request $request): never
    {
        $sessionId = (string) ($request['id'] ?? '');
        $rows = Plugin::get_instance()->StagingTable()->getFlaggedBySession($sessionId);

        (new CsvExporter())->download(
            sprintf('import-flagged-%s.csv', $sessionId),
            $this->buildValidationCsv($rows, $this->keysForSession($sessionId, $rows))
        );
    }

    /**
     * GET /import/session/{id}/results-csv — full results CSV (AD14).
     */
    public function handleResultsCsv(WP_REST_Request $request): never
    {
        $sessionId = (string) ($request['id'] ?? '');
        $rows = Plugin::get_instance()->StagingTable()->getBySession($sessionId);

        (new CsvExporter())->download(
            sprintf('import-results-%s.csv', $sessionId),
            $this->buildResultsCsv($rows, $this->keysForSession($sessionId, $rows))
        );
    }

    /**
     * GET /import/session/{id}/error-csv — failed + needs_review rows CSV (AD14).
     *
     * The focused export for the cheque Review UI: only the rows a human reviews
     * (failed + needs_review), with a Suggested Fix column. Distinct from
     * results-csv (all rows) and flagged-csv (validation-flagged rows).
     */
    public function handleErrorCsv(WP_REST_Request $request): never
    {
        $sessionId = (string) ($request['id'] ?? '');
        $rows = Plugin::get_instance()->StagingTable()->getByImportStatus($sessionId, ['failed', 'needs_review', 'email_conflict', 'skipped_active_membership']);

        // WWID-2441: flow comes from the session's batch row, never a client
        // param — the old hardcoded ?context=cheque mislabeled member batches.
        (new CsvExporter())->download(
            sprintf('import-errors-%s.csv', $sessionId),
            $this->buildErrorCsv($rows, $this->keysForSession($sessionId, $rows))
        );
    }

    /**
     * GET /import/session/{id}/source-csv — download the retained source CSV.
     *
     * Streams the original uploaded file (never exposes its uploads-path URL);
     * capability-gated like the other exports. 404s when no file was retained.
     */
    public function handleSourceCsv(WP_REST_Request $request): never
    {
        $sessionId = (string) ($request['id'] ?? '');

        if (!CsvStorage::exists($sessionId)) {
            wp_die(
                esc_html__('The source CSV for this session is not available.', 'wicket-wp-importer'),
                esc_html__('Source CSV', 'wicket-wp-importer'),
                ['response' => 404, 'back_link' => true]
            );
        }

        $filename = sanitize_file_name('import-source-' . $sessionId . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize(CsvStorage::pathFor($sessionId)));

        readfile(CsvStorage::pathFor($sessionId));
        exit;
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
    private function resolveColumns(string $context = 'bulk'): array
    {
        // Delegates to the single resolution seam in ColumnOrder so the CSV
        // template, validation, admin tables, and exports all resolve columns
        // the same way (wicket_import_csv_columns -> mergeWith -> client
        // presentation overrides). See ColumnOrder::resolvedColumns().
        return ColumnOrder::resolvedColumns($context);
    }

    /**
     * POST /import/cheque/session/{id}/run — trigger the cheque bulk-create batch.
     *
     * Enqueues staged cheque rows onto Action Scheduler via
     * BatchProcessor::startBatch (single-chain chunks); unlike the member /run
     * (inline ImportPipeline), this returns immediately with a batch_id. The
     * cheque upload (column-shaped staging) ships as /import/cheque/upload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleChequeRun(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $plugin = Plugin::get_instance();
        $staging = $plugin->StagingTable();

        $total = array_sum($staging->getValidationSummary($sessionId));
        if ($total === 0) {
            return $this->error(
                'import_session_not_found',
                __('No staged rows found for this session.', 'wicket-wp-importer'),
                404
            );
        }

        // Flow guard (peer review 2026-08-21): the cheque bulk-create chain
        // must only run rows staged under the cheque column contract. Legacy
        // sessions that pre-date the import_flow column read as 'member' and
        // are rejected too: re-upload through the cheque flow.
        $flowBatch = $plugin->BatchProcessor()->getBatchBySession($sessionId);
        if (($flowBatch['import_flow'] ?? 'member') !== 'cheque') {
            return $this->error(
                'import_wrong_flow',
                __('This session is not a cheque (lockbox) upload. Use the member import flow instead.', 'wicket-wp-importer'),
                409
            );
        }

        // Re-entry guard (WWID-2437 peer review): a double-submitted run POST
        // used to start a SECOND batch row for the same session, which made
        // the Clear/Abandon buttons act on ambiguous rows. Same guard as the
        // member /run endpoint above.
        if ($staging->isSessionRunning($sessionId)) {
            return $this->error(
                'import_session_active',
                __('A previous run for this session is still in progress. Wait for it to finish or check the results screen before retrying.', 'wicket-wp-importer'),
                409
            );
        }

        // WWID-2440: reuse the upload row — inserting a second batch row here
        // orphaned the upload row at 'running'/"Awaiting import run" forever
        // (the duplicate History entry). Null = the session has no runnable
        // upload row (already run/reviewed/cleared).
        $batchId = $plugin->BatchProcessor()->startChainOnUploadBatch($sessionId);
        if ($batchId === null) {
            return $this->error(
                'cheque_run_unavailable',
                __('No runnable upload batch exists for this session; it may have run already. Start from a fresh upload.', 'wicket-wp-importer'),
                409
            );
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'batch_id'   => $batchId,
        ], 200);
    }

    /**
     * POST /import/session/{id}/run-phase2 (D-LOCKBOX-4) — transition the most
     * recent batch for the session from pending_review to phase2_running,
     * schedule the first Phase 2 chunk, return the batch_id.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleRunPhase2(WP_REST_Request $request)
    {
        if (!\WicketImporter\Admin\ChequeReviewPage::isPhase2Available()) {
            return $this->error(
                'phase2_disabled_by_client_config',
                __('Phase 2 (payment matching) is disabled by site configuration.', 'wicket-wp-importer'),
                403
            );
        }

        $sessionId = (string) ($request['id'] ?? '');
        $plugin = Plugin::get_instance();
        $batchId = $plugin->BatchProcessor()->startPhase2(
            $sessionId,
            get_current_user_id()
        );

        if ($batchId === null) {
            return $this->error(
                'phase2_not_ready',
                __('No pending_review batch for this session; Phase 1 must complete first.', 'wicket-wp-importer'),
                409
            );
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'batch_id' => $batchId,
            'status' => 'phase2_running',
        ], 200);
    }

    /**
     * GET /import/session/{id}/progress (Slice 5; widened by WWID-2439) —
     * live batch status + per-phase row counts so the admin UI can render a
     * live view instead of a static page the user must reload. Superset of
     * the original Phase 2-only shape: legacy keys are unchanged, Phase 1
     * tallies + stall signal are additive.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleProgress(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $progress = Plugin::get_instance()->BatchProcessor()->getUnifiedProgress($sessionId);
        if ($progress === null) {
            return $this->error(
                'phase2_batch_not_found',
                __('No batch found for this session.', 'wicket-wp-importer'),
                404
            );
        }

        // Surface the per-site Phase 2 availability so the admin UI can render
        // a clear "disabled by site configuration" state before the batch even
        // lands. Phase 2 ships OFF by default; clients opt in via their theme.
        $progress['enabled'] = \WicketImporter\Admin\ChequeReviewPage::isPhase2Available();

        return new WP_REST_Response($progress, 200);
    }

    /**
     * POST /import/session/{id}/kick (WWID-2439) — re-arm the Phase 1 chunk
     * chain when the stall detector says it died (wp-cron miss). Guarded on
     * is_stalled so an in-flight chain is never doubled up: claimChunk assumes
     * a single runner per batch.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleKick(WP_REST_Request $request)
    {
        $sessionId = (string) ($request['id'] ?? '');
        $progress = Plugin::get_instance()->BatchProcessor()->getUnifiedProgress($sessionId);
        if ($progress === null) {
            return $this->error(
                'batch_not_found',
                __('No batch found for this session.', 'wicket-wp-importer'),
                404
            );
        }

        if (($progress['status'] ?? '') !== 'running' || empty($progress['is_stalled'])) {
            return $this->error(
                'batch_not_stalled',
                __('Only a stalled batch can be resumed. This batch is still processing.', 'wicket-wp-importer'),
                409
            );
        }

        // Refuse when a chunk action is already queued: a second kick (two
        // tabs, a retried fetch) would schedule a second concurrent chain and
        // break claimChunk's single-runner assumption. The stall signal is
        // minutes old by definition, so a live pending action means someone
        // (or wp-cron) beat us to the reschedule already.
        if (Plugin::get_instance()->BatchProcessor()->hasPendingChunk($sessionId)) {
            return $this->error(
                'kick_already_pending',
                __('A resume is already queued for this batch. The page will pick it up on the next check.', 'wicket-wp-importer'),
                409
            );
        }

        $batchId = Plugin::get_instance()->BatchProcessor()->startChainOnUploadBatch($sessionId);
        if ($batchId === null) {
            return $this->error(
                'kick_failed',
                __('The batch could not be resumed. Clear it from Import History and re-upload the CSV.', 'wicket-wp-importer'),
                409
            );
        }

        return new WP_REST_Response(['session_id' => $sessionId, 'batch_id' => $batchId, 'kicked' => true], 200);
    }

    /**
     * POST /import/session/{id}/retry (Slice 5) — reset Phase 2 failed /
     * needs_review rows to pending and re-schedule the chunk chain. Idempotent
     * in intent but guarded by the batch status (must be processing_complete).
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleRetry(WP_REST_Request $request)
    {
        if (!\WicketImporter\Admin\ChequeReviewPage::isPhase2Available()) {
            return $this->error(
                'phase2_disabled_by_client_config',
                __('Phase 2 (payment matching) is disabled by site configuration.', 'wicket-wp-importer'),
                403
            );
        }

        $sessionId = (string) ($request['id'] ?? '');
        $batchId = Plugin::get_instance()->BatchProcessor()->retryPhase2(
            $sessionId,
            get_current_user_id()
        );

        if ($batchId === null) {
            return $this->error(
                'phase2_retry_unavailable',
                __('Phase 2 retry only applies to a batch that already completed; start a new run instead.', 'wicket-wp-importer'),
                409
            );
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'batch_id' => $batchId,
            'status' => 'phase2_running',
        ], 200);
    }

    /**
     * Resolve the cheque-flow column set. Generic lockbox fields (order_total,
     * check_id) live in core; a client adds its member identifier (OBA: bar_id)
     * via the wicket_import_cheque_columns filter. NOT merged with the identity
     * columns: the cheque flow resolves the customer via
     * wicket_import_resolve_order_customer, not from first_name/email.
     *
     * @return list<ColumnDefinition>
     */
    private function resolveChequeColumns(): array
    {
        /** @var list<ColumnDefinition> $columns */
        $columns = apply_filters('wicket_import_cheque_columns', DefaultColumns::cheque(), ['context' => 'cheque']);

        return is_array($columns) ? $columns : [];
    }

    /**
     * Map parsed rows + their validation results into staging-table row arrays.
     *
     * The authoritative per-row state lives in ValidationSummary::$results
     * (per the Task 5 audit), not in the derived $flagged/$duplicates buckets.
     *
     * @param list<CsvRow> $rows
     */
    private function buildStagedRows(array $rows, ValidationSummary $summary): array
    {
        $staged = [];

        foreach ($rows as $csvRow) {
            $result = $summary->resultFor($csvRow->rowIndex);

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
    private function shapeValidationRow(array $row): array
    {
        return [
            'line'               => ((int) ($row['row_index'] ?? 0)) + 2,
            'data'               => Json::decodeArray($row['raw_data'] ?? null),
            'validation_status'  => (string) ($row['validation_status'] ?? ''),
            'validation_message' => (string) ($row['validation_message'] ?? ''),
            'flagged_fields'     => Json::decodeArray($row['flagged_fields'] ?? null),
        ];
    }

    /**
     * Shape a staged row for the results endpoint (decode JSON blobs).
     */
    private function shapeResultRow(array $row): array
    {
        $orderId = $row['order_id'] ?? null;

        return [
            'line'               => ((int) ($row['row_index'] ?? 0)) + 2,
            'data'               => Json::decodeArray($row['raw_data'] ?? null),
            'validation_status'  => (string) ($row['validation_status'] ?? ''),
            'import_status'      => (string) ($row['import_status'] ?? ''),
            'import_message'     => (string) ($row['import_message'] ?? ''),
            'mdp_uuid'           => isset($row['mdp_uuid']) ? (string) $row['mdp_uuid'] : null,
            'order_id'           => $orderId !== null && $orderId !== '' ? (int) $orderId : null,
            'subscription_ids'   => Json::decodeArray($row['subscription_ids'] ?? null),
            'extension_metadata' => Json::decodeArray($row['extension_metadata'] ?? null),
        ];
    }

    /**
     * Build the full CSV row list (headers first) for a flagged export.
     *
     * @param list<array<string,mixed>> $rows Staged rows.
     * @return list<list<string>>
     */
    private function buildValidationCsv(array $rows, ?array $dataKeys = null): array
    {
        $dataKeys ??= ColumnOrder::forRows($rows);
        $headers = array_merge(['Line'], $dataKeys, ['Status', 'Reason', 'Flagged Fields']);

        $out = [];
        foreach ($rows as $row) {
            $data = Json::decodeArray($row['raw_data'] ?? null);
            $line = [(string) (((int) ($row['row_index'] ?? 0)) + 2)];

            foreach ($dataKeys as $key) {
                $line[] = (string) ($data[$key] ?? '');
            }

            $line[] = (string) ($row['validation_status'] ?? '');
            $line[] = (string) ($row['validation_message'] ?? '');
            $line[] = implode(', ', Json::decodeArray($row['flagged_fields'] ?? null));

            $out[] = $line;
        }

        return array_merge([$headers], $out);
    }

    /**
     * Build the full CSV row list (headers first) for a results export.
     *
     * @param list<array<string,mixed>> $rows Staged rows.
     * @return list<list<string>>
     */
    private function buildResultsCsv(array $rows, ?array $dataKeys = null): array
    {
        $dataKeys ??= ColumnOrder::forRows($rows);

        /*
         * Extension columns use the same contract as the on-screen results
         * table (wicket_import_confirmation_columns, AD13): each entry is
         * ['label' => string, 'extractor' => fn(array $row): mixed]. The
         * extractor receives the SAME shaped row as the table (shapeResultRow:
         * raw_data + extension_metadata decoded, statuses, ids), so OBA's Bar
         * ID / tier / View-in-MDP columns render identically in the CSV
         * (WWID-2350). Columns append AFTER the fixed tail so existing header
         * positions never shift for consumers of prior exports.
         */
        $extColumns = apply_filters('wicket_import_confirmation_columns', []);
        $extColumns = is_array($extColumns) ? $extColumns : [];
        $extColumns = array_values(array_filter($extColumns, static fn ($col) => is_array($col) && ! empty($col['label'])));

        $headers = array_merge(
            ['Line'],
            $dataKeys,
            ['Import Status', 'Message', 'MDP UUID', 'Order ID', 'Subscription IDs', 'Payment Amount', 'Expected Total', 'Discrepancy'],
            array_map(static fn (array $col) => (string) $col['label'], $extColumns)
        );

        $out = [];
        foreach ($rows as $row) {
            $data = Json::decodeArray($row['raw_data'] ?? null);
            $line = [(string) (((int) ($row['row_index'] ?? 0)) + 2)];

            foreach ($dataKeys as $key) {
                $line[] = (string) ($data[$key] ?? '');
            }

            $orderId = $row['order_id'] ?? null;

            /*
             * Rows that failed validation are never claimed for import, so
             * their import_status stays 'pending' forever. Relabel to
             * 'skipped' — same rule as ImportAdminPage::effectiveImportStatus()
             * so the CSV matches the admin history screen.
             */
            $importStatus = (string) ($row['import_status'] ?? '');
            if ($importStatus === 'pending') {
                $eligible = [ValidationResult::STATUS_VALID, ValidationResult::STATUS_WARNING];
                if (!in_array((string) ($row['validation_status'] ?? ''), $eligible, true)) {
                    $importStatus = 'skipped';
                }
            }

            $line[] = $importStatus;
            $line[] = (string) ($row['import_message'] ?? '');
            $line[] = (string) ($row['mdp_uuid'] ?? '');
            $line[] = ($orderId !== null && $orderId !== '') ? (string) $orderId : '';
            $line[] = implode(', ', Json::decodeArray($row['subscription_ids'] ?? null));

            // Discrepancy reporting (D-LOCKBOX-4, spec Story 11): bank amount,
            // calculated total, and the signed delta for every reconciled
            // record. Empty for rows Phase 2 never reconciled.
            $line[] = $row['payment_amount'] !== null ? sprintf('%.2F', (float) $row['payment_amount']) : '';
            $line[] = $row['expected_amount'] !== null ? sprintf('%.2F', (float) $row['expected_amount']) : '';
            $line[] = $row['discrepancy_amount'] !== null ? sprintf('%.2F', (float) $row['discrepancy_amount']) : '';

            // Extension cells: guarded like the table's extractors — a throwing
            // extractor yields an empty cell, never a broken export.
            $shaped = $this->shapeResultRow($row);
            foreach ($extColumns as $col) {
                $value = '';
                try {
                    $value = (string) ($col['extractor']($shaped) ?? '');
                } catch (\Throwable $e) {
                    // Swallow: the fixed columns above are already complete.
                }
                $line[] = $value;
            }

            $out[] = $line;
        }

        return array_merge([$headers], $out);
    }

    /**
     * Build the CSV row list (headers first) for a failed + needs_review export.
     *
     * @param list<array<string,mixed>> $rows Staged rows.
     * @return list<list<string>>
     */
    private function buildErrorCsv(array $rows, ?array $dataKeys = null): array
    {
        $dataKeys ??= ColumnOrder::forRows($rows);
        $headers = array_merge(['Line'], $dataKeys, ['Status', 'Reason', 'Order ID', 'Suggested Fix']);

        $out = [];
        foreach ($rows as $row) {
            $data = Json::decodeArray($row['raw_data'] ?? null);
            $line = [(string) (((int) ($row['row_index'] ?? 0)) + 2)];

            foreach ($dataKeys as $key) {
                $line[] = (string) ($data[$key] ?? '');
            }

            $orderId = $row['order_id'] ?? null;
            $status = (string) ($row['import_status'] ?? '');
            $reason = (string) ($row['import_message'] ?? '');

            $line[] = $status;
            $line[] = $reason;
            $line[] = ($orderId !== null && $orderId !== '') ? (string) $orderId : '';
            $line[] = ReviewSuggester::suggestedFix($status, $reason);

            $out[] = $line;
        }

        return array_merge([$headers], $out);
    }

    /**
     * Build a WP_Error carrying an HTTP status (WP REST honours the 'status' data key).
     */
    private function error(string $code, string $message, int $status, array $extra = []): WP_Error
    {
        return new WP_Error($code, $message, array_merge(['status' => $status], $extra));
    }
}
