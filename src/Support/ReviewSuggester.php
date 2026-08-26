<?php

declare(strict_types=1);

namespace WicketImporter\Support;

/**
 * Suggests a remediation action for a cheque review row, derived from its
 * status + failure reason. Shared by the Review UI table, the review page, and
 * the error CSV export so all three agree (WWID-2026).
 *
 * This is a documented heuristic, not a rule engine: it maps the failure
 * categories the resolver chain + OrderCreator + SubscriptionCreator emit today
 * to a one-line human action. Revise when Phase 2 or clearer failure categories
 * land. Keep it fail-safe: an unknown reason always yields a generic action,
 * never an empty cell.
 */
final class ReviewSuggester
{
    /**
     * Suggest a fix for one review row.
     *
     * @param string $status import_status (failed | needs_review | ...).
     * @param string $reason import_message.
     */
    public static function suggestedFix(string $status, string $reason): string
    {
        if ($status === 'needs_review') {
            return __('Reconcile the retained On Hold order with the failed subscription before proceeding.', 'wicket-wp-importer');
        }
        if ($reason !== '' && stripos($reason, 'diverg') !== false) {
            return __('Correct the order total in the source CSV, then re-upload the batch.', 'wicket-wp-importer');
        }
        if ($reason !== '' && stripos($reason, 'no member or membership') !== false) {
            return __('Verify the member identifier exists on this site and the member has an active membership.', 'wicket-wp-importer');
        }
        if ($reason !== '' && stripos($reason, 'product resolution') !== false) {
            return __('Check the tier succession map and the product mappings.', 'wicket-wp-importer');
        }
        if ($reason !== '' && stripos($reason, 'order') !== false && stripos($reason, 'fail') !== false) {
            return __('Check WooCommerce product setup and customer resolution.', 'wicket-wp-importer');
        }

        return __('Review the source row; correct it and re-run the batch if needed.', 'wicket-wp-importer');
    }
}
