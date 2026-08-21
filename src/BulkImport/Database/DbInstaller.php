<?php

declare(strict_types=1);

namespace WicketImporter\BulkImport\Database;

class DbInstaller
{
    private const DB_VERSION_OPTION = 'wicket_import_db_version';
    public const MAPPINGS_OPTION = 'wicket_import_mappings';

    /**
     * Check schema version and run install/migration if needed.
     *
     * Surface a visible drift warning when the installed version lags behind
     * the plugin's WICKET_IMPORT_DB_VERSION so the administrator knows they
     * should deactivate + reactivate the plugin to recreate the database DV
     * (dbDelta is forward-only and silently adds columns/tables, so an
     * administrator who only updates without re-activation never sees a hint).
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
            // Remember the previous version so the admin notice can show
            // "from -> to" and remind the admin about DV recreation. The
            // option is updated below; the transient is the dismissable surface.
            if (is_string($installed_version) && $installed_version !== '' && $installed_version !== WICKET_IMPORT_DB_VERSION) {
                set_transient('wicket_importer_db_drift', [
                    'from' => $installed_version,
                    'to' => WICKET_IMPORT_DB_VERSION,
                ], 5 * MINUTE_IN_SECONDS);
            }
        }
    }

    /**
     * Render the dismissable DB drift notice on importer admin pages.
     * Hooked in WicketImporter::plugin_setup().
     */
    public static function maybeRenderDriftNotice(): void
    {
        $drift = get_transient('wicket_importer_db_drift');
        if (!is_array($drift)) {
            return;
        }

        // Per-admin dismissal (user meta so each admin sees it once).
        $userId = get_current_user_id();
        if ($userId === 0 || get_user_meta($userId, 'wicket_importer_db_drift_dismissed') === '1') {
            return;
        }

        $from = (string) ($drift['from'] ?? 'none');
        $to = (string) ($drift['to'] ?? WICKET_IMPORT_DB_VERSION);

        printf(
            '<div class="notice notice-warning wicket-importer-db-drift"><p>%s</p><p><a href="%s">%s</a></p></div>',
            esc_html(sprintf(
                /* translators: 1: previous db version, 2: current db version. */
                __('Wicket Importer: the database schema was upgraded from %1$s to %2$s. If you depend on the database schema (DV) being recreated, deactivate and reactivate the plugin in Plugins > Installed Plugins.', 'wicket-wp-importer'),
                $from,
                $to
            )),
            esc_url(add_query_arg('wicket_importer_dismiss_drift', '1')),
            esc_html__("I've handled this.", 'wicket-wp-importer')
        );
    }

    /**
     * Dismiss the drift notice for the current admin (per-user).
     */
    public static function dismissDriftNotice(): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }
        if (isset($_GET['wicket_importer_dismiss_drift']) && $_GET['wicket_importer_dismiss_drift'] === '1') {
            update_user_meta($userId, 'wicket_importer_db_drift_dismissed', '1');
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
  processing_claimed_at datetime DEFAULT NULL,
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
  batch_label varchar(20) DEFAULT NULL,
  import_flow varchar(20) NOT NULL DEFAULT 'member',
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

        // Phase 2 (Slice 5) payment rows. Separate table so the cheque/Phase 1
        // staging path stays untouched; keyed by the same session_id + batch_id
        // for unified review/history surfaces.
        $payments_table = $wpdb->prefix . 'wicket_import_payment_records';
        $payments_sql = "CREATE TABLE {$payments_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id char(36) NOT NULL,
  batch_id char(36) DEFAULT NULL,
  row_index int(11) NOT NULL DEFAULT 0,
  raw_data longtext DEFAULT NULL,
  validation_status varchar(40) NOT NULL DEFAULT 'pending',
  validation_message text DEFAULT NULL,
  import_status varchar(40) NOT NULL DEFAULT 'pending',
  import_message text DEFAULT NULL,
  processing_claimed_at datetime DEFAULT NULL,
  matched_order_id bigint(20) unsigned DEFAULT NULL,
  matched_subscription_ids text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_session (session_id),
  KEY idx_session_status (session_id, import_status),
  KEY idx_batch (batch_id),
  KEY idx_batch_status (batch_id, import_status),
  KEY idx_matched_order (matched_order_id)
) {$collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($staged_sql);
        dbDelta($batches_sql);
        dbDelta($payments_sql);
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
