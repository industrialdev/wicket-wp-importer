<?php
declare(strict_types=1);

namespace WicketImporter\Support;

/**
 * JSON helpers for reading staging-table blobs.
 *
 * Centralized so ImportAdminPage (PHP-rendered tables) and UploadController
 * (REST endpoint shaping + CSV exports) agree on the decoded shape. Both had
 * a verbatim copy of this logic; a third copy was referenced in comments.
 */
final class Json
{
	/**
	 * Decode a JSON blob from the staging table into an array (empty on miss).
	 *
	 * Stored columns (raw_data, flagged_fields, subscription_ids,
	 * extension_metadata) are nullable JSON strings. This returns a guaranteed
	 * array so callers can iterate without a type check.
	 *
	 * @param string|null $value Raw JSON string (or null).
	 *
	 * @return array<string,mixed>
	 */
	public static function decodeArray( ?string $value ): array
	{
		if ( $value === null || $value === '' ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
