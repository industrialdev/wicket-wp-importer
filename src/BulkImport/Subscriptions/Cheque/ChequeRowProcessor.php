<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions\Cheque;

use WicketImporter\BulkImport\MemberData;
use WicketImporter\BulkImport\Subscriptions\OrderCreator;
use WicketImporter\BulkImport\Subscriptions\ProductResolver;
use WicketImporter\BulkImport\Subscriptions\ResolvedProducts;
use WicketImporter\BulkImport\Subscriptions\RowProcessor;
use WicketImporter\BulkImport\Subscriptions\RowResult;
use WicketImporter\BulkImport\Subscriptions\SubscriptionCreator;
use WicketImporter\Services\Logger;

/**
 * The cheque-flow RowProcessor: assemble a row's inputs (via the generic filter
 * seams a client answers), run ProductResolver -> OrderCreator ->
 * SubscriptionCreator, and apply the compensation rule.
 *
 * GENERICITY (AD1): core resolves NOTHING client-specific here. The membership
 * post and section slugs come from filter seams a client answers
 * (wicket_import_resolve_membership_post / wicket_import_resolve_section_slugs);
 * the WC customer comes from wicket_import_resolve_order_customer inside
 * OrderCreator. OBA supplies all three from its Bar ID; core is agnostic.
 *
 * Compensation: a SubscriptionCreator failure AFTER the On Hold order was
 * created flags the row needs_review and retains the order_id (never
 * auto-cancels), mirroring the "MDP touched, WP side incomplete" pattern.
 * A resolution error, a divergent total, or an order-only failure stays
 * 'failed' with no order_id, since nothing durable was written.
 */
final class ChequeRowProcessor implements RowProcessor
{
    public function __construct(
        private readonly OrderCreator $orderCreator,
        private readonly SubscriptionCreator $subscriptionCreator,
        private readonly ProductResolver $productResolver,
        private readonly ?Logger $logger = null,
    ) {}

    public function process(array $row): RowResult
    {
        $data = $this->decode($row['raw_data'] ?? null);
        $stagingId = (int) ($row['id'] ?? 0);

        // Client-sourced inputs (OBA answers these from its Bar ID; defaults are
        // inert so core never references a client identifier).
        $csvTotal = (float) ($data['order_total'] ?? 0);
        $membershipPostId = (int) apply_filters('wicket_import_resolve_membership_post', 0, $data);
        $sectionSlugs = (array) apply_filters('wicket_import_resolve_section_slugs', [], $data);

        $resolved = $this->productResolver->resolve($membershipPostId, $sectionSlugs, $csvTotal);
        if ($resolved->isError()) {
            return RowResult::failed('Product resolution failed: ' . (string) $resolved->error);
        }
        if ($resolved->divergent) {
            // The CSV total disagrees with the calculated total past tolerance:
            // do not create an order for a wrong amount. Gated here (defensive)
            // as well as upstream in the batch.
            return RowResult::failed(sprintf(
                'CSV total %.2F diverges from the expected %.2F.',
                $csvTotal,
                $resolved->expectedTotal
            ));
        }

        $memberData = new MemberData(
            personUuid: (string) ($row['mdp_uuid'] ?? ''),
            person: [],
            row: $data,
            tierPostId: 0,
            stagingId: $stagingId,
        );

        $order = $this->orderCreator->create($memberData, $resolved);
        if ($order->isFailed()) {
            // No order was created: a plain failure, nothing to reconcile.
            return RowResult::failed($order->message ?? 'Order creation failed.');
        }

        $orderId = (int) $order->orderId;
        $sub = $this->subscriptionCreator->create($orderId, $memberData, $resolved);
        if ($sub->isFailed()) {
            // Compensation: the order exists but its subscription did not.
            // Retain the order_id and flag for review; never auto-cancel.
            $this->logger?->warning('Subscription creation failed after order; flagging needs_review.', [
                'order_id' => $orderId,
                'staging_id' => $stagingId,
            ]);

            return RowResult::needsReview(
                'Subscription creation failed after order creation: ' . ($sub->message ?? 'unknown error'),
                $orderId
            );
        }

        return RowResult::imported($orderId);
    }

    /**
     * Decode the raw_data JSON blob on a staged row. Centralized + forgiving so
     * a malformed blob never fatals the row (it yields [] and the row fails
     * downstream on a missing order_total).
     *
     * @return array<string,mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($raw) ? $raw : [];
    }
}
