<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

/**
 * Immutable bundle of everything ImportAdapter needs to create one membership.
 *
 * Carries the resolved MDP person (post Task 13), the originating CSV row
 * (so extension filters can read domain fields like OBA's admit_date), the
 * resolved tier post ID (post Task 12.7 tier filter), and the staging row ID
 * (so extension_metadata can be written back to the staged record).
 *
 * WP user ID is intentionally NOT carried: ImportAdapter resolves it from the
 * person UUID via wicket_create_wp_user_if_not_exist(), matching the canonical
 * wicket-wp-memberships Import_Controller flow.
 */
final class MemberData
{
    /**
     * @param string $personUuid   MDP person UUID (authoritative; exists after Task 13 MDP integration).
     * @param array  $person       MDP person payload (first_name, last_name, email, etc.) used both to
     *                             seed WP user creation and to populate CPT meta.
     * @param array  $row          Original CSV row (associative, keyed by column key). Passed through to
     *                             every wicket_import_* filter so extensions can read domain fields.
     * @param int    $tierPostId   Resolved wicket_mship_tier post ID (output of the
     *                             wicket_import_resolve_membership_tier filter in Task 12.7).
     * @param int    $stagingId    Staged-records row ID. Forwarded to wicket_import_post_membership_create
     *                             so extensions can write extension_metadata back to the staging table.
     */
    public function __construct(
        public readonly string $personUuid,
        public readonly array $person,
        public readonly array $row,
        public readonly int $tierPostId,
        public readonly int $stagingId,
    ) {}
}
