<?php
declare(strict_types=1);

namespace WicketImporter;

/**
 * Enqueues admin assets for Importer pages.
 *
 * Screen-gated: assets only load when the current admin page slug contains
 * 'wicket-wp-importer'. The REST config is localized so Task 8's admin.js can
 * POST to the upload/run/clear endpoints without hardcoding URLs or the nonce.
 */
class Assets
{
	/**
	 * Localized config object key. admin.js reads window.WicketImportAdmin.
	 */
	private const L10N_KEY = 'WicketImportAdmin';

	public function __construct()
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueue styles + scripts on Importer pages only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void
	{
		// Only enqueue on Importer pages. The hook suffix for our submenu is
		// 'wicket-settings_page_wicket-wp-importer'; the substring check is
		// robust against the parent-slug prefix.
		if ( ! str_contains( $hook, 'wicket-wp-importer' ) ) {
			return;
		}

		wp_register_style( 'wicket-import-admin', WICKET_IMPORT_URL . 'assets/css/admin.css', [], WICKET_IMPORT_VERSION );
		// wp-i18n provides wp.i18n.__ / sprintf / _n for admin.js strings (Task 8 S3):
		// the prior hand-rolled {n}/{max} placeholders were a translator hazard.
		wp_register_script( 'wicket-import-admin', WICKET_IMPORT_URL . 'assets/js/admin.js', [ 'jquery', 'wp-i18n' ], WICKET_IMPORT_VERSION, true );

		wp_enqueue_style( 'wicket-import-admin' );

		// Load JS translations for admin.js (wp.i18n.__ with the plugin text domain).
		// Falls back to the English in the source when no .json is shipped yet.
		wp_set_script_translations( 'wicket-import-admin', 'wicket-wp-importer', WICKET_IMPORT_PATH . 'languages' );

		// REST config for admin.js: base URL, nonce, current screen + session.
		$screen    = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : 'upload';
		$sessionId = '';
		if ( isset( $_GET['session_id'] ) ) {
			$maybe = sanitize_key( wp_unslash( $_GET['session_id'] ) );
			if ( preg_match( '/^[0-9a-fA-F-]{36}$/', $maybe ) === 1 ) {
				$sessionId = $maybe;
			}
		}

		wp_localize_script( 'wicket-import-admin', self::L10N_KEY, [
			'restRoot'        => esc_url_raw( rest_url( WICKET_IMPORT_REST_NAMESPACE . '/import' ) ),
			'restNonce'       => wp_create_nonce( 'wp_rest' ),
			'screen'          => $screen,
			'sessionId'       => $sessionId,
			'maxFileSize'     => (int) apply_filters( 'wicket_import_max_file_size', WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE ),
			'confirmationUrl' => esc_url_raw( admin_url( 'admin.php?page=wicket-wp-importer&screen=confirmation' ) ),
			'uploadUrl'       => esc_url_raw( admin_url( 'admin.php?page=wicket-wp-importer&screen=upload' ) ),
		] );

		wp_enqueue_script( 'wicket-import-admin' );
	}
}
