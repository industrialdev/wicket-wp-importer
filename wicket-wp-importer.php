<?php

/**
 * Plugin Name:       Wicket Importer
 * Plugin URI:        https://wicket.io
 * Description:       Wicket Importer plugin for bulk membership import and payment processing.
 * Version:           1.0.9
 * Author:            Wicket Inc.
 * Text Domain:       wicket-wp-importer
 * Domain Path:       /languages
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Requires Plugins:  wicket-wp-base-plugin, woocommerce, woocommerce-subscriptions, wicket-wp-memberships.
 */
if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('WICKET_IMPORT_VERSION', get_file_data(__FILE__, ['Version' => 'Version'], false)['Version']);
define('WICKET_IMPORT_DB_VERSION', '1.0.0');
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

// Initialize the plugin. Registered on `init` (not `plugins_loaded`) because
// plugin_setup() depends on wicket_api_client(), which the base plugin loads
// at `init` priority 0.
add_action('init', [WicketImporter\WicketImporter::get_instance(), 'plugin_setup']);

// Activation hook for DB installation
register_activation_hook(__FILE__, [WicketImporter\BulkImport\Database\DbInstaller::class, 'createTables']);

// Session TTL auto-expiry (Task 38.3): hourly cron + activation/deactivation pair.
register_activation_hook(__FILE__, [WicketImporter\WicketImporter::class, 'scheduleSessionExpiry']);
register_deactivation_hook(__FILE__, [WicketImporter\WicketImporter::class, 'clearSessionExpiry']);
add_action('wicket_import_expire_stale_sessions', [WicketImporter\WicketImporter::class, 'expireStaleSessions']);
