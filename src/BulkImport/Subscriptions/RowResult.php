<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Outcome of a RowProcessor::process() for one staged row: the terminal
 * import_status to apply, plus the WC order ID to retain (when an order was
 * created) and an optional message.
 *
 * Carrying orderId separately from the message lets BatchProcessor write it back
 * to the staging row via ImportStagingTable::updateOrderId, so a needs_review
 * row points at the order an admin must reconcile.
 */
final class RowResult
{
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * @param string      $status
     * @param int|null    $orderId  WC order ID when one was created; null otherwise.
     * @param string|null $message  Reason for a non-imported outcome.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $orderId = null,
        public readonly ?string $message = null,
    ) {}

    public static function imported(?int $orderId = null, ?string $message = null): self
    {
        return new self(self::STATUS_IMPORTED, $orderId, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, null, $message);
    }

    public static function needsReview(string $message, int $orderId): self
    {
        return new self(self::STATUS_NEEDS_REVIEW, $orderId, $message);
    }

    public function isImported(): bool
    {
        return $this->status === self::STATUS_IMPORTED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isNeedsReview(): bool
    {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }
}
