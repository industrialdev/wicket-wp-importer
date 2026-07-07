---
title: "Plugin Architecture"
audience: [developer, agent]
php_class: WicketImporter
source_files: ["wicket-wp-importer.php", "src/WicketImporter.php"]
---

# Plugin Architecture

## Identity

| Field | Value |
|-------|-------|
| Plugin Name | Wicket Importer |
| Text Domain | `wicket-wp-importer` |
| Namespace | `WicketImporter\\` |
| PHP | 8.2+, `strict_types=1` |
| Requires | `wicket-wp-base-plugin`, `woocommerce`, `woocommerce-subscriptions`, `wicket-wp-memberships` |
| Entry file | `wicket-wp-importer.php` |
| Main class | `WicketImporter\WicketImporter` (singleton) |
| REST namespace | `wicket/v1` |

## Core design principle

The core is **generic by design** (AD1). It has zero OBA / Bar ID / MDP-tenant / cheque / order / subscription domain knowledge. Client logic rides on `wicket_import_*` hooks (see [hooks](hooks.md)). Two flows share one core:

- **OBA bulk member import** — CSV upload → validate → create MDP person + membership + subscription. Inline processor (`ImportPipeline::runImport`, 200-row cap).
- **Cheque renewal** (Phase 4-5, not yet built) — Bulk Create On-Hold orders + Pending subscriptions, then Bulk Process to Processing + activate subscriptions. `Cheque\BatchProcessor` + Action Scheduler.

The two processors do not share a path (AD6).

## File structure (what is built today)

```
wicket-wp-importer/
├── wicket-wp-importer.php          # Entry point, constants, autoload, init hook
├── composer.json
├── index.html
│
├── src/                            # PSR-4 (WicketImporter\)
│   ├── WicketImporter.php          # Main singleton (mirrors WicketAcc pattern)
│   ├── Assets.php                  # Admin CSS/JS enqueuing
│   │
│   ├── Admin/                      # namespace WicketImporter\Admin
│   │   ├── ImportAdminPage.php     # Screen router + upload/validation/confirmation + individual form
│   │   └── MappingSettingsPage.php # Mapping settings admin page (Phase 4, placeholder)
│   │
│   ├── BulkImport/                 # namespace WicketImporter\BulkImport
│   │   ├── FileParserService.php   # CSV parser with column registry
│   │   ├── ValidationService.php   # Validators + duplicate detection
│   │   ├── ImportPipeline.php      # Three-phase orchestrator (OBA flow)
│   │   ├── ImportAdapter.php       # Membership CPT creation (fires hooks, no direct WCS)
│   │   ├── WicketMdpClient.php     # MDP person CRUD wrapper (AD15 priority ladder)
│   │   ├── PersonResolver.php      # Scenario A/B/email_conflict decision tree
│   │   ├── PersonResolutionResult.php
│   │   ├── MemberData.php          # VO: person + row + tierPostId + stagingId
│   │   ├── MembershipResult.php    # VO: created/skipped/failed named constructors
│   │   ├── Database/
│   │   │   ├── DbInstaller.php
│   │   │   └── ImportStagingTable.php
│   │   └── Rest/
│   │       └── UploadController.php  # All REST endpoints
│   │
│   ├── Cheque/                     # Phase 4-5, NOT YET BUILT
│   │   └── ... (BatchProcessor, TierResolver, OrderCreator, etc.)
│   │
│   ├── Mapping/                    # namespace WicketImporter\Mapping (partial: 15.1/15.2/15.4 built)
│   │   ├── MappingEntry.php        # BUILT
│   │   ├── MappingRepository.php   # BUILT
│   │   └── MappingSettings.php     # BUILT
│   │
│   ├── ValueObjects/               # namespace WicketImporter\ValueObjects
│   ├── Validators/                 # namespace WicketImporter\Validators
│   │
│   ├── Services/                   # namespace WicketImporter\Services
│   │   └── Logger.php              # WC_Logger wrapper, error_log fallback
│   │
│   └── Support/                    # namespace WicketImporter\Support
│       ├── CsvExporter.php         # AD14 CSV injection prevention
│       ├── ColumnOrder.php         # Shared column-order resolver
│       ├── Json.php                # Shared staging-table JSON blob decoder
│       └── SecuresRequests.php     # manage_options + nonce trait
│
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── docs/                           # this folder
```

> The Phase 4 `Cheque/` subtree is omitted here. See [roadmap](roadmap.md) for the planned cheque phases.

## Constants

Defined in `wicket-wp-importer.php`:

| Constant | Value | Purpose |
|----------|-------|---------|
| `WICKET_IMPORT_VERSION` | `'1.0.0'` | Plugin version |
| `WICKET_IMPORT_DB_VERSION` | `'1.0.0'` | Staging table schema version |
| `WICKET_IMPORT_PATH` | `plugin_dir_path(__FILE__)` | Base path |
| `WICKET_IMPORT_URL` | `plugin_dir_url(__FILE__)` | Base URL |
| `WICKET_IMPORT_BASENAME` | `plugin_basename(__FILE__)` | Plugin basename |
| `WICKET_IMPORT_REST_NAMESPACE` | `'wicket/v1'` | REST namespace |
| `WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE` | `4194304` | 4 MB default (filterable) |
| `WICKET_IMPORT_INLINE_MAX_ROWS` | `200` | Inline cap; over → HTTP 413 |
| `WICKET_IMPORT_SESSION_TTL_HOURS` | `24` | Auto-expire sessions after 24h |

## Service locator (`WicketImporter::get_instance()`)

Registered in `WicketImporter::setup()`. Access via magic `__call`:

- `Importer()->Logger()` → `Services\Logger`
- `Importer()->Mappings()` → `Mapping\MappingRepository`
- `Importer()->StagingTable()` → `BulkImport\Database\ImportStagingTable`
- `Importer()->FileParser()` → `BulkImport\FileParserService`
- `Importer()->Validation()` → `BulkImport\ValidationService`
- `Importer()->ImportAdapter()` → `BulkImport\ImportAdapter`
- `Importer()->MdpClient()` → `BulkImport\WicketMdpClient`
- `Importer()->PersonResolver()` → `BulkImport\PersonResolver`
- `Importer()->Pipeline()` → `BulkImport\ImportPipeline`

Side-effect classes instantiated in `setup()` (register their own hooks): `Admin\ImportAdminPage`, `Assets`, `BulkImport\Rest\UploadController`, `Mapping\MappingSettings`. `DbInstaller::checkSchemaVersion()` runs on `admin_init`.

## Database tables

Two tables, both namespaced via `$wpdb->prefix`:

- `{$wpdb->prefix}wicket_import_staged_records` — session-based staging. One row per CSV row. Carries `validation_status`, `import_status`, `mdp_uuid`, `extension_metadata`, `order_id`, `subscription_ids`.
- `{$wpdb->prefix}wicket_import_batches` — persistent batch table for the cheque flow (Phase 4). Schema is already installed; not yet used.

Full column/index reference: the schema is encoded in `src/Services/DbInstaller.php` (`createTables()`).

## Architectural Decisions (AD1-AD15)

Encoded directly in the code. The full catalog is summarized below; the load-bearing ones for daily work:

- **AD1** — Core stays generic; client logic lives in extensions.
- **AD6** — Two processors, distinct scaling paths (OBA inline 200-row cap; Cheque AS 50/chunk).
- **AD8** — Bar ID is MDP-provided. The extension reads it back; never mints one locally.
- **AD10** — `ImportAdapter` fires `wicket_import_create_subscription`; core never calls WC Subscriptions directly.
- **AD11** — `wicket_import_person_data` filter fires before every MDP create/update (avoids double PATCH).
- **AD12** — `runConflictCheck` is a thin shell: it computes a core verdict, then fires `wicket_import_check_conflict` so an extension (OBA's 4-tier email + Bar ID check) can override. The core verdict is the filter's starting point.
- **AD14** — Every CSV export prefixes cells starting with `= + - @ \t \r` with a tab. Injection-safe via `Support\CsvExporter`.
- **AD15** — MDP integration priority ladder: `wicket-wp-memberships` → `wicket-wp-base-plugin` → `wicket-wp-account-centre` → direct MDP API (last resort; document WHY).

## Status: what ships today (2026-07-03)

- **Phase 0** (scaffold, DB, admin shell, security, Logger) — done
- **Phase 1** (parser, validators, REST upload endpoints) — done
- **Phase 2** (admin UI: upload/validation/confirmation + individual form) — done
- **Phase 3** (pipeline orchestrator, MDP integration, membership adapter) — done
- **Phase 4-5** (cheque flow) — not started
- **Phase 6** (OBA extension in OBA child theme) — not started
- **Phase 7** (polish + E2E tests) — not started

See [roadmap](roadmap.md) for phase status, sequencing, and transition gates.

## See also

- [Hooks](hooks.md) — full filter/action catalog
- [Import pipeline](import-pipeline.md) — three-phase orchestrator detail
- [REST endpoints](rest-endpoints.md) — route table
- [Plugin entrypoint](plugin-entrypoint.md) — bootstrap flow
