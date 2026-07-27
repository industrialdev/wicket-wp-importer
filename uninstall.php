<?php
/**
 * Uninstall: drop the importer's custom tables + options when the plugin is
 * deleted via the WP admin (G7). Leaves no orphaned schema or options.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the staging + batches tables.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wicket_import_staged_records");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wicket_import_batches");

// Remove options.
delete_option('wicket_import_db_version');
delete_option('wicket_import_mappings');

// Clear the session-expiry cron.
wp_clear_scheduled_hook('wicket_import_expire_stale_sessions');
