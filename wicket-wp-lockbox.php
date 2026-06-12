<?php
/**
 * Plugin Name:       Wicket Lockbox
 * Plugin URI:        https://wicket.io
 * Description:       Wicket Lockbox plugin for bulk membership import and payment processing.
 * Version:           1.0.0
 * Author:            Wicket Inc.
 * Text Domain:       wicket-wp-lockbox
 * Domain Path:       /languages
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Requires Plugins:  wicket-wp-base-plugin, woocommerce, woocommerce-subscriptions, wicket-wp-memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants
define( 'WICKET_LOCKBOX_VERSION', '1.0.0' );
define( 'WICKET_LOCKBOX_DB_VERSION', '1.0.0' );
define( 'WICKET_LOCKBOX_PATH', plugin_dir_path( __FILE__ ) );
define( 'WICKET_LOCKBOX_URL', plugin_dir_url( __FILE__ ) );
define( 'WICKET_LOCKBOX_BASENAME', plugin_basename( __FILE__ ) );
define( 'WICKET_LOCKBOX_REST_NAMESPACE', 'wicket/v1' );
define( 'WICKET_LOCKBOX_DEFAULT_MAX_FILE_SIZE', 4194304 );  // 4MB
define( 'WICKET_LOCKBOX_INLINE_MAX_ROWS', 200 );
define( 'WICKET_LOCKBOX_SESSION_TTL_HOURS', 24 );

// Composer Autoloader
if ( file_exists( WICKET_LOCKBOX_PATH . 'vendor/autoload.php' ) ) {
	require_once WICKET_LOCKBOX_PATH . 'vendor/autoload.php';
}

// Initialize the plugin
add_action( 'plugins_loaded', [ \WicketLockbox\Lockbox::class, 'plugin_setup' ] );

// Activation hook for DB installation
register_activation_hook( __FILE__, [ \WicketLockbox\BulkImport\Database\DbInstaller::class, 'createTables' ] );
