<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport;

/**
 * Outcome of resolving one import row against the MDP (Task 13).
 *
 * PersonResolver::resolve() returns one of these. The pipeline (Task 12) maps
 * the status + person onto the staging row (mdp_uuid + import_status) and then
 * hands off to ImportAdapter for membership creation. This VO deliberately
 * carries no staging side-effects — it is pure data, mirroring MembershipResult
 * from Task 14.
 *
 * Status semantics:
 *  - RESOLVED                  Scenario A (created) or Scenario B (merged). A
 *                             UUID is present and the row is ready for the
 *                             membership-creation step.
 *  - EMAIL_CONFLICT            Email matched an existing person but the names
 *                             differ (partial match). Not auto-processed; needs
 *                             human review (Task 13.4).
 *  - SKIPPED_ACTIVE_MEMBERSHIP Email + name matched but the person already holds
 *                             an active membership. Not auto-processed (13.4).
 *  - FAILED                    MDP API error. Row continues as a failure but
 *                             the batch does not halt (13.5).
 *
 * Note on `skipped-by-extension` (13.3): the wicket_import_pre_membership_create
 * veto is owned by ImportAdapter (Task 14.1) to avoid a double-fire, so this VO
 * does not carry that status — it surfaces as MembershipResult::skipped
 * downstream.
 */
final class PersonResolutionResult
{
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_EMAIL_CONFLICT = 'email_conflict';
    public const STATUS_SKIPPED_ACTIVE_MEMBERSHIP = 'skipped_active_membership';
    public const STATUS_FAILED = 'failed';

    /**
     * @param string      $status   One of the STATUS_* constants.
     * @param string|null $uuid     Resolved MDP person UUID (RESOLVED only).
     * @param array|null  $person   Normalized MDP person entry (RESOLVED only).
     *                              Carried so the pipeline can seed WP user
     *                              creation + CPT meta without a re-fetch.
     * @param string|null $message  Human-readable detail for non-RESOLVED states.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $uuid = null,
        public readonly ?array $person = null,
        public readonly ?string $message = null,
    ) {}

    /** Scenario A/B success: a UUID was resolved and the row may proceed. */
    public static function resolved(string $uuid, array $person): self
    {
        return new self(self::STATUS_RESOLVED, $uuid, $person);
    }

    /** Partial match (email hit, name differs). Needs review. */
    public static function emailConflict(string $message): self
    {
        return new self(self::STATUS_EMAIL_CONFLICT, null, null, $message);
    }

    /** Exact match but the person already has an active membership. */
    public static function skippedActiveMembership(string $message): self
    {
        return new self(self::STATUS_SKIPPED_ACTIVE_MEMBERSHIP, null, null, $message);
    }

    /** MDP API failure; row failed but batch continues. */
    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, null, null, $message);
    }

    /** Convenience for the pipeline: did resolution yield a usable UUID? */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }
}
