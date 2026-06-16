<?php
declare(strict_types=1);

namespace WicketImporter\Admin;

use WicketImporter\Support\SecuresRequests;

/**
 * Admin page for the importer. Registered as submenu under the Wicket parent (wicket-settings).
 */
class ImportAdminPage
{
	use SecuresRequests;

	public function __construct()
	{
		add_action( 'admin_menu', [ $this, 'registerMenu' ] );
	}

	/**
	 * Register the admin menu page.
	 */
	public function registerMenu(): void
	{
		add_submenu_page(
			'wicket-settings',
			__( 'Import', 'wicket-wp-importer' ),
			__( 'Import', 'wicket-wp-importer' ),
			'manage_options',
			'wicket-wp-importer',
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Render the admin page.
	 * Phase 0: empty placeholder. Phase 2 will add upload/validation/confirmation screens.
	 */
	public function renderPage(): void
	{
		$this->requireCapability();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import', 'wicket-wp-importer' ); ?></h1>
			<p><?php esc_html_e( 'Bulk import and payment processing.', 'wicket-wp-importer' ); ?></p>
		</div>
		<?php
	}
}
