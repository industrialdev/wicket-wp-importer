<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Database;

class DbInstaller
{
    private const DB_VERSION_OPTION = 'wicket_import_db_version';
    public const MAPPINGS_OPTION = 'wicket_import_mappings';

    /**
     * Check schema version and run install/migration if needed.
     */
    public static function checkSchemaVersion(): void
    {
        $installed_version = get_option(self::DB_VERSION_OPTION);
        if ($installed_version !== WICKET_IMPORT_DB_VERSION) {
            self::createTables();
            self::seedDefaultMappings();
            update_option(self::DB_VERSION_OPTION, WICKET_IMPORT_DB_VERSION);
            // Backfill the session-expiry cron on upgrade so installs activated
            // before Task 38.3 land the schedule without a manual re-activation
            // (register_activation_hook only fires on activate). Idempotent.
            \WicketImporter\WicketImporter::scheduleSessionExpiry();
        }
    }

    /**
     * Create/update custom database tables using dbDelta.
     */
    public static function createTables(): void
    {
        global $wpdb;

        $staged_table = $wpdb->prefix . 'wicket_import_staged_records';
        $batches_table = $wpdb->prefix . 'wicket_import_batches';
        $collate = $wpdb->get_charset_collate();

        $staged_sql = "CREATE TABLE {$staged_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id char(36) NOT NULL,
  batch_id char(36) DEFAULT NULL,
  row_index int(11) NOT NULL DEFAULT 0,
  raw_data longtext DEFAULT NULL,
  validation_status varchar(40) NOT NULL DEFAULT 'pending',
  validation_message text DEFAULT NULL,
  flagged_fields text DEFAULT NULL,
  mdp_uuid varchar(36) DEFAULT NULL,
  import_status varchar(40) NOT NULL DEFAULT 'pending',
  import_message text DEFAULT NULL,
  extension_metadata longtext DEFAULT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  subscription_ids text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_session (session_id),
  KEY idx_session_validation (session_id, validation_status),
  KEY idx_session_import (session_id, import_status),
  KEY idx_session_created (session_id, created_at),
  KEY idx_batch (batch_id),
  KEY idx_batch_status (batch_id, import_status)
) {$collate};";

        $batches_sql = "CREATE TABLE {$batches_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id char(36) NOT NULL,
  session_id char(36) NOT NULL,
  status varchar(30) NOT NULL DEFAULT 'pending',
  created_by_user_id bigint(20) unsigned NOT NULL,
  csv_filename varchar(255) DEFAULT NULL,
  csv_row_count int(11) NOT NULL DEFAULT 0,
  mapping_config longtext DEFAULT NULL,
  phase1_total int(11) NOT NULL DEFAULT 0,
  phase1_succeeded int(11) NOT NULL DEFAULT 0,
  phase1_failed int(11) NOT NULL DEFAULT 0,
  phase1_needs_review int(11) NOT NULL DEFAULT 0,
  phase2_total int(11) NOT NULL DEFAULT 0,
  phase2_succeeded int(11) NOT NULL DEFAULT 0,
  phase2_failed int(11) NOT NULL DEFAULT 0,
  phase2_needs_review int(11) NOT NULL DEFAULT 0,
  conflicting_roles longtext DEFAULT NULL,
  report_csv_path varchar(500) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  phase1_started_at datetime DEFAULT NULL,
  phase1_completed_at datetime DEFAULT NULL,
  phase2_started_at datetime DEFAULT NULL,
  phase2_completed_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_batch_id (batch_id),
  KEY idx_session (session_id),
  KEY idx_status (status),
  KEY idx_created_by (created_by_user_id),
  KEY idx_created_at (created_at)
) {$collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($staged_sql);
        dbDelta($batches_sql);
    }

    /**
     * Initialize the HyperFields mappings option.
     *
     * Core ships NO client billing mappings (AD1: no client/cheque/lockbox
     * domain data in core). The option is seeded empty; a client (e.g. OBA via
     * the child theme) supplies its late-fee/discount/section table through the
     * wicket_import_default_mappings filter, keyed by SKU so it carries zero
     * environment-specific product IDs (D-LOCKBOX-1: resolve IDs at runtime).
     */
    public static function seedDefaultMappings(): void
    {
        $existing = get_option(self::MAPPINGS_OPTION);
        if (is_array($existing) && $existing !== []) {
            return;
        }

        /**
         * Let a client extension supply its default billing mappings.
         *
         * @param array $defaults Shape: ['late_fees' => [], 'discounts' => [], 'sections' => []].
         */
        $defaults = apply_filters('wicket_import_default_mappings', [
            'late_fees' => [],
            'discounts' => [],
            'sections'  => [],
        ]);

        // Defensive: enforce the three buckets exist as arrays regardless of
        // what the filter returned, so MappingRepository never fatals.
        if (!is_array($defaults)) {
            $defaults = [];
        }
        foreach (['late_fees', 'discounts', 'sections'] as $bucket) {
            if (!isset($defaults[$bucket]) || !is_array($defaults[$bucket])) {
                $defaults[$bucket] = [];
            }
        }

        update_option(self::MAPPINGS_OPTION, $defaults, false);
    }
}
