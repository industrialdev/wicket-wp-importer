<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Subscriptions;

/**
 * Strategy for processing one staged bulk-import row into a terminal outcome.
 *
 * BatchProcessor delegates per-row work to a RowProcessor, so the generic chunk
 * engine stays flow-agnostic: the implementation owns any flow-specific assembly
 * from the raw row. The cheque ChequeRowProcessor resolves the membership post +
 * sections via filters, runs ProductResolver, then OrderCreator ->
 * SubscriptionCreator with the compensation rule; another flow supplies its own.
 *
 * The row -> inputs assembly lives here (not in BatchProcessor) because that is
 * where client-specific resolution lives (e.g. OBA Bar ID -> membership post),
 * which core cannot do generically (AD1).
 */
interface RowProcessor
{
    /**
     * @param array<string,mixed> $row A staged record (id, raw_data JSON, mdp_uuid, ...).
     */
    public function process(array $row): RowResult;
}
