<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Outcome of reconciling one Phase 1 record against its own order
 * (D-LOCKBOX-4). Immutable; static constructors make the caller sites read
 * as the decision they encode.
 */
final class ReconcileResult
{
    /**
     * @param string    $status           Terminal import status: 'imported' | 'needs_review'.
     * @param string    $message          Human-readable reason for the row / report.
     * @param float     $paymentAmount    Bank-reported amount (the CSV order_total).
     * @param float     $expectedAmount   The order total the membership plugin calculated.
     * @param float     $discrepancyAmount Signed: payment - expected. Positive = surplus, negative = shortfall.
     * @param list<int> $subscriptionIds  Activated subscription IDs (empty when not processed).
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly float $paymentAmount,
        public readonly float $expectedAmount,
        public readonly float $discrepancyAmount,
        public readonly array $subscriptionIds = [],
    ) {}

    /**
     * Amounts matched within tolerance (or processed under a shortfall/surplus
     * policy): the order moved to Processing.
     *
     * @param list<int> $subscriptionIds
     */
    public static function processed(float $payment, float $expected, float $discrepancy, array $subscriptionIds, string $message): self
    {
        return new self('imported', $message, $payment, $expected, $discrepancy, $subscriptionIds);
    }

    /**
     * Held for a human (shortfall by default, surplus under a hold policy):
     * the order stays On Hold; the discrepancy is recorded.
     */
    public static function heldForReview(float $payment, float $expected, float $discrepancy, string $message): self
    {
        return new self('needs_review', $message, $payment, $expected, $discrepancy);
    }
}
