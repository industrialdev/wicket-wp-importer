<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Outcome of SubscriptionCreator::create() for one cheque-renewal row.
 *
 * Mirrors the MembershipResult / PersonResolutionResult VO pattern. Two
 * terminal states: created (one or more subscription IDs populated) or failed
 * (no subscriptions; message carries the reason for the staging row).
 */
final class SubscriptionResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_FAILED = 'failed';

    /**
     * @param string $status
     * @param list<int> $subscriptionIds WC subscription post IDs created.
     * @param string|null $message Failure reason (failed) or null (created).
     */
    public function __construct(
        public readonly string $status,
        public readonly array $subscriptionIds,
        public readonly ?string $message,
    ) {}

    public static function created(array $subscriptionIds): self
    {
        return new self(self::STATUS_CREATED, $subscriptionIds, null);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, [], $message);
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
