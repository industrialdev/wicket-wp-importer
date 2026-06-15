<?php
declare(strict_types=1);

namespace WicketLockbox;

class Assets
{
	public function __construct()
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueue styles and scripts for Lockbox pages.
	 */
	public function enqueue_admin_assets( string $hook ): void
	{
		// Only enqueue on Lockbox pages
		if ( ! str_contains( $hook, 'wicket-wp-lockbox' ) ) {
			return;
		}

		wp_register_style( 'wicket-lockbox-admin', WICKET_LOCKBOX_URL . 'assets/css/admin.css', [], WICKET_LOCKBOX_VERSION );
		wp_register_script( 'wicket-lockbox-admin', WICKET_LOCKBOX_URL . 'assets/js/admin.js', [ 'jquery' ], WICKET_LOCKBOX_VERSION, true );

		wp_enqueue_style( 'wicket-lockbox-admin' );
		wp_enqueue_script( 'wicket-lockbox-admin' );
	}
}
