<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

use WicketImporter\Services\Logger;

/**
 * Thin wrapper over the base plugin's configured MDP SDK client.
 *
 * Per Task 13.1 / AD11 / AD15. This class sits at rung 2 of the MDP priority
 * ladder (wicket-wp-base-plugin): it reuses the authenticated SDK client
 * `wicket_api_client()` provided by the base plugin rather than reinventing
 * auth/transport (which would be rung 4). It deliberately does NOT use the
 * base plugin's opinionated helpers `wicket_create_person()` /
 * `wicket_update_person()` for writes, because:
 *   - `wicket_create_person()` has a fixed signature with no hook point for the
 *     AD11 `wicket_import_person_data` filter that extensions (OBA) rely on to
 *     inject tenant-specific fields (status = Good Standing, address type,
 *     country defaults).
 *   - `wicket_update_person()` performs a fetch-then-overwrite that coerces
 *     blank strings to NULL and merges them over existing values, i.e. it is a
 *     DESTRUCTIVE merge. Task 13.3 explicitly requires a non-destructive merge
 *     (update only fields present in the import row, never overwrite existing
 *     values with nulls). That is impossible through `wicket_update_person()`.
 *
 * Lookup (findPersonByEmail) and the active-membership check (hasActiveMembership)
 * DO delegate to rung-2 helpers (`wicket_get_person_by_email`,
 * `wicket_get_person_active_memberships`) because they already do exactly what
 * we need.
 *
 * Both createPerson() and updatePerson() fire `wicket_import_person_data`
 * BEFORE the API call (AD11), passing the structured payload, the originating
 * CSV row (so extensions can read domain fields like OBA's admit_date), and a
 * context tag ('create' | 'update'). This avoids a second PATCH per row.
 *
 * All methods catch MDP API errors and return WP_Error; callers (PersonResolver)
 * are responsible for mapping them to per-row import status (Task 13.5).
 *
 * @see AD11     Pre-create filter for MDP person payload.
 * @see AD15     MDP integration priority ladder.
 * @see atlas/packages/wicket-wp-importer/import-pipeline.md
 */
final class WicketMdpClient
{
    public function __construct(
        private readonly Logger $logger
    ) {}

    /**
     * Find a person in the MDP by primary or secondary email address.
     *
     * Two-stage lookup mirroring wicket_create_or_get_person(): first the
     * primary-email helper, then a direct filter on the address field which
     * catches secondary / alias emails the primary-only helper misses.
     *
     * Distinguishes "no match" from "lookup failed": a transport/config
     * failure (client unavailable, helper threw, request threw) returns a
     * WP_Error instead of null, so PersonResolver::resolve() can fail the row
     * CLOSED rather than misreading an outage as "no such person" and creating
     * a duplicate. A genuine no-match (both lookups ran cleanly, no data) still
     * returns null.
     *
     * @param string $email Email address to look up.
     *
     * @return array|null|\WP_Error Normalized person array (the raw /people entry)
     *                    on hit, null on a clean no-match, WP_Error when the
     *                    lookup itself failed (caller must fail-closed).
     */
    public function findPersonByEmail(string $email): array|null|\WP_Error
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $primary_failed = false;

        // 1. Primary-email lookup via the rung-2 helper.
        if (function_exists('wicket_get_person_by_email')) {
            try {
                $person = wicket_get_person_by_email($email);
                if (!empty($person)) {
                    return $this->normalizePerson($person);
                }
            } catch (\Throwable $e) {
                // Non-fatal: fall through to the secondary-email filter, but
                // remember the primary errored so a final "no data" is reported
                // as a lookup failure rather than a clean miss.
                $primary_failed = true;
                $this->logger->warning('wicket_get_person_by_email threw; trying secondary filter.', ['email' => $email, 'error' => $e->getMessage()]);
            }
        }

        // 2. Fallback: direct filter on emails_address_eq (catches secondary /
        //    alias addresses). Uses the base plugin's configured client object.
        $client = $this->client();
        if ($client === null) {
            return new \WP_Error('mdp_lookup_unavailable', 'Wicket API client is not available.');
        }
        try {
            $response = $client->get('people?filter[emails_address_eq]=' . rawurlencode($email));
        } catch (\Throwable $e) {
            $this->logger->error('Secondary person lookup failed.', ['email' => $email, 'error' => $e->getMessage()]);

            return new \WP_Error('mdp_lookup_failed', $e->getMessage());
        }

        if (!empty($response['data'][0])) {
            return $this->normalizePerson($response['data'][0]);
        }

        // No data. If the primary lookup errored, this is not a trustworthy
        // "no match" (the secondary may have hit the same outage); surface a
        // lookup failure so the caller fails the row closed.
        if ($primary_failed) {
            return new \WP_Error('mdp_lookup_failed', 'Primary person lookup errored; cannot confirm no-match.');
        }

        return null;
    }

    /**
     * Create a new MDP person (Scenario A).
     *
     * Builds a minimal JSON:API payload from the normalized person array
     * (first_name / last_name / email), fires wicket_import_person_data (AD11)
     * so extensions can inject tenant-specific attributes, then POSTs /people.
     *
     * @param array  $person Normalized person input: {first_name, last_name, email, ...}.
     * @param array  $row    Original CSV row, forwarded into the filter so
     *                       extensions can read domain fields.
     *
     * @return array|\WP_Error Person entry (with 'id') on success, WP_Error on failure.
     */
    public function createPerson(array $person, array $row): array|\WP_Error
    {
        $client = $this->client();
        if ($client === null) {
            return new \WP_Error('mdp_client_unavailable', 'Wicket API client is not available.');
        }

        $payload = $this->baseCreatePayload($person);

        /**
         * AD11: fire before the create call so extensions can inject
         * tenant-specific fields (status, address type, country defaults) in a
         * single request. Avoids a follow-up PATCH per row.
         *
         * @param array  $payload JSON:API payload being sent to POST /people.
         * @param array  $row     Original CSV row.
         * @param string $context 'create'.
         */
        $payload = apply_filters('wicket_import_person_data', $payload, $row, 'create');

        try {
            $response = $client->post('people', ['json' => $payload]);
        } catch (\Throwable $e) {
            $this->logger->error('MDP createPerson failed.', ['email' => $person['email'] ?? '', 'error' => $e->getMessage()]);

            return new \WP_Error('mdp_create_failed', $e->getMessage());
        }

        $normalized = $this->normalizePerson($response);
        if (empty($normalized['id'])) {
            return new \WP_Error('mdp_create_no_uuid', 'MDP create returned no person UUID.');
        }

        return $normalized;
    }

    /**
     * Non-destructive merge of an existing MDP person (Scenario B).
     *
     * Only attributes present (non-null) in $attributes are PATCHed; the caller
     * is responsible for stripping keys it does not want to overwrite. Existing
     * MDP values are NEVER replaced with nulls — the opposite of the base
     * helper wicket_update_person(), which is why we use the client directly.
     *
     * Fires wicket_import_person_data (AD11) with context 'update' before the
     * PATCH.
     *
     * @param string $uuid        Target person UUID.
     * @param array  $attributes  Key=>value MDP attributes to merge (null values
     *                            are stripped before send).
     * @param array  $row         Original CSV row, forwarded into the filter.
     *
     * @return array|\WP_Error Normalized person entry on success, WP_Error on failure.
     */
    public function updatePerson(string $uuid, array $attributes, array $row): array|\WP_Error
    {
        $client = $this->client();
        if ($client === null) {
            return new \WP_Error('mdp_client_unavailable', 'Wicket API client is not available.');
        }

        // Non-destructive: drop null/empty values so existing MDP data is preserved.
        $attributes = $this->stripEmpty($attributes);
        if ($attributes === []) {
            // Nothing to merge (row had no additional fields). No PATCH needed.
            return ['id' => $uuid];
        }

        $payload = [
            'data' => [
                'id'         => $uuid,
                'type'       => 'people',
                'attributes' => $attributes,
            ],
        ];

        /** AD11: fire before the update call. @see createPerson() for rationale. */
        $payload = apply_filters('wicket_import_person_data', $payload, $row, 'update');

        try {
            $response = $client->patch("people/{$uuid}", ['json' => $payload]);
        } catch (\Throwable $e) {
            $this->logger->error('MDP updatePerson failed.', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return new \WP_Error('mdp_update_failed', $e->getMessage());
        }

        $normalized = $this->normalizePerson($response);
        if ($normalized === null) {
            // 204 No Content / empty body / malformed response. The PATCH itself
            // succeeded; we just have no parseable body. Preserve the known UUID
            // so callers (mergeScenario) can still proceed.
            return ['id' => $uuid];
        }

        return $normalized;
    }

    /**
     * Whether the person currently holds at least one active MDP membership.
     *
     * Used by PersonResolver (Task 13.4) to mark rows
     * `skipped-active-membership` so existing members are not auto-processed.
     * Delegates to the rung-2 helper which queries
     * /people/{uuid}/membership_entries?filter[active_at]=now.
     *
     * S3: returns null (UNKNOWN) when the lookup itself fails (client missing,
     * request threw) so PersonResolver can fail the row CLOSED instead of
     * assuming "no active membership" and risking a duplicate. Only a
     * successful query returns a definitive bool.
     *
     * @param string $uuid Person UUID.
     *
     * @return bool|null True when one or more active memberships exist, false when
         *                   confirmed none, null when the check failed (unknown).
     */
    public function hasActiveMembership(string $uuid): ?bool
    {
        $client = $this->client();
        if ($client === null) {
            $this->logger->warning('wicket_api_client unavailable; membership status unknown.', ['uuid' => $uuid]);

            return null;
        }

        // Query the SDK client directly. The base helper
        // wicket_get_person_active_memberships() uses a `static $memberships = null;`
        // cache with NO UUID keying: in a batch the first call would poison the
        // cache and every subsequent UUID would return row 1's result. Direct query
        // is the only correct path inside a per-row loop.
        try {
            $response = $client->get("people/{$uuid}/membership_entries?filter[active_at]=now");
        } catch (\Throwable $e) {
            $this->logger->warning('Active-membership query threw; membership status unknown.', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return null;
        }

        return !empty($response['data']);
    }

    /**
     * Get the base plugin's configured SDK client, or null when unavailable.
     *
     * Centralized so every method shares one availability check + the same
     * "client missing" logging surface.
     */
    private function client(): ?object
    {
        if (!function_exists('wicket_api_client')) {
            $this->logger->error('wicket_api_client() is not available; base plugin not loaded?');

            return null;
        }
        try {
            return wicket_api_client();
        } catch (\Throwable $e) {
            $this->logger->error('wicket_api_client() threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the minimal JSON:API create payload from a normalized person array.
     * The AD11 filter (fired by createPerson()) is where extensions add more.
     *
     * @return array<string,mixed>
     */
    private function baseCreatePayload(array $person): array
    {
        $payload = [
            'data' => [
                'type'       => 'people',
                'attributes' => array_filter(
                    [
                        'given_name'  => $person['first_name'] ?? '',
                        'family_name' => $person['last_name'] ?? '',
                    ],
                    static fn ($v) => is_string($v) && trim($v) !== ''
                ),
            ],
        ];

        $email = trim((string) ($person['email'] ?? ''));
        if ($email !== '') {
            $payload['data']['relationships']['emails']['data'][] = [
                'type'       => 'emails',
                'attributes' => ['address' => $email],
            ];
        }

        return $payload;
    }

    /**
     * Reduce the various shapes the MDP SDK returns (entry resource, array, etc.)
     * to a flat, predictable array keyed by 'id' + 'attributes' + the profile
     * fields PersonResolver compares against. Centralized so callers never have
     * to guess the response shape.
     *
     * @param mixed $entry Raw response from the SDK.
     *
     * @return array|null Normalized array, or null when no usable entry was returned.
     */
    private function normalizePerson(mixed $entry): ?array
    {
        // SDK resources expose public attributes; arrays come back from GET filters.
        // Covers stdClass AND typed SDK resource objects (json round-trip), since the
        // Scolmore SDK may return either and is not guaranteed to be stdClass.
        if (is_object($entry)) {
            $decoded = json_decode(json_encode($entry) ?: '{}', true);
            $entry = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($entry)) {
            return null;
        }

        // Unwrap JSON:API envelope: top-level { data: { id, attributes } } or the
        // bare entry already sitting at the top level.
        if (isset($entry['data']) && is_array($entry['data'])) {
            $entry = $entry['data'];
        }

        $id = $entry['id'] ?? $entry['uuid'] ?? null;
        if (empty($id)) {
            return null;
        }

        $attributes = $entry['attributes'] ?? [];

        return [
            'id'         => (string) $id,
            'attributes' => is_array($attributes) ? $attributes : [],
        ];
    }

    /**
     * Remove null and whitespace-only string values so updatePerson() can never
     * overwrite existing MDP data with nothing (Task 13.3 non-destructive merge).
     *
     * @return array<string,mixed>
     */
    private function stripEmpty(array $attributes): array
    {
        $kept = [];
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $kept[$key] = $value;
        }

        return $kept;
    }
}
