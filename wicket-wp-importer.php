<?php

/**
 * Plugin Name:       Wicket Importer
 * Plugin URI:        https://wicket.io
 * Description:       Wicket Importer plugin for bulk membership import and payment processing.
 * Version:           1.0.70
 * Author:            Wicket Inc.
 * Text Domain:       wicket-wp-importer
 * Domain Path:       /languages
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Requires Plugins:  wicket-wp-base-plugin, woocommerce, woocommerce-subscriptions, wicket-wp-memberships, wicket-wp-account-centre
 */
if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('WICKET_IMPORT_VERSION', get_file_data(__FILE__, ['Version' => 'Version'], false)['Version']);
define('WICKET_IMPORT_DB_VERSION', '1.6.0');
define('WICKET_IMPORT_PATH', plugin_dir_path(__FILE__));
define('WICKET_IMPORT_URL', plugin_dir_url(__FILE__));
define('WICKET_IMPORT_BASENAME', plugin_basename(__FILE__));
define('WICKET_IMPORT_REST_NAMESPACE', 'wicket/v1');
define('WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE', 4194304);  // 4MB
define('WICKET_IMPORT_INLINE_MAX_ROWS', 200);
define('WICKET_IMPORT_SESSION_TTL_HOURS', 24);
define('WICKET_IMPORT_CHUNK_SIZE', 25); // rows per bulk-import Action Scheduler chunk; filter: wicket_import_chunk_size

// Composer Autoloader
if (file_exists(WICKET_IMPORT_PATH . 'vendor/autoload.php')) {
    require_once WICKET_IMPORT_PATH . 'vendor/autoload.php';
}

/*
|--------------------------------------------------------------------------
| Dependency: wicket-wp-account-centre
|--------------------------------------------------------------------------
|
| This plugin requires wicket-wp-account-centre to be active. That plugin
| provides the HyperFields\ namespace that the importer's admin pages rely
| on. This plugin does not vendor the library itself.
|
| On activation we abort with a clear message if the dependency is missing.
| After activation we bail out of the bootstrap and show an admin notice if
| the dependency is deactivated while the importer is still active.
|
*/

if (!function_exists('wicket_importer_check_account_centre_dependency')) {
    /**
     * Returns true when wicket-wp-account-centre is active.
     */
    function wicket_importer_check_account_centre_dependency(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('wicket-wp-account-centre/class-wicket-acc-main.php');
    }
}

if (!function_exists('wicket_importer_dependency_missing_notice')) {
    /**
     * Renders the admin notice shown when the required plugin is inactive.
     */
    function wicket_importer_dependency_missing_notice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Wicket Importer requires the Wicket Account Centre plugin to be installed and activated.',
            'wicket-wp-importer'
        );
        echo '</p></div>';
    }
}

register_activation_hook(__FILE__, static function (): void {
    if (!wicket_importer_check_account_centre_dependency()) {
        deactivate_plugins(WICKET_IMPORT_BASENAME);
        wp_die(
            esc_html__(
                'Wicket Importer requires the Wicket Account Centre plugin to be installed and activated. Please activate it before enabling this plugin.',
                'wicket-wp-importer'
            ),
            esc_html__('Plugin activation failed', 'wicket-wp-importer'),
            ['back_link' => true]
        );
    }

    WicketImporter\BulkImport\Database\DbInstaller::createTables();
    WicketImporter\WicketImporter::scheduleSessionExpiry();
});

// Initialize the plugin. Registered on `init` (not `plugins_loaded`) because
// plugin_setup() depends on wicket_api_client(), which the base plugin loads
// at `init` priority 0. The dependency check runs on plugins_loaded, when
// account-centre's autoloader is already in place.
add_action('plugins_loaded', static function (): void {
    if (!wicket_importer_check_account_centre_dependency()) {
        add_action('admin_notices', 'wicket_importer_dependency_missing_notice');

        return;
    }

    add_action('init', [WicketImporter\WicketImporter::get_instance(), 'plugin_setup']);
});
register_deactivation_hook(__FILE__, [WicketImporter\WicketImporter::class, 'clearSessionExpiry']);
add_action('wicket_import_expire_stale_sessions', [WicketImporter\WicketImporter::class, 'expireStaleSessions']);
