<?php
declare(strict_types=1);

namespace WicketLockbox\BulkImport\Database;

/**
 * CRUD for the wicket_import_staged_records table.
 * Session-based staging for import rows.
 */
class ImportStagingTable
{
	private string $table_name;

	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wicket_import_staged_records';
	}

	/**
	 * Build a value fragment for a single column, handling NULL correctly.
	 * $wpdb->prepare( '%s', null ) produces '' not SQL NULL.
	 */
	private function sqlValue( mixed $value, string $format ): string
	{
		if ( $value === null ) {
			return 'NULL';
		}
		return $format;
	}

	/**
	 * Bulk insert rows for a session.
	 *
	 * @param array  $rows      Array of associative arrays matching table columns.
	 * @param string $session_id UUID for the session.
	 */
	public function insertBatch( array $rows, string $session_id ): void
	{
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$chunks = array_chunk( $rows, 500 );
		foreach ( $chunks as $chunk ) {
			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $row ) {
				$batch_id    = $row['batch_id'] ?? null;
				$raw_data    = isset( $row['raw_data'] ) ? wp_json_encode( $row['raw_data'] ) : null;
				$val_msg     = $row['validation_message'] ?? null;
				$flagged     = isset( $row['flagged_fields'] ) ? wp_json_encode( $row['flagged_fields'] ) : null;
				$mdp_uuid    = $row['mdp_uuid'] ?? null;
				$imp_msg     = $row['import_message'] ?? null;
				$ext_meta    = isset( $row['extension_metadata'] ) ? wp_json_encode( $row['extension_metadata'] ) : null;
				$order_id    = $row['order_id'] ?? null;
				$sub_ids     = $row['subscription_ids'] ?? null;

				// Build per-row placeholder string with NULL literals for null values
				$ph = '('
				    . '%s, '                          // session_id (always set)
				    . $this->sqlValue( $batch_id, '%s' ) . ', '
				    . '%d, '                          // row_index
				    . $this->sqlValue( $raw_data, '%s' ) . ', '
				    . '%s, '                          // validation_status
				    . $this->sqlValue( $val_msg, '%s' ) . ', '
				    . $this->sqlValue( $flagged, '%s' ) . ', '
				    . $this->sqlValue( $mdp_uuid, '%s' ) . ', '
				    . '%s, '                          // import_status
				    . $this->sqlValue( $imp_msg, '%s' ) . ', '
				    . $this->sqlValue( $ext_meta, '%s' ) . ', '
				    . $this->sqlValue( $order_id, '%d' ) . ', '
				    . $this->sqlValue( $sub_ids, '%s' ) . ', '
				    . '%s'                            // created_at
				    . ')';

				$placeholders[] = $ph;

				// Only push non-null values into the $values array (nulls use NULL literal)
				$values[] = $session_id;
				if ( $batch_id !== null )  $values[] = $batch_id;
				$values[] = $row['row_index'] ?? 0;
				if ( $raw_data !== null )  $values[] = $raw_data;
				$values[] = $row['validation_status'] ?? 'pending';
				if ( $val_msg !== null )   $values[] = $val_msg;
				if ( $flagged !== null )   $values[] = $flagged;
				if ( $mdp_uuid !== null )  $values[] = $mdp_uuid;
				$values[] = $row['import_status'] ?? 'pending';
				if ( $imp_msg !== null )   $values[] = $imp_msg;
				if ( $ext_meta !== null )  $values[] = $ext_meta;
				if ( $order_id !== null )  $values[] = $order_id;
				if ( $sub_ids !== null )   $values[] = $sub_ids;
				$values[] = current_time( 'mysql' );
			}

			$query = "INSERT INTO {$this->table_name}
				( session_id, batch_id, row_index, raw_data, validation_status, validation_message, flagged_fields, mdp_uuid, import_status, import_message, extension_metadata, order_id, subscription_ids, created_at )
				VALUES " . implode( ', ', $placeholders );

			$wpdb->query( $wpdb->prepare( $query, $values ) );
		}
	}

	/**
	 * Get all rows for a session.
	 */
	public function getBySession( string $session_id ): array
	{
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE session_id = %s ORDER BY row_index ASC",
				$session_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get flagged (invalid/duplicate/warning) rows for a session.
	 */
	public function getFlaggedBySession( string $session_id ): array
	{
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE session_id = %s AND validation_status IN ('invalid', 'duplicate', 'warning', 'conflict') ORDER BY row_index ASC",
				$session_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get valid rows for a session.
	 */
	public function getValidBySession( string $session_id ): array
	{
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE session_id = %s AND validation_status = 'valid' ORDER BY row_index ASC",
				$session_id
			),
			ARRAY_A
		);
	}

	/**
	 * Update import result for a single row.
	 */
	public function updateImportResult( int $id, string $import_status, ?string $import_message = null ): void
	{
		global $wpdb;
		$wpdb->update(
			$this->table_name,
			[
				'import_status' => $import_status,
				'import_message' => $import_message,
				'processed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Update the MDP UUID for a single row.
	 */
	public function updatePersonUuid( int $id, string $uuid ): void
	{
		global $wpdb;
		$wpdb->update(
			$this->table_name,
			[ 'mdp_uuid' => $uuid ],
			[ 'id' => $id ]
		);
	}

	/**
	 * Update validation result for a single row.
	 */
	public function updateValidationResult( int $id, string $validation_status, ?string $validation_message = null, ?array $flagged_fields = null ): void
	{
		global $wpdb;
		$data = [
			'validation_status'  => $validation_status,
			'validation_message' => $validation_message,
		];
		if ( $flagged_fields !== null ) {
			$data['flagged_fields'] = wp_json_encode( $flagged_fields );
		}
		$wpdb->update( $this->table_name, $data, [ 'id' => $id ] );
	}

	/**
	 * Update extension metadata for a single row.
	 */
	public function updateExtensionMetadata( int $id, array $metadata ): void
	{
		global $wpdb;
		$wpdb->update(
			$this->table_name,
			[ 'extension_metadata' => wp_json_encode( $metadata ) ],
			[ 'id' => $id ]
		);
	}

	/**
	 * Delete all rows for a session.
	 */
	public function deleteSession( string $session_id ): void
	{
		global $wpdb;
		$wpdb->delete( $this->table_name, [ 'session_id' => $session_id ] );
	}

	/**
	 * Check if any session has pending (unfinished) rows.
	 * A completed session (all rows imported/updated/skipped/failed/phase2_complete) does NOT block.
	 */
	public function hasActiveSession(): bool
	{
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE import_status = %s",
				'pending'
			)
		);
		return $count > 0;
	}

	/**
	 * Count pending rows in a session.
	 */
	public function countPendingInSession( string $session_id ): int
	{
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND import_status = 'pending'",
				$session_id
			)
		);
	}

	/**
	 * Get validation summary counts for a session.
	 */
	public function getValidationSummary( string $session_id ): array
	{
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT validation_status, COUNT(*) as count FROM {$this->table_name} WHERE session_id = %s GROUP BY validation_status",
				$session_id
			),
			ARRAY_A
		);

		$summary = [
			'valid'     => 0,
			'invalid'   => 0,
			'duplicate' => 0,
			'warning'   => 0,
			'conflict'  => 0,
			'pending'   => 0,
		];

		foreach ( $results as $row ) {
			$key = $row['validation_status'];
			if ( array_key_exists( $key, $summary ) ) {
				$summary[ $key ] = (int) $row['count'];
			}
		}

		return $summary;
	}

	/**
	 * Get import summary counts for a session.
	 */
	public function getImportSummary( string $session_id ): array
	{
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT import_status, COUNT(*) as count FROM {$this->table_name} WHERE session_id = %s GROUP BY import_status",
				$session_id
			),
			ARRAY_A
		);

		$summary = [
			'pending'            => 0,
			'imported'           => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'phase1_complete'    => 0,
			'phase2_complete'    => 0,
			'needs_review'       => 0,
		];

		foreach ( $results as $row ) {
			$key = $row['import_status'];
			if ( array_key_exists( $key, $summary ) ) {
				$summary[ $key ] = (int) $row['count'];
			}
		}

		return $summary;
	}
}
