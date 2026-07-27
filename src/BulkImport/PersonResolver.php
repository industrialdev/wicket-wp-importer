<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

/**
 * Resolves one import row to an MDP person (Task 13.2 - 13.5).
 *
 * Owns the Scenario A / B / partial-match decision tree and the
 * active-membership guard. Sits between the upload/validation stage and
 * ImportAdapter (Task 14): it produces the person UUID + payload that
 * MemberData carries into membership creation.
 *
 * Decision tree for a row's normalized {first_name, last_name, email}:
 *   1. No email match       -> Scenario A: create person (13.2)
 *   2. Match, names differ  -> email_conflict (partial, 13.4)
 *   3. Match, names match,
 *      active membership    -> skipped_active_membership (13.4)
 *   4. Match, names match,
 *      no active membership -> Scenario B: non-destructive merge (13.3)
 *
 * The wicket_import_pre_membership_create veto (13.3) is NOT fired here. It is
 * owned by ImportAdapter::create() (Task 14.1, contract true|string|WP_Error)
 * to avoid a double-fire; PersonResolver performs the Scenario B merge
 * unconditionally and the veto gates the membership-CPT step downstream.
 *
 * MDP API errors are caught and mapped to a FAILED result; the caller (pipeline)
 * writes the row's import_status and continues the batch (13.5). This class
 * performs NO staging writes — it returns a pure result VO, mirroring
 * MembershipResult from Task 14.
 *
 * @see WicketMdpClient  Low-level wrapper used for all MDP calls (AD15 rung 2).
 * @see PersonResolutionResult
 */
final class PersonResolver
{
    public function __construct(
        private readonly WicketMdpClient $client
    ) {}

    /**
     * Read-only conflict classification for runConflictCheck (Task 12.3).
     *
     * Performs the SAME email lookup + name match as resolve(), but with NO
     * side-effects: it never creates, merges, or checks active memberships. The
     * import pipeline uses this pre-pass to surface conflicts (mdp_uuid /
     * email_conflict) to the UI BEFORE the destructive runImport (Task 12.4),
     * which calls resolve() for the real create/merge.
     *
     * Returns a plain shape (not a VO) because the caller only needs three
     * branches and a UUID:
     *   ['match' => 'none'|'exact'|'partial', 'uuid' => ?string, 'existing' => ?array]
     *
     * @param array $person Normalized person input ({email} required; first/last
     *                      name used for the exact-vs-partial decision).
     * @param array $row    Original CSV row (reserved for logging symmetry with
     *                      resolve(); no side-effect here).
     *
     * @return array{match:string, uuid:?string, existing:?array}
     */
    public function checkConflict(array $person, array $row): array
    {
        $email = trim((string) ($person['email'] ?? ''));
        if ($email === '') {
            return ['match' => 'none', 'uuid' => null, 'existing' => null];
        }

        try {
            $existing = $this->client->findPersonByEmail($email);
        } catch (\Throwable $e) {
            // Defensive: findPersonByEmail returns WP_Error rather than throwing,
            // but keep the guard so an unexpected throw is still contained.
            return ['match' => 'none', 'uuid' => null, 'existing' => null];
        }

        if (is_wp_error($existing)) {
            // Read-only pre-pass: a lookup FAILURE is not a clean "no match".
            // Leave the row unclassified here; the destructive resolve() will
            // fail it closed so we never create a duplicate against an outage.
            return ['match' => 'none', 'uuid' => null, 'existing' => null];
        }

        if ($existing === null) {
            return ['match' => 'none', 'uuid' => null, 'existing' => null];
        }

        if (!$this->namesMatch($existing, $person)) {
            return ['match' => 'partial', 'uuid' => null, 'existing' => $existing];
        }

        return ['match' => 'exact', 'uuid' => (string) $existing['id'], 'existing' => $existing];
    }

    /**
     * Resolve one row to an MDP person.
     *
     * @param array $person    Normalized person input: requires {email}; uses
     *                         {first_name, last_name} for name matching. Extra
     *                         keys are ignored by core (extensions layer richer
     *                         sync via wicket_import_person_data).
     * @param array $row       Original CSV row, forwarded to the MDP client so
     *                         the AD11 filter can read domain fields.
     * @param int   $stagingId Staged-records row ID (reserved for logging; no DB
     *                         side-effect here).
     *
     * @return PersonResolutionResult
     */
    public function resolve(array $person, array $row, int $stagingId): PersonResolutionResult
    {
        $email = trim((string) ($person['email'] ?? ''));
        if ($email === '') {
            // Validation (Task 5) should have caught missing required email for
            // bulk flow; reaching here means an optional-email context (OBA
            // individual form). Nothing to look up.
            return PersonResolutionResult::failed('No email provided; cannot resolve MDP person.');
        }

        // --- Lookup ---------------------------------------------------------
        try {
            $existing = $this->client->findPersonByEmail($email);
        } catch (\Throwable $e) {
            // 13.5: never halt the batch on an MDP error.
            return PersonResolutionResult::failed(sprintf('Person lookup failed: %s', $e->getMessage()));
        }

        if (is_wp_error($existing)) {
            // C1: a lookup failure is NOT a clean "no match". Fail the row closed
            // rather than misread an MDP outage as "no such person" and create a
            // duplicate person + WP user + membership for the whole batch.
            return PersonResolutionResult::failed(sprintf(
                'Person lookup failed (not creating to avoid a duplicate): %s',
                $existing->get_error_message()
            ));
        }

        // --- Scenario A: no match -> create --------------------------------
        if ($existing === null) {
            return $this->createScenario($person, $row);
        }

        // --- Match found: distinguish exact vs partial ---------------------
        if (!$this->namesMatch($existing, $person)) {
            // 13.4 partial match: same email, different person. Needs review.
            return PersonResolutionResult::emailConflict(
                sprintf('Email %s is already assigned to a different person (%s).', $email, $this->existingName($existing))
            );
        }

        // --- Exact match: guard on active membership -----------------------
        $uuid = (string) $existing['id'];
        try {
            $active = $this->client->hasActiveMembership($uuid);
        } catch (\Throwable $e) {
            // 13.5: a conflict-check failure fails the row rather than
            // silently proceeding to a duplicate membership.
            return PersonResolutionResult::failed(sprintf('Active-membership check failed: %s', $e->getMessage()));
        }

        // S3: unknown status (null) fails CLOSED — never assume "not active" on an
        // MDP error, or a transient blip double-charges an existing member.
        if ($active === null) {
            return PersonResolutionResult::failed(
                sprintf('Could not confirm membership status for %s; skipping to avoid a duplicate.', $uuid)
            );
        }
        if ($active === true) {
            // 13.4: do not auto-process existing active members.
            return PersonResolutionResult::skippedActiveMembership(
                sprintf('Person %s already holds an active membership.', $uuid)
            );
        }

        // --- Scenario B: exact match, no active membership -> merge --------
        return $this->mergeScenario($uuid, $person, $row, $existing);
    }

    /**
     * Scenario A (13.2): create a new MDP person and return the resolved UUID.
     */
    private function createScenario(array $person, array $row): PersonResolutionResult
    {
        $created = $this->client->createPerson($person, $row);
        if (is_wp_error($created)) {
            return PersonResolutionResult::failed(sprintf('MDP create failed: %s', $created->get_error_message()));
        }

        /* @var array $created */
        return PersonResolutionResult::resolved((string) $created['id'], $created);
    }

    /**
     * Scenario B (13.3): non-destructive merge of the row's core profile fields
     * onto the existing person. Only given_name / family_name are synced by core;
     * richer contact-info sync is the OBA extension's job (Task 32) via the AD11
     * filter + wicket_import_post_membership_create. Never overwrites existing
     * values with nulls — WicketMdpClient::updatePerson() strips empties.
     */
    private function mergeScenario(string $uuid, array $person, array $row, array $existing): PersonResolutionResult
    {
        $attributes = array_filter(
            [
                'given_name'  => $person['first_name'] ?? null,
                'family_name' => $person['last_name'] ?? null,
            ],
            static fn ($v) => $v !== null
        );

        $merged = $this->client->updatePerson($uuid, $attributes, $row);
        if (is_wp_error($merged)) {
            return PersonResolutionResult::failed(sprintf('MDP merge failed: %s', $merged->get_error_message()));
        }

        // updatePerson returns a minimal ['id'=>$uuid] when there was nothing to
        // merge; preserve the original entry attributes so downstream WP-user /
        // CPT seeding has the profile data without a re-fetch.
        $personEntry = $merged;
        if (empty($personEntry['attributes']) && isset($existing['attributes'])) {
            $personEntry = ['id' => $uuid, 'attributes' => $existing['attributes']];
        }

        return PersonResolutionResult::resolved($uuid, $personEntry);
    }

    /**
     * Case-insensitive, whitespace-tolerant comparison of full name
     * (given + family) between the MDP entry and the import row.
     *
     * Accents are NOT folded on purpose: the OBA CSV can legitimately contain
     * accented names and a transliteration mismatch should be surfaced for
     * review (partial match) rather than silently treated as the same person.
     */
    private function namesMatch(array $existing, array $person): bool
    {
        $existingName = $this->existingName($existing);
        $rowName = trim((string) ($person['first_name'] ?? '')) . ' ' . trim((string) ($person['last_name'] ?? ''));

        // Guard against an empty-name false positive: if either side folds to
        // empty, we cannot assert a name match, so treat as a partial match and
        // surface for review rather than silently treating as the same person.
        if ($this->foldName($existingName) === '' || $this->foldName($rowName) === '') {
            return false;
        }

        return $this->foldName($existingName) === $this->foldName($rowName);
    }

    /**
     * Extract a "First Last" string from a normalized MDP person entry.
     */
    private function existingName(array $existing): string
    {
        $attributes = $existing['attributes'] ?? [];

        return trim((string) ($attributes['given_name'] ?? '')) . ' ' . trim((string) ($attributes['family_name'] ?? ''));
    }

    /**
     * Normalize a name for comparison: collapse internal whitespace, lowercase.
     * Leading/trailing whitespace already trimmed by callers.
     */
    private function foldName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower($name, 'UTF-8');
    }
}
