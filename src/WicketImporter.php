<?php

declare(strict_types=1);

namespace WicketImporter;

use WicketImporter\BulkImport\Database\ImportStagingTable;

/**
 * Main Wicket Importer plugin class.
 *
 * @method \WicketImporter\Mapping\MappingRepository Mappings()
 * @method \WicketImporter\Services\Logger Logger()
 * @method \WicketImporter\BulkImport\Database\ImportStagingTable StagingTable()
 * @method \WicketImporter\BulkImport\FileParserService FileParser()
 * @method \WicketImporter\BulkImport\ValidationService Validation()
 * @method \WicketImporter\BulkImport\ImportAdapter ImportAdapter()
 * @method \WicketImporter\BulkImport\WicketMdpClient MdpClient()
 * @method \WicketImporter\BulkImport\PersonResolver PersonResolver()
 * @method \WicketImporter\BulkImport\ImportPipeline Pipeline()
 */
final class WicketImporter
{
    /**
     * Singleton instance.
     */
    private static ?WicketImporter $instance = null;

    /**
     * Service instances registered in the plugin.
     */
    private array $instances = [];

    /**
     * Get the singleton instance.
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
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
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            BulkImport\Database\DbInstaller::checkSchemaVersion();
        }

        // Core service instances
        $logger = new Services\Logger();
        $mdp_client = new BulkImport\WicketMdpClient($logger);
        $person_resolver = new BulkImport\PersonResolver($mdp_client);

        $this->instances = [
            'Logger'         => $logger,
            'Mappings'       => new Mapping\MappingRepository(),
            'StagingTable'   => new ImportStagingTable(),
            'FileParser'     => new BulkImport\FileParserService($logger),
            'Validation'     => new BulkImport\ValidationService($logger),
            'ImportAdapter'  => new BulkImport\ImportAdapter(),
            'MdpClient'      => $mdp_client,
            'PersonResolver' => $person_resolver,
            'Pipeline'       => new BulkImport\ImportPipeline($logger, $person_resolver),
        ];

        // Instantiate classes that register their own hooks
        new Admin\ImportAdminPage();
        new Assets();
        new BulkImport\Rest\UploadController();
        new Mapping\MappingSettings();

        // TODO Phase 1: ImportPipeline
        // TODO Phase 4: Cheque\BatchProcessor, Cheque\Rest\ProcessController
    }

    /**
     * Magic method to access services as methods (e.g. WicketImporter::get_instance()->Logger()).
     */
    public function __call(string $name, array $arguments)
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }
        throw new \BadMethodCallException("Service '{$name}' does not exist in Wicket Importer instances.");
    }
}
