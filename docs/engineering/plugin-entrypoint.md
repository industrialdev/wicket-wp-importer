---
title: "Plugin Entrypoint"
audience: [developer, agent]
php_class: WicketImporter
source_files: ["wicket-wp-importer.php", "src/WicketImporter.php"]
---

# Plugin Entrypoint

## Bootstrap flow

`wicket-wp-importer.php` is the entry point. Sequence:

1. **Plugin header** — declares `Requires Plugins: wicket-wp-base-plugin, woocommerce, woocommerce-subscriptions, wicket-wp-memberships`. WP blocks activation if any are missing, so `Wicket_Memberships\*` classes are loadable before any importer code runs.
2. **Constants** — `WICKET_IMPORT_VERSION`, `WICKET_IMPORT_DB_VERSION`, `WICKET_IMPORT_PATH`, `WICKET_IMPORT_URL`, `WICKET_IMPORT_BASENAME`, `WICKET_IMPORT_REST_NAMESPACE`, `WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE`, `WICKET_IMPORT_INLINE_MAX_ROWS`, `WICKET_IMPORT_SESSION_TTL_HOURS` (see [architecture](architecture.md)).
3. **Autoloader** — Composer PSR-4 (`WicketImporter\` → `src/`).
4. **`register_activation_hook`** → `DbInstaller::createTables()` (idempotent `dbDelta`; installs both `wicket_import_staged_records` and `wicket_import_batches`).
5. **Hook `plugins_loaded`** → `WicketImporter::plugin_setup()` (singleton init).

## `WicketImporter::plugin_setup()`

Single shared `WicketMdpClient` + `PersonResolver` pair. `ImportPipeline` reuses the same `PersonResolver` instance (one MDP client per request).

Registered `$instances` (accessed via magic `__call`, e.g. `Importer()->Pipeline()`): Logger, Mappings, StagingTable, FileParser, Validation, ImportAdapter, MdpClient, PersonResolver, Pipeline. See [architecture](architecture.md) for the full map.

Side-effect classes instantiated in `plugin_setup()` (each registers its own hooks in its constructor): `Admin\ImportAdminPage`, `Assets`, `BulkImport\Rest\UploadController`, `Mapping\MappingSettings`.

On `admin_init` (admin or WP_CLI context only): `DbInstaller::checkSchemaVersion()` runs the schema-version gate and creates/migrates tables if the stored version is behind `WICKET_IMPORT_DB_VERSION`.

## Admin menu

Importer registers as a submenu under the Wicket parent menu (`parent_slug: wicket-settings`), per AD9. Capability-gated `manage_options`.

## See also

- [Architecture](architecture.md) — full file structure, constants, service locator
- [REST endpoints](rest-endpoints.md)
