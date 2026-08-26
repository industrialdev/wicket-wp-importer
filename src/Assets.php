<?php

declare(strict_types=1);

namespace WicketImporter;

/**
 * Enqueues admin assets for Importer pages.
 *
 * Screen-gated: assets only load when the current admin page slug contains
 * 'wicket-wp-importer'. The REST config is localized so Task 8's admin.js can
 * POST to the upload/run/clear endpoints without hardcoding URLs or the nonce.
 */
class Assets
{
    /**
     * Localized config object key. admin.js reads window.WicketImportAdmin.
     */
    private const L10N_KEY = 'WicketImportAdmin';

    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue styles + scripts on Importer pages only.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public function enqueue_admin_assets(string $hook): void
    {
        /*
         * Two screens load admin assets: Importer pages (full bundle) and
         * the WooCommerce order edit screen, where admin.css carries the
         * Story 13 Batch ID field's formatting rules (WWID-2349). JS (and
         * the REST config below) stays on Importer pages only. The gate runs
         * before any filemtime() call so unrelated admin pages pay nothing.
         */
        $isImporterPage = str_contains($hook, 'wicket-wp-importer');
        if (!$isImporterPage && !self::isOrderEditScreen($hook)) {
            return;
        }

        /*
         * Cache-bust by file mtime, not the plugin version: the admin JS/CSS
         * can change between releases (branch builds, hotfixes synced to a
         * client), and a same-version asset URL keeps serving the stale file
         * from browser cache — the new Upload-tab flow then half-loads (PHP
         * markup fresh, JS behaviors old). mtime changes with every edit.
         */
        $cssVer = (string) filemtime(WICKET_IMPORT_PATH . 'assets/css/admin.css');
        wp_register_style('wicket-import-admin', WICKET_IMPORT_URL . 'assets/css/admin.css', [], $cssVer ?: WICKET_IMPORT_VERSION);
        wp_enqueue_style('wicket-import-admin');

        if (!$isImporterPage) {
            return;
        }

        $jsVer = (string) filemtime(WICKET_IMPORT_PATH . 'assets/js/admin.js');
        // wp-i18n provides wp.i18n.__ / sprintf / _n for admin.js strings (Task 8 S3):
        // the prior hand-rolled {n}/{max} placeholders were a translator hazard.
        wp_register_script('wicket-import-admin', WICKET_IMPORT_URL . 'assets/js/admin.js', ['wp-i18n'], $jsVer ?: WICKET_IMPORT_VERSION, true);

        // Load JS translations for admin.js (wp.i18n.__ with the plugin text domain).
        // Falls back to the English in the source when no .json is shipped yet.
        wp_set_script_translations('wicket-import-admin', 'wicket-wp-importer', WICKET_IMPORT_PATH . 'languages');

        // REST config for admin.js: base URL, nonce, current screen + session.
        $screen = isset($_GET['screen']) ? sanitize_key(wp_unslash($_GET['screen'])) : 'upload';
        $sessionId = '';
        if (isset($_GET['session_id'])) {
            $maybe = sanitize_key(wp_unslash($_GET['session_id']));
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $maybe) === 1) {
                $sessionId = $maybe;
            }
        }

        wp_localize_script('wicket-import-admin', self::L10N_KEY, [
            'restRoot'        => esc_url_raw(rest_url(WICKET_IMPORT_REST_NAMESPACE . '/import')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'screen'          => $screen,
            'sessionId'       => $sessionId,
            'maxFileSize'     => (int) apply_filters('wicket_import_max_file_size', WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE),
            'confirmationUrl' => esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&screen=confirmation')),
            'uploadUrl'       => esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&screen=upload')),
            'historyUrl'      => esc_url_raw(admin_url('admin.php?page=wicket-wp-importer&tab=history')),
        ]);

        wp_enqueue_script('wicket-import-admin');
    }

    /**
     * Is this a WooCommerce order edit screen? Covers both storage models:
     * HPOS (admin.php?page=wc-orders, hook suffix 'woocommerce_page_wc-orders')
     * and the classic post screen ('post.php' / 'post-new.php' on shop_order).
     *
     * @param string $hook The current admin page hook suffix.
     */
    private static function isOrderEditScreen(string $hook): bool
    {
        if ($hook === 'woocommerce_page_wc-orders') {
            // Edit AND Add-new render the same order-data panel (the Story 13
            // Batch ID field mounts on both); the list screen has no action.
            $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

            return in_array($action, ['edit', 'new'], true);
        }

        if ($hook === 'post.php' || $hook === 'post-new.php') {
            $postId = isset($_GET['post']) ? absint($_GET['post']) : 0;

            return $postId > 0 && get_post_type($postId) === 'shop_order';
        }

        return false;
    }
}
