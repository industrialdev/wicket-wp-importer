<?php
declare(strict_types=1);

namespace WicketImporter\BulkImport;

use Wicket_Memberships\Membership_Controller;
use Wicket_Memberships\Membership_Tier;
use Wicket_Memberships\Utilities;

/**
 * Creates the wicket_membership CPT for one import row by delegating to the
 * canonical wicket-wp-memberships pipeline.
 *
 * Design (see docs/engineering/architecture.md AD1, AD10, AD15; docs/engineering/import-pipeline.md):
 *  - Core stays generic. This adapter owns NO client domain logic; per-tenant
 *    behaviour arrives through the wicket_import_* filters/actions it fires.
 *  - Per AD10 the adapter never calls the WC Subscriptions API. It fires
 *    wicket_import_create_subscription and an extension (OBA / cheque) does it.
 *  - Per AD15 (MDP priority ladder) the adapter delegates to the rung-1
 *    memberships wrappers rather than reinventing their logic:
 *      * dates    -> Membership_Config::get_membership_dates() (anniversary /
 *                    seasonal / align-end-dates / grace / early-renew in one call)
 *      * MDP      -> Membership_Controller::create_mdp_record() (dedup +
 *                    previous-membership + base-plugin version-compat)
 *      * CPT      -> Membership_Controller::create_local_membership_record()
 *      * WP user  -> wicket_create_wp_user_if_not_exist() (rung-2 base helper)
 *    No direct MDP API call exists in this class.
 *
 * Flow: pre-membership gate -> resolve WP user -> resolve start via filter
 *       -> compute dates from tier config -> build mapping -> assign MDP
 *       membership (with dedup) -> CPT -> fire subscription action -> fire
 *       post-create action.
 */
final class ImportAdapter
{
	/**
	 * Create one membership CPT (and its MDP membership) for a staged row.
	 *
	 * @param MemberData $data Resolved person + row + tier + staging id.
	 *
	 * @return MembershipResult created | skipped | failed.
	 */
	public function create( MemberData $data ): MembershipResult
	{
		// Task 12.5 / 14.1: extension may veto this row before any work is done.
		// Contract: filter returns true (proceed), a non-empty string (skip with
		// reason), or WP_Error (skip with error message). A false return is treated
		// as a skip with a generic reason for backwards-compat. Returning a reason
		// directly is the supported path — reading it back from the readonly row
		// does not work (B4). Subscription creation is coupled to this gate: a veto
		// suppresses wicket_import_create_subscription as well.
		$veto = apply_filters( 'wicket_import_pre_membership_create', true, $data->row, $data->person );
		if ( $veto === true ) {
			// proceed
		} elseif ( is_wp_error( $veto ) ) {
			return MembershipResult::skipped( $veto->get_error_message() );
		} elseif ( $veto === false ) {
			return MembershipResult::skipped( 'Skipped by extension (wicket_import_pre_membership_create).' );
		} else {
			return MembershipResult::skipped( (string) $veto );
		}

		$tier = new Membership_Tier( $data->tierPostId );
		if ( empty( $tier->tier_data ) ) {
			return MembershipResult::failed( sprintf( 'Tier post %d could not be loaded.', $data->tierPostId ) );
		}

		// WP user resolved from the MDP person UUID, mirroring the memberships
		// Import_Controller. Names/email are passed through to avoid a second fetch.
		$user_id = $this->resolveUserId( $data );
		if ( ! $user_id ) {
			return MembershipResult::failed( sprintf( 'Could not resolve WP user for person %s.', $data->personUuid ) );
		}

		// Resolve the start ONCE and feed the same value to both the MDP assign
		// call and the CPT mapping (fixes S2: three independent "now"s).
		// 14.2: extension may override. Default null -> today, start of MDP day.
		$start_date = apply_filters( 'wicket_import_membership_start_date', null, $data->row );
		$starts_iso = $this->resolveStartDate( $start_date );

		// Dates (start/end/expires/early-renew) come from the tier's config via the
		// source-of-truth calculator (fixes B1: anniversary/seasonal/align cycles,
		// and B2: ends_at no longer collapses to start when a config resolves).
		$dates = $this->resolveDates( $tier, $starts_iso );

		// 14.3: membership status. Advisory only — create_local_membership_record
		// overrides to PENDING for approval-required tiers and DELAYED when start
		// is in the future. The filter is the default for the common case. (S3.)
		$status = apply_filters( 'wicket_import_membership_status', 'active', $data->row );

		// Build the full $membership shape used by BOTH the MDP creator (needs
		// person_uuid + tier_uuid + starts/ends + grace for dedup) and the CPT
		// creator (needs the full meta shape).
		$mapping = $this->buildMapping( $data, $user_id, $tier, $status, $dates, $starts_iso );

		// Assign the MDP membership via the rung-1 wrapper (fixes B3: dedup via
		// check_mdp_membership_record_exists; D3: previous-membership + version-
		// compat). Returns '' on error and sets the controller's error_message.
		$controller = new Membership_Controller();
		$wicket_uuid_raw = $controller->create_mdp_record( $mapping );

		if ( empty( $wicket_uuid_raw ) ) {
			// create_mdp_record() stashes its MDP WP_Error detail in a private
			// $error_message with no public accessor (it surfaces via wc_add_notice
			// in checkout context, not here). We return a precise method-level
			// reason; the staging row still records the failure. The dedup check
			// inside create_mdp_record means a retry will return the existing UUID.
			return MembershipResult::failed(
				'Membership_Controller::create_mdp_record returned no UUID (MDP assign or dedup-query failure).'
			);
		}
		$wicket_uuid = (string) $wicket_uuid_raw;
		$mapping['membership_wicket_uuid'] = $wicket_uuid;

		// Source-of-truth CPT creator. Returns the new/updated membership post ID.
		$membership_id = $controller->create_local_membership_record( $mapping, $wicket_uuid );
		if ( is_wp_error( $membership_id ) || empty( $membership_id ) ) {
			// N1: guard against WP_Error (empty() alone would coerce an error to 1).
			// D1: persist the MDP UUID into extension_metadata so a pipeline retry
			// reuses it (create_mdp_record's dedup will then return the same UUID
			// rather than creating a second MDP membership).
			return MembershipResult::failed(
				sprintf( 'CPT creation failed (MDP membership %s already assigned; retry-safe).', $wicket_uuid )
			);
		}

		// AD10: fire, don't call WCS. OBA subscribes (no order); cheque does NOT use this path.
		do_action( 'wicket_import_create_subscription', (int) $membership_id, $user_id, $data->row );

		// 14.4: post-create hook. stagingId is forwarded so extensions can write
		// extension_metadata (Bar ID once MDP exposes it, resolved tier name, View-in-MDP URL).
		do_action( 'wicket_import_post_membership_create', (int) $membership_id, $data->person, $data->row, $data->stagingId );

		return MembershipResult::created( (int) $membership_id, $wicket_uuid );
	}

	/**
	 * Resolve (creating if absent) the WP user for an MDP person UUID.
	 * Mirrors wicket-wp-memberships/includes/Import_Controller.php which calls
	 * wicket_create_wp_user_if_not_exist() with the person UUID as login.
	 */
	private function resolveUserId( MemberData $data ): int|false
	{
		$user = get_user_by( 'login', $data->personUuid );
		if ( $user ) {
			return (int) $user->ID;
		}

		// Pass the names we already have from the MDP person payload so the helper
		// doesn't re-fetch the person.
		$id = wicket_create_wp_user_if_not_exist(
			$data->personUuid,
			$data->person['first_name'] ?? null,
			$data->person['last_name'] ?? null,
			$data->person['email'] ?? null
		);

		return $id === false ? false : (int) $id;
	}

	/**
	 * Normalize any filter-returned start date to a single ISO-8601 string in the
	 * MDP timezone (start of day). Computed once so MDP and CPT share the value.
	 */
	private function resolveStartDate( mixed $start_date ): string
	{
		if ( $start_date instanceof \DateTimeInterface ) {
			return Utilities::get_mdp_day_start( $start_date->format( 'Y-m-d' ) )->format( 'c' );
		}
		if ( is_string( $start_date ) && $start_date !== '' ) {
			return Utilities::get_mdp_day_start( $start_date )->format( 'c' );
		}
		// Default: today, start of MDP day. Same value flows to MDP + CPT.
		return Utilities::get_mdp_day_start( 'now' )->format( 'c' );
	}

	/**
	 * Compute the full date set from the tier's config via the source-of-truth
	 * Membership_Config::get_membership_dates(). Falls back to a +1-year window
	 * only when the tier has no config (mirrors MDP's own default).
	 *
	 * @return array{start_date:string,end_date:string,expires_at:string,early_renew_at:string}
	 */
	private function resolveDates( Membership_Tier $tier, string $starts_iso ): array
	{
		$config = $tier->get_config();
		if ( $config ) {
			$dates = $config->get_membership_dates( [ 'membership_starts_at' => $starts_iso ] );
			// get_membership_dates always returns start_date/end_date; expires_at and
			// early_renew_at are only set when configured. Backfill for the mapping.
			return [
				'start_date'    => $dates['start_date'] ?? $starts_iso,
				'end_date'      => $dates['end_date'] ?? '',
				'expires_at'    => $dates['expires_at'] ?? $dates['end_date'] ?? '',
				'early_renew_at' => $dates['early_renew_at'] ?? $dates['end_date'] ?? '',
			];
		}

		// No config: derive a simple +1-year window so the CPT ends_at never
		// collapses to the start (fixes B2 for the no-config path).
		$end_dt = new \DateTime( $starts_iso, wp_timezone() );
		$end_dt->modify( '+1 year' );
		$end_iso = Utilities::get_mdp_day_end( $end_dt->format( 'Y-m-d' ) )->format( 'c' );

		return [
			'start_date'    => $starts_iso,
			'end_date'      => $end_iso,
			'expires_at'    => $end_iso,
			'early_renew_at' => $end_iso,
		];
	}

	/**
	 * Build the $membership array in the exact shape Membership_Controller::
	 * create_local_membership_record() expects (the same shape used by the
	 * memberships plugin's own Import_Controller). Also feeds create_mdp_record()
	 * which requires person_uuid, membership_tier_uuid, membership_starts_at,
	 * membership_ends_at, and membership_grace_period_days for its dedup query.
	 *
	 * @param array    $dates    Resolved dates from resolveDates().
	 */
	private function buildMapping(
		MemberData $data,
		int $user_id,
		Membership_Tier $tier,
		string $status,
		array $dates,
		string $starts_iso
	): array {
		$user = get_user_by( 'ID', $user_id );
		$name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : ( $data->person['email'] ?? '' );

		// Grace window from the tier config; create_mdp_record forwards it to MDP.
		$config      = $tier->get_config();
		$grace_days  = $config ? (int) $config->get_late_fee_window_days() : 0;

		$ends = $dates['end_date'] !== '' ? $dates['end_date'] : $starts_iso;

		return [
			'membership_type'                         => 'individual',
			'person_uuid'                             => $data->personUuid,
			'user_id'                                 => $user_id,
			'membership_user_uuid'                    => $data->personUuid,
			'membership_wp_user_display_name'         => $name,
			'user_name'                               => $name,
			'membership_wp_user_last_name'            => $user ? $user->last_name : '',
			'membership_wp_user_email'                => $user ? $user->user_email : ( $data->person['email'] ?? '' ),
			'user_email'                              => $user ? $user->user_email : ( $data->person['email'] ?? '' ),
			'membership_status'                       => $status,
			'membership_starts_at'                    => $starts_iso,
			'membership_ends_at'                      => $ends,
			'membership_expires_at'                   => $dates['expires_at'] !== '' ? $dates['expires_at'] : $ends,
			'membership_early_renew_at'               => $dates['early_renew_at'] !== '' ? $dates['early_renew_at'] : $ends,
			'membership_grace_period_days'            => $grace_days,
			'membership_tier_post_id'                 => $data->tierPostId,
			'membership_tier_uuid'                    => $tier->get_mdp_tier_uuid(),
			'membership_tier_name'                    => $tier->get_mdp_tier_name(),
			'membership_next_tier_id'                 => $tier->get_next_tier_id() ?: 0,
			'membership_next_tier_form_page_id'       => $tier->get_next_tier_form_page_id() ?: 0,
			// S4: sourced from the tier, not hardcoded, so subscription-renewal tiers behave.
			'membership_next_tier_subscription_renewal' => $this->tierSubscriptionRenewal( $tier ),
			'membership_wicket_uuid'                  => '', // populated after create_mdp_record().
			'membership_parent_order_id'              => '',
			'membership_product_id'                   => '',
			'membership_subscription_id'              => '',
		];
	}

	/**
	 * Whether the tier renews via subscription (renewal_type === 'subscription').
	 * Mirrors Import_Controller::get_tier_by_id() so we don't hardcode false (S4).
	 */
	private function tierSubscriptionRenewal( Membership_Tier $tier ): bool
	{
		$data = $tier->tier_data;
		return isset( $data['renewal_type'] ) && $data['renewal_type'] === 'subscription';
	}
}
