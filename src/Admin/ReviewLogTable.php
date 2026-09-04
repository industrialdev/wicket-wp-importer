<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

// WP_List_Table is admin-only and not autoloaded; load it on demand.
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the cheque Review UI: renders the failed + needs_review rows
 * of a Phase 1 batch so a human can review them before Phase 2 (WWID-2026).
 *
 * Receives PRE-SHAPED rows from ChequeReviewPage (line, status, reason,
 * order_id, fix, data). This class renders only; it decodes nothing and owns no
 * business logic, so it stays a dumb presentation surface (AD1: no client
 * identifiers here; the caller shapes the data).
 *
 * Not paginated server-side: a cheque batch is bounded by the inline cap, so
 * the full reviewable set renders in one table. Pagination belongs to the
 * History detail screen, not the review gate.
 */
final class ReviewLogTable extends \WP_List_Table
{
    /**
     * Shaped rows to render. Set via setRows() before display().
     *
     * @var list<array<string,mixed>>
     */
    private array $rows = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'review-row',
            'plural'   => 'review-rows',
            'ajax'     => false,
        ]);
    }

    /**
     * Inject the shaped rows to render.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = array_values($rows);
    }

    /**
     * Column headers. Order matches the render order in column_default.
     *
     * @return array<string,string>
     */
    public function get_columns(): array
    {
        return [
            'line'     => __('Line', 'wicket-wp-importer'),
            'data'     => __('Member / Data', 'wicket-wp-importer'),
            'status'   => __('Status', 'wicket-wp-importer'),
            'reason'   => __('Reason', 'wicket-wp-importer'),
            'order_id' => __('Order ID', 'wicket-wp-importer'),
            'fix'      => __('Suggested Fix', 'wicket-wp-importer'),
        ];
    }

    /**
     * Render a single column cell. Escapes every value (admin output).
     *
     * @param array<string,mixed> $item
     */
    public function column_default($item, $column_name)
    {
        $value = $item[$column_name] ?? '';

        switch ($column_name) {
            case 'status':
                return $this->renderStatusBadge((string) $value);
            case 'order_id':
                // WWID-2439: link straight to the order edit screen (HPOS
                // route) so the admin can act on a held/failed order without
                // copy-pasting the id into a search box.
                if ($value === '' || $value === null) {
                    return '<span aria-hidden="true">&mdash;</span>';
                }
                $orderUrl = admin_url('admin.php?page=wc-orders&action=edit&id=' . rawurlencode((string) $value));

                return '<a href="' . esc_url($orderUrl) . '"><code>' . esc_html((string) $value) . '</code></a>';
            case 'data':
                // Already shaped into a readable string by the controller.
                return esc_html((string) $value);
            case 'reason':
            case 'fix':
            case 'line':
            default:
                return esc_html((string) $value);
        }
    }

    /**
     * Render a colored status badge for failed vs needs_review.
     */
    private function renderStatusBadge(string $status): string
    {
        $label = $status === 'needs_review'
            ? __('Needs Review', 'wicket-wp-importer')
            : __('Failed', 'wicket-wp-importer');
        $class = $status === 'needs_review' ? 'review-status review-status--review' : 'review-status review-status--failed';

        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * No bulk actions on the review table: each row is reviewed individually.
     *
     * @return array<array<string,string>>
     */
    protected function get_bulk_actions(): array
    {
        return [];
    }

    /**
     * Populate items from the injected rows. Pagination is disabled (the full
     * reviewable set renders at once).
     */
    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $this->rows;
        $this->set_pagination_args([
            'total_items' => count($this->rows),
            'per_page'    => max(1, count($this->rows)),
            'total_pages' => 1,
        ]);
    }
}
