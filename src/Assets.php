<?php
declare(strict_types=1);

namespace WicketImporter;

class Assets
{
	public function __construct()
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueue styles and scripts for Importer pages.
	 */
	public function enqueue_admin_assets( string $hook ): void
	{
		// Only enqueue on Importer pages
		if ( ! str_contains( $hook, 'wicket-wp-importer' ) ) {
			return;
		}

		wp_register_style( 'wicket-import-admin', WICKET_IMPORT_URL . 'assets/css/admin.css', [], WICKET_IMPORT_VERSION );
		wp_register_script( 'wicket-import-admin', WICKET_IMPORT_URL . 'assets/js/admin.js', [ 'jquery' ], WICKET_IMPORT_VERSION, true );

		wp_enqueue_style( 'wicket-import-admin' );
		wp_enqueue_script( 'wicket-import-admin' );
	}
}
