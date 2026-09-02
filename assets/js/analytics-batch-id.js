/**
 * Wicket Importer - Analytics Batch ID column (WWID-2428)
 *
 * Analytics > Orders: PHP adds a `batch_id` field to every report row via the
 * `woocommerce_rest_prepare_report_orders` filter (AnalyticsBatchIdColumn.php).
 * This filter paints the matching header + row cells.
 *
 * Payload contract (verified live against this store and mirrored from the
 * WooCommerce PDF Invoices plugin's analytics-order.js, which paints its
 * Invoice Number column the same way): the `woocommerce_admin_report_table`
 * filter receives {endpoint, headers, rows, totals, summary, items} where
 * `items` is the paginated query result object {data: [...], total} and
 * `rows` is an array of cell arrays shaped {display, value}, index-aligned
 * with items.data. Do NOT require rows.length === items.data.length: real
 * renders violate that (placeholder rows), which silently drops the column.
 *
 * Depends on wp-hooks. No jQuery, same as admin.js.
 */
(function() {
	'use strict';

	if (typeof window.wp === 'undefined' || !wp.hooks || !wp.hooks.addFilter) {
		return;
	}

	wp.hooks.addFilter('woocommerce_admin_report_table', 'wicket-importer/analytics-batch-id', function(tableData) {
		if (!tableData || tableData.endpoint !== 'orders') {
			return tableData;
		}

		// wc-admin hands over {data: [...], total}; accept a bare array too.
		var rawItems = tableData.items;
		var items = rawItems && Array.isArray(rawItems.data) ? rawItems.data : (Array.isArray(rawItems) ? rawItems : null);

		if (!items || !items.length || items[0].batch_id === undefined) {
			return tableData;
		}

		tableData.headers = (tableData.headers || []).concat([
			{ label: 'Batch ID', key: 'batch_id', screenReaderLabel: 'Batch ID', isSortable: false, visible: true }
		]);

		tableData.rows = (tableData.rows || []).map(function(cells, index) {
			var value = items[index] ? (items[index].batch_id || '') : '';

			return (Array.isArray(cells) ? cells : []).concat([{ display: value, value: value }]);
		});

		return tableData;
	});
})();
