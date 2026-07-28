<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Outcome of OrderCreator::create() for one cheque-renewal row.
 *
 * Mirrors the SubscriptionResult / MembershipResult VO pattern. Two terminal
 * states: created (the WC order ID populated) or failed (no order; message
 * carries the reason for the staging row).
 */
final class OrderResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_FAILED = 'failed';

    /**
     * @param string       $status
     * @param int|null     $orderId  WC order ID when created; null on failure.
     * @param string|null  $message  Failure reason (failed) or null (created).
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $orderId,
        public readonly ?string $message,
    ) {}

    public static function created(int $orderId): self
    {
        return new self(self::STATUS_CREATED, $orderId, null);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, null, $message);
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
