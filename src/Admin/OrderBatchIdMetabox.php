<?php

declare(strict_types=1);

namespace WicketImporter\Admin;

/**
 * Story 13: a "Batch ID" field on the WooCommerce Edit Order screen so orders
 * processed manually outside the bulk tool group under the same `_batch_id`
 * meta key the bulk runs write (Story 12).
 *
 * Mounted on `woocommerce_admin_order_data_after_order_details`, which fires
 * on the classic post-based screen AND under HPOS (no add_meta_box on a fixed
 * shop_order post type, which breaks when stores move to the custom orders
 * table). Optional field: empty is valid, never blocks saving.
 *
 * Validation: the label must match the bulk format YYYYMMDD-HHMM (site-time
 * semantics; same format `BatchProcessor::startRun` generates).
 */
final class OrderBatchIdMetabox
{
    public const META_KEY = '_batch_id';

    /** Bulk-label pattern: YYYYMMDD-HHMM (e.g. 20260817-1432). */
    public const LABEL_PATTERN = '/^\d{8}-\d{2}\d{2}$/';

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 10, 2);
        // Renders the persisted invalid-value notice from a prior save POST
        // (the redirect lands on a fresh request where a save-time hook is gone).
        add_action('admin_notices', [self::class, 'renderStoredNotice']);
    }

    /**
     * Render the field inside the Order Data panel.
     *
     * @param object $order WC_Order (post-based or HPOS).
     */
    public function render($order): void
    {
        $value = (string) $order->get_meta(self::META_KEY);

        /*
         * The after_order_details hook fires INSIDE the General order column,
         * not as a sibling of the three data columns, so this must stay a
         * plain block field — NOT an order_data_column (a floated 1/3-width
         * column nested inside General squeezes against whatever follows).
         * admin.css makes the wrapper a flow-root block that contains the
         * floated form field and puts the help tip on the label's line: the
         * memberships plugin renders its floated "Wicket Membership"
         * order_data_column right after this field, and without the containment
         * the two jam together with no padding (WWID-2349).
         */
        echo '<div class="order-batch-id-field">';
        woocommerce_wp_text_input([
            'id' => self::META_KEY,
            'label' => __('Batch ID', 'wicket-wp-importer'),
            'description' => __('Optional. Format YYYYMMDD-HHMM. Group this order with a bulk-import batch for reporting.', 'wicket-wp-importer'),
            'desc_tip' => true,
            'value' => $value,
            'custom_attributes' => ['pattern' => trim(self::LABEL_PATTERN, '/^$')],
        ]);
        echo '</div>';
    }

    /**
     * Render the persisted invalid-Batch-ID notice (set by save()) and clear it.
     */
    public static function renderStoredNotice(): void
    {
        global $post;
        $orderId = isset($_GET['id']) ? absint($_GET['id']) : (int) ($post->ID ?? 0);
        $raw = get_transient('wicket_importer_batch_id_error_' . $orderId);
        if ($raw === false) {
            return;
        }
        delete_transient('wicket_importer_batch_id_error_' . $orderId);

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: the rejected value. */
                __('Invalid Batch ID "%s": the format must be YYYYMMDD-HHMM (e.g. 20260817-1432). The previous value was kept.', 'wicket-wp-importer'),
                (string) $raw
            ))
        );
    }

    /**
     * Persist on save. Invalid (non-empty, non-pattern) values are rejected
     * with an admin notice; the order still saves with the previous value.
     *
     * @param int   $orderId
     * @param object $order
     */
    public function save($orderId, $order = null): void
    {
        // POST is absent on non-edit contexts; nothing to do.
        if (!isset($_POST[self::META_KEY])) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash($_POST[self::META_KEY]));
        if ($raw === '') {
            $order->update_meta_data(self::META_KEY, '');
            $order->save();

            return;
        }

        if (preg_match(self::LABEL_PATTERN, $raw) !== 1) {
            // Reject and keep the stored value. The notice is persisted as a
            // transient because the save POST redirects: an admin_notices hook
            // registered on the save request dies with it.
            set_transient('wicket_importer_batch_id_error_' . $orderId, $raw, 5 * MINUTE_IN_SECONDS);

            return; // reject, keep the stored value
        }

        $order->update_meta_data(self::META_KEY, $raw);
        $order->save();
    }
}
