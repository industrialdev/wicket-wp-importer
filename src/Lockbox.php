<?php
declare(strict_types=1);

namespace WicketLockbox;

use WicketLockbox\BulkImport\Database\ImportStagingTable;

/**
 * Main Wicket Lockbox plugin class.
 *
 * @method \WicketLockbox\Mapping\MappingRepository Mappings()
 * @method \WicketLockbox\Services\Logger Logger()
 * @method \WicketLockbox\BulkImport\Database\ImportStagingTable StagingTable()
 * @method \WicketLockbox\BulkImport\FileParserService FileParser()
 */
class Lockbox
{
	/**
	 * Singleton instance.
	 */
	private static ?Lockbox $instance = null;

	/**
	 * Service instances registered in the plugin.
	 */
	private array $instances = [];

	/**
	 * Get the singleton instance.
	 */
	public static function get_instance(): self
	{
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Static wrapper for hooks setup.
	 */
	public static function plugin_setup(): void
	{
		self::get_instance()->setup();
	}

	/**
	 * Setup and register all services and hooks.
	 */
	private function setup(): void
	{
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			\WicketLockbox\BulkImport\Database\DbInstaller::checkSchemaVersion();
		}

		// Core service instances
		$logger = new \WicketLockbox\Services\Logger();

		$this->instances = [
			'Logger'       => $logger,
			'Mappings'     => new \WicketLockbox\Mapping\MappingRepository(),
			'StagingTable' => new ImportStagingTable(),
			'FileParser'   => new \WicketLockbox\BulkImport\FileParserService( $logger ),
		];

		// Instantiate classes that register their own hooks
		new \WicketLockbox\Admin\ImportAdminPage();
		new \WicketLockbox\Assets();
		new \WicketLockbox\Mapping\MappingSettings();

		// TODO Phase 1: ValidationService, ImportPipeline, UploadController
		// TODO Phase 4: Cheque\BatchProcessor, Cheque\Rest\ProcessController
	}

	/**
	 * Magic method to access services as methods (e.g. Lockbox::get_instance()->Logger()).
	 */
	public function __call( string $name, array $arguments )
	{
		if ( isset( $this->instances[ $name ] ) ) {
			return $this->instances[ $name ];
		}
		throw new \BadMethodCallException( "Service '{$name}' does not exist in Wicket Lockbox instances." );
	}
}
