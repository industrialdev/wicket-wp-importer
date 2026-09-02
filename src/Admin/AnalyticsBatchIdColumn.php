<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

/**
 * WWID-2428: surfaces the `_batch_id` order meta (bulk runs AND the manual
 * Story 13 field write the same key) where OBA reports on it:
 *
 * - Analytics > Orders report: a `batch_id` field on every REST row, a table
 *   column (assets/js/analytics-batch-id.js paints it), and a CSV-export
 *   column.
 * - The admin orders list table (HPOS + classic storage).
 *
 * Core itself reads WC orders per report row (`get_order_number`,
 * `get_total_formatted`), so the per-row meta read here follows the same
 * pattern; a static cache keeps repeat reads (REST + list table in one
 * request) at one wc_get_order per order.
 */
final class AnalyticsBatchIdColumn
{
    public const COLUMN_ID = 'batch_id';

    /** Analytics page marker: wc-admin JS pages all use page=wc-admin. */
    private const ANALYTICS_ORDERS_PATH = '/analytics/orders';

    /** @var array<int,string> per-request meta cache keyed by order id */
    private static array $cache = [];

    public function register(): void
    {
        // Analytics > Orders REST rows (feeds the JS table column).
        add_filter('woocommerce_rest_prepare_report_orders', [$this, 'addReportField'], 10, 2);
        // Analytics > Orders CSV download.
        add_filter('woocommerce_report_orders_export_columns', [$this, 'addExportColumn']);
        add_filter('woocommerce_report_orders_prepare_export_item', [$this, 'addExportValue'], 10, 2);
        // Admin orders list table, both storage models (same hook pairs core's
        // FulfillmentsRenderer uses).
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addListColumn']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderListCell'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'addListColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderListCellLegacy'], 10, 1);
        // JS that paints the analytics table column.
        add_action('admin_enqueue_scripts', [$this, 'enqueueAnalyticsScript']);
    }

    /**
     * Add `batch_id` to an Analytics orders report item. $response is
     * WP_REST_Response (duck-typed so unit tests can pass a double).
     *
     * @param object $response the filtered REST response.
     * @param array  $report   the raw report row (order_id key).
     */
    public function addReportField($response, array $report = [])
    {
        $orderId = isset($report['order_id']) ? (int) $report['order_id'] : 0;
        if ($orderId <= 0) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $data[self::COLUMN_ID] = self::readBatchId($orderId);
        $response->set_data($data);

        return $response;
    }

    /**
     * Add the Batch ID column to the orders report CSV export headers.
     *
     * @param array $columns column id => label.
     */
    public function addExportColumn(array $columns): array
    {
        $columns[self::COLUMN_ID] = self::columnLabel();

        return $columns;
    }

    /**
     * Add the Batch ID value to an orders report CSV export row. The export
     * path bypasses the REST response, so the meta is read here per row.
     *
     * @param array $export_item column id => row value.
     * @param array $item        the raw report row.
     */
    public function addExportValue(array $export_item, array $item): array
    {
        $orderId = isset($item['order_id']) ? (int) $item['order_id'] : 0;
        if ($orderId <= 0) {
            return $export_item;
        }

        $export_item[self::COLUMN_ID] = self::readBatchId($orderId);

        return $export_item;
    }

    /**
     * Add the column to the orders list table, before the row-actions column
     * when present (both storage models share this filter).
     *
     * @param array $columns column id => label.
     */
    public function addListColumn(array $columns): array
    {
        if (array_key_exists(self::COLUMN_ID, $columns)) {
            return $columns;
        }

        $position = array_search('wc_actions', array_keys($columns), true);
        if ($position === false) {
            $columns[self::COLUMN_ID] = self::columnLabel();

            return $columns;
        }

        return array_slice($columns, 0, $position, true)
            + [self::COLUMN_ID => self::columnLabel()]
            + array_slice($columns, $position, null, true);
    }

    /**
     * Render the list-table cell (HPOS row object).
     *
     * @param string $column column id.
     * @param object $order  WC_Order.
     */
    public function renderListCell(string $column, $order): void
    {
        if ($column !== self::COLUMN_ID) {
            return;
        }

        $orderId = is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        echo esc_html(self::readBatchId($orderId));
    }

    /**
     * Render the list-table cell (classic posts screen relies on $the_order).
     *
     * @param string $column column id.
     */
    public function renderListCellLegacy(string $column): void
    {
        if ($column !== self::COLUMN_ID) {
            return;
        }

        global $the_order;
        $orderId = is_object($the_order) && method_exists($the_order, 'get_id') ? (int) $the_order->get_id() : 0;
        echo esc_html(self::readBatchId($orderId));
    }

    /**
     * Load the analytics table-column JS on the Orders analytics page only.
     *
     * @param string $hook current admin page hook suffix.
     */
    public function enqueueAnalyticsScript(string $hook = ''): void
    {
        unset($hook);

        // Gate on the wc-admin page params (same idiom as core's
        // PageController::is_admin_page): the admin_enqueue_scripts hook
        // suffix for these submenu pages embeds the &path query, so it is
        // unusable for matching.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-targeting params, no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-targeting params, no state change.
        $path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
        if ($page !== 'wc-admin' || $path !== self::ANALYTICS_ORDERS_PATH) {
            return;
        }

        // mtime cache-bust, same rationale as Assets.php (branch builds and
        // hotfixes change the JS between releases).
        $version = (string) filemtime(WICKET_IMPORT_PATH . 'assets/js/analytics-batch-id.js');
        wp_enqueue_script(
            'wicket-import-analytics-batch-id',
            WICKET_IMPORT_URL . 'assets/js/analytics-batch-id.js',
            ['wp-hooks'],
            $version ?: WICKET_IMPORT_VERSION,
            true
        );
    }

    private static function columnLabel(): string
    {
        return __('Batch ID', 'wicket-wp-importer');
    }

    /**
     * Read `_batch_id` (the shared key: bulk stamping + the manual metabox
     * field). Empty string when the order or meta is absent.
     */
    private static function readBatchId(int $orderId): string
    {
        if (array_key_exists($orderId, self::$cache)) {
            return self::$cache[$orderId];
        }

        $value = '';
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        // wc_get_order returns WC_Order|false (never WP_Error); guard on the
        // read method so exotic order objects fail closed to empty.
        if ($order && method_exists($order, 'get_meta')) {
            $value = (string) $order->get_meta(OrderBatchIdMetabox::META_KEY);
        }

        return self::$cache[$orderId] = $value;
    }
}
