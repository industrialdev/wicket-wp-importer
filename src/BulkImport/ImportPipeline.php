<?php
declare(strict_types=1);

namespace WicketImporter\BulkImport;

use WicketImporter\Services\Logger;
use WicketImporter\ValueObjects\ColumnDefinition;
use WicketImporter\ValueObjects\CsvRow;
use WicketImporter\ValueObjects\ValidationResult;
use WicketImporter\ValueObjects\ValidationSummary;
use WicketImporter\WicketImporter as Plugin;

/**
 * Three-phase OBA import orchestrator (Task 12).
 *
 * Owns the session-level lifecycle that turns staged rows into MDP people +
 * memberships. Cheque renewal does NOT use this class; it has its own
 * Cheque\BatchProcessor (Phase 4-5) because it needs Action Scheduler chunking.
 *
 * Phases:
 *   1. runValidation($sessionId)    Re-run the validation pipeline over every
 *                                   staged row and persist the fresh verdicts.
 *                                   Necessary because extension validators may
 *                                   have changed between upload and processing.
 *   2. runConflictCheck($sessionId) READ-ONLY MDP pre-pass: for each valid row,
 *                                   look up the email and classify exact vs
 *                                   partial match. Writes mdp_uuid for exact
 *                                   matches and marks email_conflict rows so
 *                                   they are NOT auto-processed. No create/merge.
 *   3. runImport($sessionId)        The destructive phase: resolve each pending
 *                                   valid row to an MDP person (Scenario A/B via
 *                                   PersonResolver) and create the membership
 *                                   (ImportAdapter). [Per-row loop = Task 12.4.]
 *
 * Per-row error handling (12.1): every row is isolated. A failure marks that row
 * `failed` and the batch continues; nothing in one row's pipeline can halt the
 * session. runImport additionally raises PHP's time + abort limits and caps the
 * session size at WICKET_IMPORT_INLINE_MAX_ROWS (200) since this phase runs
 * inline on the request thread.
 *
 * This class performs NO membership creation itself (12.4 is not yet wired);
 * runImport is currently the guard + timing shell. The row loop + ImportAdapter
 * hand-off lands with Task 12.4.
 *
 * @see Task 12  in docs/importer-plan-workstreams.md
 * @see PersonResolver  checkConflict() (12.3) + resolve() (12.4).
 */
final class ImportPipeline
{
	public function __construct(
		private readonly Logger $logger,
		private readonly PersonResolver $personResolver
	) {
	}

	/**
	 * Phase 1 (Task 12.2): re-validate every row in the session and persist the
	 * fresh verdicts to the staging table.
	 *
	 * Rows are reconstructed from staged raw_data into CsvRow value objects,
	 * run through the same ValidationService + column registry the upload used,
	 * and each row's validation_status / message / flagged_fields is rewritten.
	 * This makes the pipeline robust to extension validator changes between
	 * upload and run.
	 *
	 * @param string $sessionId Session to re-validate.
	 *
	 * @return ValidationSummary The authoritative summary (results map + counts).
	 */
	public function runValidation( string $sessionId ): ValidationSummary
	{
		$plugin   = Plugin::get_instance();
		$staging  = $plugin->StagingTable();
		$rowsData = $staging->getBySession( $sessionId );

		// Skip rows that have already moved past validation into the import
		// lifecycle. Re-validating a row that is already imported/failed would
		// rewrite its validation_status and desync the UI (imported row showing
		// a stale validation verdict). Only pre-import rows are re-validated.
		$terminal = [ 'imported' => true, 'updated' => true, 'skipped' => true, 'failed' => true, 'email_conflict' => true ];

		$csvRows = [];
		$byIndex = [];
		foreach ( $rowsData as $row ) {
			$importStatus = (string) ( $row['import_status'] ?? '' );
			if ( isset( $terminal[ $importStatus ] ) ) {
				continue;
			}
			$csvRows[] = $this->csvRowFromStaged( $row );
			$byIndex[ (int) $row['row_index'] ] = (int) $row['id'];
		}

		$columns = $this->resolveColumns();
		$summary = $plugin->Validation()->validateBatch( $csvRows, $columns );

		// Persist the fresh verdict for every re-validated row. The authoritative
		// state is $summary->results (rowIndex => ValidationResult).
		foreach ( $summary->results as $rowIndex => $result ) {
			$stagingId = $byIndex[ $rowIndex ] ?? null;
			if ( $stagingId === null ) {
				continue;
			}
			$staging->updateValidationResult(
				$stagingId,
				$result->status,
				$result->message,
				$result->flaggedFields
			);
		}

		$this->logger->info(
			'runValidation complete.',
			[
				'session_id'  => $sessionId,
				'total'       => $summary->total,
				'valid'       => $summary->validCount,
				'flagged'     => count( $summary->flagged ),
				'duplicates'  => count( $summary->duplicates ),
			]
		);

		return $summary;
	}

	/**
	 * Phase 2 (Task 12.3): READ-ONLY MDP conflict pre-pass.
	 *
	 * For every currently-valid row, look the email up against the MDP and
	 * classify the match. Effects:
	 *   - exact match   -> write mdp_uuid to the staging row (runImport/12.4
	 *                      will merge onto this person).
	 *   - partial match -> set import_status = email_conflict so the row is NOT
	 *                      auto-processed (name differs; needs human review).
	 *   - no match      -> leave the row untouched (runImport/12.4 will create).
	 *
	 * This phase NEVER creates or merges people. It only populates mdp_uuid and
	 * flags conflicts so the validation screen can show them before the
	 * destructive import. The active-membership guard (13.4) intentionally runs
	 * later, inside PersonResolver::resolve(), because it is a destructive-phase
	 * decision (a membership may expire between conflict-check and import).
	 *
	 * AD12 hookable: fires wicket_import_check_conflict per row so extensions
	 * (OBA's 4-tier email + Bar ID check, Task 34) can override the core
	 * classification. The default core verdict is computed first and forwarded
	 * to the filter as the starting point.
	 *
	 * Per-row errors are caught + logged; they do not halt the pass.
	 *
	 * @param string $sessionId Session to check.
	 *
	 * @return array{checked:int, exact:int, partial:int, none:int} Per-session tally.
	 */
	public function runConflictCheck( string $sessionId ): array
	{
		$plugin  = Plugin::get_instance();
		$staging = $plugin->StagingTable();
		$rows    = $staging->getValidBySession( $sessionId );

		$tally = [ 'checked' => 0, 'exact' => 0, 'partial' => 0, 'none' => 0, 'error' => 0 ];

		foreach ( $rows as $row ) {
			$stagingId = (int) $row['id'];
			$rowData   = $this->decodeRawData( $row );
			$person    = $this->extractPerson( $rowData );

			$tally['checked']++;

			// No extractable identity (extension did not map this row's columns):
			// nothing to check; leave for runImport to surface as a failure.
			if ( $person === null ) {
				$tally['none']++;
				continue;
			}

			try {
				$verdict = $this->personResolver->checkConflict( $person, $rowData );
			} catch ( \Throwable $e ) {
				$this->logger->warning(
					'Conflict check threw for row; leaving untouched.',
					[ 'session_id' => $sessionId, 'row_id' => $stagingId, 'error' => $e->getMessage() ]
				);
				$tally['error']++;
				continue;
			}

			/**
			 * AD12: let extensions override or refine the conflict verdict.
			 * Receives the core classification and the row; returns a verdict
			 * of the same shape. OBA (Task 34) implements the 4-tier email +
			 * Bar ID check here. Default = core verdict unchanged.
			 *
			 * @param array  $verdict {match:'none'|'exact'|'partial', uuid:?string, existing:?array}
			 * @param array  $rowData Original CSV row.
			 * @param string $sessionId
			 */
			$verdict = apply_filters( 'wicket_import_check_conflict', $verdict, $rowData, $sessionId );

			// Defensive: a misbehaving extension can return null/scalar from the
			// filter. Without this guard, $verdict['match'] throws TypeError in
			// PHP 8.0+ and aborts the whole batch (no row-level recovery).
			if ( ! is_array( $verdict ) ) {
				$verdict = [ 'match' => 'none', 'uuid' => null, 'existing' => null ];
			}

			$match = $verdict['match'] ?? 'none';
			if ( $match === 'exact' && ! empty( $verdict['uuid'] ) ) {
				$staging->updatePersonUuid( $stagingId, (string) $verdict['uuid'] );
				$tally['exact']++;
			} elseif ( $match === 'partial' ) {
				$existing = $verdict['existing'] ?? null;
				$existingName = '';
				if ( is_array( $existing ) ) {
					$attrs    = $existing['attributes'] ?? [];
					$fullName = trim( (string) ( $attrs['given_name'] ?? '' ) . ' ' . (string) ( $attrs['family_name'] ?? '' ) );
					if ( $fullName !== '' ) {
						$existingName = ' (' . $fullName . ')';
					}
				}
				$message = sprintf(
					'Email %s already belongs to a different person%s.',
					$person['email'] ?? '',
					$existingName
				);
				$staging->updateImportResult( $stagingId, 'email_conflict', $message );
				$tally['partial']++;
			} else {
				$tally['none']++;
			}
		}

		$this->logger->info( 'runConflictCheck complete.', array_merge( [ 'session_id' => $sessionId ], $tally ) );

		return $tally;
	}

	/**
	 * Phase 3 (Task 12.1 shell / 12.4 row loop).
	 *
	 * Guards + timing. The per-row destructive loop (resolve person via
	 * PersonResolver::resolve() -> ImportAdapter::create()) is Task 12.4 and is
	 * not yet wired; this method currently enforces the inline-execution
	 * contract and returns once 12.4 is implemented.
	 *
	 * Guards:
	 *   - set_time_limit(0) + ignore_user_abort(true): the inline import can
	 *     run long; do not let PHP's default 30s cap or a client disconnect
	 *     truncate a batch mid-membership.
	 *   - countPendingInSession() > WICKET_IMPORT_INLINE_MAX_ROWS: this phase
	 *     runs on the request thread, so cap it (200 default). Larger batches
	 *     need a chunked/AS path (out of scope for OBA inline flow).
	 *   - Duration is timed and logged for capacity planning.
	 *
	 * @param string $sessionId      Session to import.
	 * @param bool   $skipFlagged    When true, rows flagged at validation are
	 *                               skipped (default). Reserved for 12.4.
	 *
	 * @return \WP_Error|true True on completion, WP_Error if the guard rejects.
	 */
	public function runImport( string $sessionId, bool $skipFlagged = true ): \WP_Error|true
	{
		$started = microtime( true );

		// Raise limits: inline import must not die mid-batch on PHP timeouts or a
		// dropped client connection. Both are best-effort (host may cap them).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$staging         = Plugin::get_instance()->StagingTable();
		$importableCount = $staging->countImportableInSession( $sessionId );

		if ( $importableCount > WICKET_IMPORT_INLINE_MAX_ROWS ) {
			return new \WP_Error(
				'import_too_many_rows',
				sprintf(
					'Session has %d importable rows; inline import is capped at %d. Use a chunked flow.',
					$importableCount,
					WICKET_IMPORT_INLINE_MAX_ROWS
				)
			);
		}

		// TODO Task 12.4: per-row destructive loop.
		//   For each pending valid row:
		//     1. extract person via wicket_import_extract_person
		//     2. PersonResolver->resolve($person, $row, $stagingId)
		//     3. map PersonResolutionResult onto staging (updatePersonUuid +
		//        updateImportResult with resolved/email_conflict/skipped/failed)
		//     4. on RESOLVED: build MemberData, ImportAdapter->create()
		//     5. wrap each row in try/catch -> mark failed, continue batch.

		$duration = round( microtime( true ) - $started, 3 );
		$this->logger->info(
			'runImport complete.',
			[
				'session_id'     => $sessionId,
				'importable_rows' => $importableCount,
				'skip_flagged'   => $skipFlagged,
				'duration_sec'   => $duration,
				'rows_processed' => 0, // populated by 12.4
			]
		);

		return true;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Resolve the column registry via the same filter the upload controller
	 * uses (wicket_import_csv_columns). runValidation must use the identical
	 * column set so re-validation is consistent with the initial pass.
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
	 * Reconstruct a CsvRow from a staged DB row. raw_data is the keyed column
	 * map written at upload time; rowData (positional cells) is not stored, so
	 * it is left empty — validators read keyed values via CsvRow::data, not
	 * rawData.
	 *
	 * @param array $staged One row from ImportStagingTable::getBySession().
	 */
	private function csvRowFromStaged( array $staged ): CsvRow
	{
		$data = $this->decodeRawData( $staged );
		return new CsvRow(
			(int) ( $staged['row_index'] ?? 0 ),
			$data,
			[]
		);
	}

	/**
	 * Decode the raw_data JSON blob stored on a staged row. Centralized so both
	 * runValidation and runConflictCheck share one forgiving decoder.
	 *
	 * @return array<string,mixed>
	 */
	private function decodeRawData( array $staged ): array
	{
		$raw = $staged['raw_data'] ?? null;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		// Legacy/defensive: some rows may already store an array (e.g. tests).
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Extract a normalized person identity {first_name, last_name, email} from a
	 * CSV row. Core registers no columns (keys are extension-defined), so this
	 * fires wicket_import_extract_person to let the extension (OBA) map its own
	 * column keys. Default fallback guesses the common keys so a sensible
	 * extension works without the hook.
	 *
	 * Extension contract: the filter may return null (use the fallback guess) or
	 * a partial/full array. Returned values are merged OVER the guess, so
	 * extension-supplied keys win. Extensions SHOULD return non-empty strings;
	 * null/blank values are coerced to '' and will not override a guessed value
	 * meaningfully (a blank name yields a partial-match verdict against an MDP
	 * person whose name is present, which is the safe default for review).
	 *
	 * @param array $row Keyed CSV row data.
	 *
	 * @return array|null {first_name,last_name,email} or null when no email could
	 *                    be resolved (nothing to look up against the MDP).
	 */
	private function extractPerson( array $row ): ?array
	{
		$person = apply_filters( 'wicket_import_extract_person', null, $row );

		if ( is_array( $person ) ) {
			$person = array_merge( $this->guessPerson( $row ), $person );
		} else {
			$person = $this->guessPerson( $row );
		}

		$email = trim( (string) ( $person['email'] ?? '' ) );
		if ( $email === '' ) {
			return null;
		}

		return [
			'first_name' => trim( (string) ( $person['first_name'] ?? '' ) ),
			'last_name'  => trim( (string) ( $person['last_name'] ?? '' ) ),
			'email'      => $email,
		];
	}

	/**
	 * Best-effort default extraction from common canonical column keys. Used
	 * unless an extension overrides via wicket_import_extract_person. Tries the
	 * obvious key variants so a vanilla extension works without the hook.
	 *
	 * @return array{first_name:string,last_name:string,email:string}
	 */
	private function guessPerson( array $row ): array
	{
		return [
			'first_name' => $this->firstOf( $row, [ 'first_name', 'given_name', 'firstname', 'first' ] ),
			'last_name'  => $this->firstOf( $row, [ 'last_name', 'family_name', 'lastname', 'last' ] ),
			'email'      => $this->firstOf( $row, [ 'email', 'email_address', 'e_mail' ] ),
		];
	}

	/**
	 * Return the first non-empty string value found for any of the given keys
	 * (case-insensitive). Column keys are registered canonically but CSV alias
	 * matching may normalize them differently, so a tolerant lookup is safer
	 * than a single isset().
	 */
	private function firstOf( array $row, array $keys ): string
	{
		$lower = [];
		foreach ( $row as $k => $v ) {
			$lower[ strtolower( (string) $k ) ] = $v;
		}
		foreach ( $keys as $key ) {
			$lk = strtolower( $key );
			if ( isset( $lower[ $lk ] ) ) {
				$value = trim( (string) $lower[ $lk ] );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}
		return '';
	}
}
