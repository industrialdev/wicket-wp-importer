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

    /**
     * Activation: schedule the hourly session-expiry event (Task 38.3).
     * Sits alongside the existing DbInstaller::createTables activation hook
     * (WordPress permits multiple activation callbacks).
     */
    public static function scheduleSessionExpiry(): void
    {
        if (!wp_next_scheduled('wicket_import_expire_stale_sessions')) {
            wp_schedule_event(time(), 'hourly', 'wicket_import_expire_stale_sessions');
        }
    }

    /**
     * Deactivation: clear the scheduled session-expiry event (Task 38.3).
     */
    public static function clearSessionExpiry(): void
    {
        wp_clear_scheduled_hook('wicket_import_expire_stale_sessions');
    }

    /**
     * Cron callback (Task 38.3): mark staged rows still pending past the TTL
     * as 'expired' so abandoned sessions stop blocking new uploads and stop
     * accumulating. Constructs the staging table directly (it only needs
     * $wpdb) so the callback never depends on the plugin singleton being
     * initialized when wp-cron fires.
     */
    public static function expireStaleSessions(): void
    {
        /** @var int $ttl Filterable session TTL in hours. */
        $ttl = (int) apply_filters('wicket_import_session_ttl_hours', WICKET_IMPORT_SESSION_TTL_HOURS);
        $expired = (new ImportStagingTable())->expireStaleSessions($ttl);

        if ($expired > 0) {
            (new Services\Logger())->info(sprintf(
                'Session TTL expiry: marked %d row(s) expired (>%dh inactive).',
                $expired,
                $ttl
            ));
        }
    }
}
