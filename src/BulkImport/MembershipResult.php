<?php
declare(strict_types=1);

namespace WicketImporter\BulkImport;

/**
 * Outcome of ImportAdapter::create() for a single row.
 *
 * Three terminal states mirror the staging table's import_status vocabulary:
 *  - created  -> CPT inserted (and MDP membership assigned). membershipId + wicketUuid populated.
 *  - skipped  -> wicket_import_pre_membership_create returned false. No CPT, no subscription.
 *  - failed   -> MDP assign or CPT insert errored. message carries the reason for the staging row.
 *
 * The Task 12 ImportPipeline caller maps these onto staging import_status
 * (created -> imported/updated, skipped -> skipped, failed -> failed).
 */
final class MembershipResult
{
	public const STATUS_CREATED = 'created';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_FAILED  = 'failed';

	private function __construct(
		public readonly string $status,
		public readonly ?int $membershipId,
		public readonly ?string $wicketUuid,
		public readonly ?string $message,
	) {
	}

	public static function created( int $membershipId, string $wicketUuid ): self
	{
		return new self( self::STATUS_CREATED, $membershipId, $wicketUuid, null );
	}

	public static function skipped( string $reason ): self
	{
		return new self( self::STATUS_SKIPPED, null, null, $reason );
	}

	public static function failed( string $message ): self
	{
		return new self( self::STATUS_FAILED, null, null, $message );
	}

	public function isCreated(): bool
	{
		return $this->status === self::STATUS_CREATED;
	}

	public function isSkipped(): bool
	{
		return $this->status === self::STATUS_SKIPPED;
	}

	public function isFailed(): bool
	{
		return $this->status === self::STATUS_FAILED;
	}
}
