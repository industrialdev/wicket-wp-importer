<?php

declare(strict_types=1);

namespace WicketImporter;

use WicketImporter\BulkImport\Database\ImportStagingTable;
use WicketImporter\BulkImport\Subscriptions\BundleRenewalSubscriber;

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
 * @method \WicketImporter\BulkImport\Subscriptions\BatchProcessor BatchProcessor()
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
     * Runtime dependency check (S4). Returns true only when the plugins the
     * importer calls unguarded are loaded: the base plugin's MDP client and
     * the memberships controllers/tiers. WooCommerce is required by
     * memberships, so its presence is implied.
     */
    private function dependenciesAvailable(): bool
    {
        return function_exists('wicket_api_client')
            && class_exists('Wicket_Memberships\Membership_Controller');
    }

    /**
     * Admin notice shown when a required sibling plugin is missing (S4), so the
     * failure is visible instead of a white screen.
     */
    public function renderMissingDependencyNotice(): void
    {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Wicket Importer is inactive: a required plugin (Wicket base plugin or Wicket Memberships) is missing or deactivated. Re-enable it to resume imports.', 'wicket-wp-importer')
            . '</p></div>';
    }

    /**
     * Setup and register all services and hooks.
     *
     * Hooked on `init` (not `plugins_loaded`) because the runtime dependency
     * check calls `wicket_api_client()`, which the base plugin loads at
     * `init` priority 0 (src/Includes.php). Hooking at `plugins_loaded` would
     * make `function_exists('wicket_api_client')` return false and abort boot.
     *
     * @wp-hook init
     */
    public function plugin_setup(): void
    {
        // S4: guard runtime dependencies. The Requires Plugins header is enforced
        // at activation, not at runtime — deactivate wicket-wp-base-plugin or
        // wicket-wp-memberships and an unguarded call below (wicket_api_client /
        // Membership_Controller) white-screens the request.
        if (!$this->dependenciesAvailable()) {
            add_action('admin_notices', [$this, 'renderMissingDependencyNotice']);

            return;
        }

        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            BulkImport\Database\DbInstaller::checkSchemaVersion();
        }

        // Core service instances
        $logger = new Services\Logger();
        $mdp_client = new BulkImport\WicketMdpClient($logger);
        $person_resolver = new BulkImport\PersonResolver($mdp_client);
        $chequeRowProcessor = new BulkImport\Subscriptions\Cheque\ChequeRowProcessor(
            new BulkImport\Subscriptions\OrderCreator(),
            new BulkImport\Subscriptions\SubscriptionCreator($logger),
            new BulkImport\Subscriptions\ProductResolver($logger),
            $logger
        );
        $batchProcessor = new BulkImport\Subscriptions\BatchProcessor($chequeRowProcessor, $logger);

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
            'BatchProcessor' => $batchProcessor,
        ];

        // Instantiate classes that register their own hooks
        new Admin\ImportAdminPage();
        new Assets();
        new BulkImport\Rest\UploadController();
        new Mapping\MappingSettings();

        // Phase 4: Lockbox answers the bundle-renewal filters fired by
        // wicket-wp-memberships (decision D-LOCKBOX-2, PULL architecture).
        new BundleRenewalSubscriber($logger);

        // Default handler for the inline import's subscription create-seam
        // (wicket_import_create_subscription). Owns the no-order subscription
        // capability so client themes never write WCS code on this path (AD1).
        new BulkImport\Subscriptions\InlineSubscriptionCreator($logger);

        // Phase 4 BatchProcessor: the Action Scheduler chunk engine. Its hook is
        // registered so AS can fire chunks; the per-row work (resolver chain ->
        // OrderCreator -> SubscriptionCreator) lands in Slice 2.
        add_action($batchProcessor::CHUNK_HOOK, [$batchProcessor, 'processChunk'], 10, 2);

        // WWID-2026: the cheque Phase 1 review's "Proceed to Phase 2" form
        // posts to admin-post.php. The handler is a stub until Phase 2 ships.
        add_action('admin_post_wicket_import_cheque_proceed', [Admin\ChequeReviewPage::class, 'handleProceed']);

        // TODO Phase 4 (cheque adapter): OrderCreator (On Hold order, cheque
        // payment, customer by Bar ID), the per-row processRow wiring that turns
        // BatchProcessor's placeholder into the resolver -> OrderCreator ->
        // SubscriptionCreator pipeline, and Cheque\Rest\ProcessController.
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
        $staging = new ImportStagingTable();

        $expired = $staging->expireStaleSessions($ttl);
        if ($expired > 0) {
            (new Services\Logger())->info(sprintf(
                'Session TTL expiry: marked %d row(s) expired (>%dh inactive).',
                $expired,
                $ttl
            ));
        }

        // C5: reclaim rows stuck in 'processing' after a crashed /run so the
        // session stops returning 409 forever. Needs-review (never pending) —
        // we cannot tell whether the membership was already created.
        $reclaimed = $staging->expireStaleClaims();
        if ($reclaimed > 0) {
            (new Services\Logger())->warning(sprintf(
                'Reclaimed %d stuck processing row(s) after an interrupted run.',
                $reclaimed
            ));
        }
    }
}
