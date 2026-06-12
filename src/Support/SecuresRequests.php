<?php
declare(strict_types=1);

namespace WicketLockbox\Support;

/**
 * Security baseline for admin pages and REST endpoints.
 * Provides capability checks and nonce verification.
 */
trait SecuresRequests
{
	/**
	 * Verify the current user can manage Lockbox.
	 * Uses manage_options (admin-level) per plan task 3.3.
	 */
	protected function requireCapability(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wicket-wp-lockbox' ), 403 );
		}
	}

	/**
	 * REST permission_callback for manage_options capability.
	 * Returns true/false (no wp_die) for REST context.
	 */
	public function restPermissionCheck(): bool
	{
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verify nonce for form or AJAX requests.
	 */
	protected function verifyNonce( string $action = 'wicket_lockbox_nonce', string $query_arg = '_wpnonce' ): void
	{
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ?? '' ) ), $action ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'wicket-wp-lockbox' ), 403 );
		}
	}
}
