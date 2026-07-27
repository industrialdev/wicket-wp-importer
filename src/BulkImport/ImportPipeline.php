<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

use WicketImporter\Services\Logger;
use WicketImporter\Support\DefaultColumns;
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
 * WicketCheque\BatchProcessor (Phase 4-5) because it needs Action Scheduler chunking.
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
 * @see docs/engineering/import-pipeline.md
 * @see PersonResolver  checkConflict() + resolve().
 */
final class ImportPipeline
{
    public function __construct(
        private readonly Logger $logger,
        private readonly PersonResolver $personResolver
    ) {}

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
    public function runValidation(string $sessionId): ValidationSummary
    {
        $plugin = Plugin::get_instance();
        $staging = $plugin->StagingTable();
        $rowsData = $staging->getBySession($sessionId);

        // Skip rows that have already moved past validation into the import
        // lifecycle. Re-validating a row that is already imported/failed would
        // rewrite its validation_status and desync the UI (imported row showing
        // a stale validation verdict). Only pre-import rows are re-validated.
        $terminal = ['imported' => true, 'updated' => true, 'skipped' => true, 'failed' => true, 'email_conflict' => true];

        $csvRows = [];
        $byIndex = [];
        foreach ($rowsData as $row) {
            $importStatus = (string) ($row['import_status'] ?? '');
            if (isset($terminal[$importStatus])) {
                continue;
            }
            $csvRows[] = $this->csvRowFromStaged($row);
            $byIndex[(int) $row['row_index']] = (int) $row['id'];
        }

        $columns = $this->resolveColumns();
        $summary = $plugin->Validation()->validateBatch($csvRows, $columns);

        // Persist the fresh verdict for every re-validated row. The authoritative
        // state is $summary->results (rowIndex => ValidationResult).
        foreach ($summary->results as $rowIndex => $result) {
            $stagingId = $byIndex[$rowIndex] ?? null;
            if ($stagingId === null) {
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
                'flagged'     => count($summary->flagged),
                'duplicates'  => count($summary->duplicates),
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
    public function runConflictCheck(string $sessionId): array
    {
        $plugin = Plugin::get_instance();
        $staging = $plugin->StagingTable();
        $rows = $staging->getValidBySession($sessionId);

        $tally = ['checked' => 0, 'exact' => 0, 'partial' => 0, 'none' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $stagingId = (int) $row['id'];
            $rowData = $this->decodeRawData($row);
            $person = $this->extractPerson($rowData);

            $tally['checked']++;

            // No extractable identity (extension did not map this row's columns):
            // nothing to check; leave for runImport to surface as a failure.
            if ($person === null) {
                $tally['none']++;
                continue;
            }

            try {
                $verdict = $this->personResolver->checkConflict($person, $rowData);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Conflict check threw for row; leaving untouched.',
                    ['session_id' => $sessionId, 'row_id' => $stagingId, 'error' => $e->getMessage()]
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
             * @param array  $verdict {match:'none'|'exact'|'partial', uuid:?string, existing:?array, message:?string}
             * @param array  $rowData Original CSV row.
             * @param string $sessionId
             */
            $verdict = apply_filters('wicket_import_check_conflict', $verdict, $rowData, $sessionId);

            // Defensive: a misbehaving extension can return null/scalar from the
            // filter. Without this guard, $verdict['match'] throws TypeError in
            // PHP 8.0+ and aborts the whole batch (no row-level recovery).
            if (!is_array($verdict)) {
                $verdict = ['match' => 'none', 'uuid' => null, 'existing' => null];
            }

            $match = $verdict['match'] ?? 'none';
            if ($match === 'exact' && !empty($verdict['uuid'])) {
                $staging->updatePersonUuid($stagingId, (string) $verdict['uuid']);
                $tally['exact']++;
            } elseif ($match === 'partial') {
                $existing = $verdict['existing'] ?? null;
                $existingName = '';
                if (is_array($existing)) {
                    $attrs = $existing['attributes'] ?? [];
                    $fullName = trim((string) ($attrs['given_name'] ?? '') . ' ' . (string) ($attrs['family_name'] ?? ''));
                    if ($fullName !== '') {
                        $existingName = ' (' . $fullName . ')';
                    }
                }
                // D-OBA-1: an extension (OBA Task 34) may supply a custom
                // skip reason via $verdict['message']; fall back to the core
                // default when absent (backward-compatible).
                $message = $verdict['message'] ?? sprintf(
                    'Email %s already belongs to a different person%s.',
                    $person['email'] ?? '',
                    $existingName
                );
                $staging->updateImportResult($stagingId, 'email_conflict', $message);
                $tally['partial']++;
            } else {
                $tally['none']++;
            }
        }

        $this->logger->info('runConflictCheck complete.', array_merge(['session_id' => $sessionId], $tally));

        return $tally;
    }

    /**
     * Phase 3 (Task 12.1 + 12.4): inline destructive import.
     *
     * Per-row flow:
     *   1. extract person identity (wicket_import_extract_person filter or guess)
     *   2. PersonResolver::resolve() -> PersonResolutionResult
     *   3. On RESOLVED, fire wicket_import_post_person_resolved (12.6) so
     *      extensions can react to the resolved person before membership work.
     *   4. On RESOLVED, fire wicket_import_resolve_membership_tier (12.7) so
     *      the extension (OBA) can return a tier post ID. WP_Error from the
     *      filter short-circuits the row as `failed`.
     *   5. On RESOLVED, build MemberData and call ImportAdapter::create()
     *      (which fires 12.5 + wicket_import_create_subscription + 12.8).
     *   6. Map the result back onto staging: updatePersonUuid + updateImportResult.
     *      `imported` for Scenario A (create), `updated` for Scenario B (merge,
     *      when mdp_uuid was already set by the conflict pre-pass).
     *
     * Per-row error handling (12.1): every row is isolated by a try/catch. A
     * failure in one row marks it `failed` and the batch continues; nothing in
     * one row's pipeline can halt the session.
     *
     * Guards:
     *   - set_time_limit(0) + ignore_user_abort(true): the inline import can
     *     run long; do not let PHP's default 30s cap or a client disconnect
     *     truncate a batch mid-membership.
     *   - countImportableInSession() > WICKET_IMPORT_INLINE_MAX_ROWS: this
     *     phase runs on the request thread, so cap it (200 default). Larger
     *     batches need a chunked/AS path (out of scope for OBA inline flow).
     *   - Duration is timed and logged for capacity planning.
     *
     * @param string $sessionId      Session to import.
     * @param bool   $skipFlagged    When true, rows flagged at validation are
     *                               skipped (default). The inline cap already
     *                               excludes them; this is the public contract.
     *
     * @return array{summary:array<string,int>, duration_sec:float}|\WP_Error
     *               Tally on completion, WP_Error if the cap guard rejects.
     */
    public function runImport(string $sessionId, bool $skipFlagged = true): \WP_Error|array
    {
        $started = microtime(true);

        // Raise limits: inline import must not die mid-batch on PHP timeouts or a
        // dropped client connection. Both are best-effort (host may cap them).
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $plugin = Plugin::get_instance();
        $staging = $plugin->StagingTable();
        $adapter = $plugin->ImportAdapter();
        $importableCount = $staging->countImportableInSession($sessionId);

        if ($importableCount > WICKET_IMPORT_INLINE_MAX_ROWS) {
            return new \WP_Error(
                'import_too_many_rows',
                sprintf(
                    'Session has %d importable rows; inline import is capped at %d. Use a chunked flow.',
                    $importableCount,
                    WICKET_IMPORT_INLINE_MAX_ROWS
                )
            );
        }

        $tally = [
            'total'         => 0,
            'imported'      => 0,
            'updated'       => 0,
            'skipped'       => 0,
            'needs_review'  => 0,
            'failed'        => 0,
            'conflicts'     => 0,
        ];

        // Atomic claim: transition import_status 'pending' -> 'processing' for
        // every importable row in the session. The UPDATE is atomic, so two
        // parallel /run calls on the same session can't both drive
        // ImportAdapter::create() on the same rows — the loser's claim returns
        // 0 affected rows and short-circuits to the empty-tally fast path.
        $claimedCount = $staging->claimImportableInSession($sessionId);

        // No-op fast path: nothing claimed (either nothing to process, or a
        // concurrent /run beat us to it). Return empty tally.
        if ($claimedCount === 0) {
            $duration = round(microtime(true) - $started, 3);
            $this->logger->info('runImport complete (no rows claimed).', [
                'session_id'     => $sessionId,
                'duration_sec'   => $duration,
                'rows_processed' => 0,
            ]);

            return ['summary' => $tally, 'duration_sec' => $duration];
        }

        $rows = $staging->getProcessingBySession($sessionId);

        foreach ($rows as $row) {
            $stagingId = (int) $row['id'];
            $rowData = $this->decodeRawData($row);
            $tally['total']++;

            // Track whether the MDP person was touched in the current row's
            // pipeline. Set true after PersonResolver->resolve() returns
            // RESOLVED (Scenario A create OR Scenario B merge). Used by the
            // post-RESOLVED failure branches (12.7 WP_Error, adapter failed,
            // per-row exception) to mark the row 'needs_review' instead of
            // 'failed' — a touched MDP person + a missing/stale WP
            // membership is exactly the orphan situation an admin must
            // address manually, not a row that the pipeline can safely
            // re-process on its own.
            $personTouched = false;

            try {
                $person = $this->extractPerson($rowData);
                if ($person === null) {
                    $staging->updateImportResult(
                        $stagingId,
                        'failed',
                        'Could not extract person identity from row (no email).'
                    );
                    $tally['failed']++;
                    continue;
                }

                $resolution = $this->personResolver->resolve($person, $rowData, $stagingId);
                $personTouched = ($resolution->status === PersonResolutionResult::STATUS_RESOLVED);

                switch ($resolution->status) {
                    case PersonResolutionResult::STATUS_RESOLVED:
                        $uuid = (string) $resolution->uuid;
                        $mdpEntry = is_array($resolution->person)
                            ? $resolution->person
                            : ['id' => $uuid];
                        // MemberData's contract is FLAT keys (first_name, last_name,
                        // email) per its own docblock — ImportAdapter reads those
                        // directly (resolveUserId, buildMapping). PersonResolver hands
                        // back the MDP entry shape (id + attributes) which would leave
                        // every flat key as null and break WP user + CPT seeding.
                        // Merge the flat identity (from extractPerson) with the MDP
                        // entry; flat wins because it's what the contract requires,
                        // and id/attributes are preserved for hook consumers (12.6 / 12.8)
                        // that want the full MDP payload.
                        $personEntry = array_merge(
                            [
                                'first_name' => (string) ($person['first_name'] ?? ''),
                                'last_name'  => (string) ($person['last_name'] ?? ''),
                                'email'      => (string) ($person['email'] ?? ''),
                            ],
                            $mdpEntry
                        );
                        $hadUuid = !empty($row['mdp_uuid']);

                        // 12.6: notify extensions that an MDP person is now resolved
                        // (created or merged). 4-arg signature mirrors 12.8 (post-
                        // membership_create) for consistency so extensions can rely
                        // on the same (uuid, person, row, stagingId) shape across
                        // the lifecycle.
                        do_action('wicket_import_post_person_resolved', $uuid, $personEntry, $rowData, $stagingId);

                        // 12.7: extension returns the tier post ID, or WP_Error
                        // to skip the row with an error reason. Default = 0 (no
                        // extension override); ImportAdapter treats tier=0 as a
                        // failed row with a precise reason downstream.
                        // WP_Error here is a needs_review, not a failed: the
                        // person is already created/merged in the MDP and a
                        // tier-resolution failure means the row cannot be
                        // re-run safely without admin intervention.
                        $tierResult = apply_filters('wicket_import_resolve_membership_tier', 0, $rowData);
                        if (is_wp_error($tierResult)) {
                            $staging->updateImportResult(
                                $stagingId,
                                'needs_review',
                                sprintf('Tier resolution failed: %s', $tierResult->get_error_message())
                            );
                            $tally['needs_review']++;
                            break;
                        }
                        $tierId = (int) $tierResult;

                        $memberData = new MemberData(
                            $uuid,
                            $personEntry,
                            $rowData,
                            $tierId,
                            $stagingId
                        );

                        $staging->updatePersonUuid($stagingId, $uuid);
                        $adapterResult = $adapter->create($memberData);

                        if ($adapterResult->isCreated()) {
                            // Scenario A (create) -> 'imported'. Scenario B (merge,
                            // mdp_uuid was set by runConflictCheck on entry) -> 'updated'.
                            $status = $hadUuid ? 'updated' : 'imported';
                            $staging->updateImportResult($stagingId, $status);
                            if ($status === 'imported') {
                                $tally['imported']++;
                            } else {
                                $tally['updated']++;
                            }
                        } elseif ($adapterResult->isSkipped()) {
                            $staging->updateImportResult($stagingId, 'skipped', $adapterResult->message);
                            $tally['skipped']++;
                        } else {
                            // Adapter returned a non-skipped, non-created
                            // failure after the MDP person was touched. Mark
                            // needs_review so an admin can address the
                            // orphaned person + missing WP membership.
                            $staging->updateImportResult($stagingId, 'needs_review', $adapterResult->message);
                            $tally['needs_review']++;
                        }
                        break;

                    case PersonResolutionResult::STATUS_EMAIL_CONFLICT:
                        $staging->updateImportResult($stagingId, 'email_conflict', $resolution->message);
                        $tally['conflicts']++;
                        break;

                    case PersonResolutionResult::STATUS_SKIPPED_ACTIVE_MEMBERSHIP:
                        $staging->updateImportResult($stagingId, 'skipped_active_membership', $resolution->message);
                        $tally['skipped']++;
                        break;

                    case PersonResolutionResult::STATUS_FAILED:
                    default:
                        $staging->updateImportResult($stagingId, 'failed', $resolution->message);
                        $tally['failed']++;
                        break;
                }
            } catch (\Throwable $e) {
                // Per-row isolation: a thrown exception in one row's pipeline
                // must not halt the batch. If the person was already touched
                // (resolve returned RESOLVED before the throw), the row lands
                // as needs_review — the orphan concern is the same as the
                // adapter-failed branch. Pre-resolve throws land as failed
                // because no person was touched.
                $status = $personTouched ? 'needs_review' : 'failed';
                $tallyKey = $personTouched ? 'needs_review' : 'failed';
                $this->logger->error(sprintf('runImport row threw; marking %s and continuing.', $status), [
                    'session_id' => $sessionId,
                    'row_id'     => $stagingId,
                    'error'      => $e->getMessage(),
                ]);
                $staging->updateImportResult($stagingId, $status, sprintf('Pipeline exception: %s', $e->getMessage()));
                $tally[$tallyKey]++;
            }
        }

        $duration = round(microtime(true) - $started, 3);
        // Escalate to warning when any row failed so failed batches are
        // greppable in the WC log without filtering by summary (round-2 N6).
        $logMethod = $tally['failed'] > 0 ? 'warning' : 'info';
        $this->logger->{$logMethod}('runImport complete.', [
            'session_id'     => $sessionId,
            'duration_sec'   => $duration,
            'rows_processed' => $tally['total'],
            'summary'        => $tally,
            'skip_flagged'   => $skipFlagged,
        ]);

        return ['summary' => $tally, 'duration_sec' => $duration];
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
        $columns = apply_filters('wicket_import_csv_columns', [], ['context' => 'bulk']);

        // Core always contributes the baseline identity columns and layers the
        // extension's domain columns on top, so the re-validation pass is
        // consistent with the upload pass. See DefaultColumns::mergeWith().
        return DefaultColumns::mergeWith(is_array($columns) ? $columns : []);
    }

    /**
     * Reconstruct a CsvRow from a staged DB row. raw_data is the keyed column
     * map written at upload time; rowData (positional cells) is not stored, so
     * it is left empty — validators read keyed values via CsvRow::data, not
     * rawData.
     *
     * @param array $staged One row from ImportStagingTable::getBySession().
     */
    private function csvRowFromStaged(array $staged): CsvRow
    {
        $data = $this->decodeRawData($staged);

        return new CsvRow(
            (int) ($staged['row_index'] ?? 0),
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
    private function decodeRawData(array $staged): array
    {
        $raw = $staged['raw_data'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Legacy/defensive: some rows may already store an array (e.g. tests).
        return is_array($raw) ? $raw : [];
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
    private function extractPerson(array $row): ?array
    {
        $person = apply_filters('wicket_import_extract_person', null, $row);

        if (is_array($person)) {
            $person = array_merge($this->guessPerson($row), $person);
        } else {
            $person = $this->guessPerson($row);
        }

        $email = trim((string) ($person['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        return [
            'first_name' => trim((string) ($person['first_name'] ?? '')),
            'last_name'  => trim((string) ($person['last_name'] ?? '')),
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
    private function guessPerson(array $row): array
    {
        return [
            'first_name' => $this->firstOf($row, ['first_name', 'given_name', 'firstname', 'first']),
            'last_name'  => $this->firstOf($row, ['last_name', 'family_name', 'lastname', 'last']),
            'email'      => $this->firstOf($row, ['email', 'email_address', 'e_mail']),
        ];
    }

    /**
     * Return the first non-empty string value found for any of the given keys
     * (case-insensitive). Column keys are registered canonically but CSV alias
     * matching may normalize them differently, so a tolerant lookup is safer
     * than a single isset().
     */
    private function firstOf(array $row, array $keys): string
    {
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($keys as $key) {
            $lk = strtolower($key);
            if (isset($lower[$lk])) {
                $value = trim((string) $lower[$lk]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
