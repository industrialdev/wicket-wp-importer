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
		$installed_version = get_option( self::DB_VERSION_OPTION );
		if ( $installed_version !== WICKET_IMPORT_DB_VERSION ) {
			self::createTables();
			self::seedDefaultMappings();
			update_option( self::DB_VERSION_OPTION, WICKET_IMPORT_DB_VERSION );
		}
	}

	/**
	 * Create/update custom database tables using dbDelta.
	 */
	public static function createTables(): void
	{
		global $wpdb;

		$staged_table  = $wpdb->prefix . 'wicket_import_staged_records';
		$batches_table = $wpdb->prefix . 'wicket_import_batches';
		$collate       = $wpdb->get_charset_collate();

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
		dbDelta( $staged_sql );
		dbDelta( $batches_sql );
	}

	/**
	 * Seed the default late fee mappings into HyperFields options.
	 */
	public static function seedDefaultMappings(): void
	{
		$existing = get_option( self::MAPPINGS_OPTION );

		if ( ! is_array( $existing ) || empty( $existing ) ) {
			$defaults = [
				[
					'role_slug'        => 'late-fee-1',
					'product_id'       => 2111,
					'product_sku'      => 'LATE-1',
					'label'            => 'Late Fee 1',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-2',
					'product_id'       => 2112,
					'product_sku'      => 'LATE-2',
					'label'            => 'Late Fee 2 (Reinstatement Fee)',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-3-year-0-to-3',
					'product_id'       => 2113,
					'product_sku'      => 'LATE-3-0-TO-3',
					'label'            => 'Late Fee 3 - 0 to 3 Years',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-3-regular',
					'product_id'       => 2114,
					'product_sku'      => 'LATE-3-REG',
					'label'            => 'Late Fee 3 - Regular',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-4-year-0-to-3',
					'product_id'       => 2115,
					'product_sku'      => 'LATE-4-0-TO-3',
					'label'            => 'Late Fee 4 - 0 to 3 Years',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-4-regular',
					'product_id'       => 2116,
					'product_sku'      => 'LATE-4-REG',
					'label'            => 'Late Fee 4',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-5',
					'product_id'       => 2117,
					'product_sku'      => 'LATE-5',
					'label'            => 'Late Fee 5',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
				[
					'role_slug'        => 'late-fee-special-temporary',
					'product_id'       => 2118,
					'product_sku'      => 'LATE-ST',
					'label'            => 'Late Fee - Special Temporary',
					'mapping_type'     => 'late_fee',
					'application_type' => 'product',
					'is_active'        => 1,
				],
			];

			update_option( self::MAPPINGS_OPTION, [
				'late_fees' => $defaults,
				'discounts' => [],
				'sections'  => [],
			] );
		}
	}
}
