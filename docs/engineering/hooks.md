---
title: "Hooks: Filters & Actions"
audience: [developer, agent]
php_class: WicketImporter
source_files: ["src/BulkImport/ImportPipeline.php", "src/BulkImport/ImportAdapter.php", "src/BulkImport/WicketMdpClient.php", "src/Admin/ImportAdminPage.php"]
---

# Developer Hooks (Filters & Actions)

## Overview

The Importer core is **generic by design** (AD1). It has no OBA, Bar ID, MDP, cheque, order, or subscription domain knowledge. Every client-specific behavior is wired through `wicket_import_*` hooks. This is the only extension surface.

There are two consumers of the same hook surface:
- **OBA extension** (in the client child theme) — OBA bulk member import
- **Cheque code** (in this core plugin, Phase 4 — not yet built) — cheque renewal flow

Both subscribe to the same hooks; they never share a request path (OBA = inline `runImport()`, Cheque = `BatchProcessor` + Action Scheduler).

> **Reference implementation:** the OBA extension (Phase 6) is the canonical example of hooking this plugin. See workspace `docs/importer-oba-reqs.md`.

---

## Columns & Parsing

### `wicket_import_csv_columns`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_csv_columns', array $columns, array $context): array`
- **Fired from**: `ImportPipeline`, `UploadController`, `Support/ColumnOrder`
- **Default**: `[]` (core registers no columns; the extension owns them)
- **Purpose**: Register static CSV column definitions. `$context` is `['context' => 'bulk'|'individual']` so an extension can vary the required-set between the bulk CSV and the manual entry form.
- **Verified**: fired.

### `wicket_import_csv_delimiter`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_csv_delimiter', string $delimiter): string`
- **Fired from**: `FileParserService`
- **Default**: `','`
- **Purpose**: Override the CSV delimiter. OBA uses `;`.
- **Verified**: fired.

### `wicket_import_dynamic_columns`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_dynamic_columns', array $columns, array $headers, array $context): array`
- **Default**: `[]`
- **Purpose**: Register variable columns derived from parsed headers (e.g. OBA's "Other State 1..N"). Used when the static `wicket_import_csv_columns` cannot express the shape.
- **Verified**: **planned, not yet fired** (Phase 6 OBA). Document the contract; do not assume it fires today.

### `wicket_import_max_file_size`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_max_file_size', int $bytes): int`
- **Fired from**: `Assets`, `FileParserService`
- **Default**: `4194304` (4 MB, the `WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE` constant)
- **Purpose**: Override max upload size. Single enforcement point lives in `FileParserService::precheckFile()`.
- **Verified**: fired.

---

## Validation

### `wicket_import_validators`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_validators', array $validators, array $context): array`
- **Fired from**: `ValidationService` (memoized registry; fires once per instance, not per row)
- **Default**: built-in validator specs (`required`, `email`, `phone`, `date`, `zip`, `us_state`, `enum`)
- **Purpose**: Add custom row validators. Validators are **specs** (`['type'=>name, ...options]`) on `ColumnDefinition::$validators`, not class instances.
- **Verified**: fired.

---

## UI

### `wicket_import_upload_page_meta`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_upload_page_meta', array $meta): array`
- **Fired from**: `ImportAdminPage::renderPageMetaSlots()` — called on **both** the upload screen and the confirmation screen (re-fired so meta reflects post-import state)
- **Default**: `[]`
- **Each entry**: `['label' => string, 'value' => string]`
- **Purpose**: Inject UI stat boxes (e.g. "Next Bar ID"). Per AD8, OBA no longer feeds a locally-minted Bar ID here.
- **Verified**: fired.

### `wicket_import_individual_form_fields`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_individual_form_fields', array $fields, array $context): array`
- **Fired from**: `ImportAdminPage::renderIndividualForm()` with `$context = ['context' => 'individual']`
- **Default**: `[]` (core renders 8 base fields: first/last name, email, phone, address/city/state/zip + Membership Tier `<select>`)
- **Each entry**: `['name' => string, 'label' => string, 'type' => 'text'|'email'|'tel'|'date'|'select'|'textarea', 'required' => bool, 'options' => [['value','label'], ...]]`
- **Purpose**: Add fields to the manual entry form. OBA injects Middle Name, Suffix, Birthdate, Gender, Fax, Law School Code, Law School Grad Date, Type, Admit Date, dynamic state rows.
- **Verified**: fired.

### `wicket_import_membership_tier_post_type`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_membership_tier_post_type', string $postType): string`
- **Fired from**: `ImportAdminPage::renderIndividualForm()`
- **Default**: `'wicket_mship_tier'`
- **Purpose**: Override the Membership Tier CPT slug for the individual form's tier selector. Render is `post_type_exists()`-guarded, so the form degrades gracefully when `wicket-wp-memberships` is inactive.
- **Verified**: fired.

### `wicket_import_confirmation_columns`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_confirmation_columns', array $cols): array`
- **Fired from**: `ImportAdminPage::renderResultsTable()`
- **Each entry**: `['label' => string, 'extractor' => callable(array $shapedRow): mixed, 'link_extractor' => ?callable(array $shapedRow): ?string]`
- **Shaped row**: `raw_data` (decoded) + `extension_metadata` (decoded) — matches the REST `/results` endpoint shape
- **Purpose**: Add columns to the confirmation screen. Extractors are wrapped in try/catch + `is_scalar` guard so one buggy extension cannot fatal the page.
- **Verified**: fired.

---

## Person Resolution & Conflict

### `wicket_import_extract_person`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_extract_person', ?array $person, array $row): ?array`
- **Fired from**: `ImportPipeline`
- **Default**: `guessPerson()` fallback tries common canonical keys (`first_name`/`given_name`/`first`, etc.) case-insensitively
- **Returns**: `?array{first_name, last_name, email}`. `null` when no email resolves → row skipped at conflict check.
- **Purpose**: Let extensions map their own column keys to the person identity tuple.
- **Verified**: fired.

### `wicket_import_check_conflict`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_check_conflict', array $result, array $row, string $sessionId): array`
- **Fired from**: `ImportPipeline::runConflictCheck()` (read-only pre-pass; populates `mdp_uuid` and `email_conflict`, never creates/merges)
- **`$result` in**: `['match' => 'none'|'exact'|'partial', 'uuid' => ?string, 'existing' => ?array, 'conflict' => bool]`
- **Returns**: `['status' => ..., 'message' => ..., 'skip' => bool, 'mdp_uuid' => ?string]`
- **Defensive**: pipeline applies an `is_array()` type-guard — a null/scalar return is reset to a 'none' verdict so a misbehaving extension cannot TypeError-abort the batch.
- **Purpose**: Custom conflict logic (OBA's 4-tier email match lives here).
- **Verified**: fired.

---

## Person Data & Membership Lifecycle

### `wicket_import_person_data`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_person_data', array $payload, array $row, string $context): array`
- **Fired from**: `WicketMdpClient::createPerson()` (`$context = 'create'`) and `updatePerson()` (`$context = 'update'`), before the MDP API call
- **Purpose**: Inject tenant-specific fields into the MDP person payload in one request (AD11). Avoids a double PATCH per row. OBA injects `status = Good Standing`, address type, country defaults.
- **Verified**: fired.

### `wicket_import_post_person_resolved`
- **Type**: Action
- **Signature**: `do_action('wicket_import_post_person_resolved', string $uuid, array $person, array $row, int $stagingId)`
- **Fired from**: `ImportPipeline::runImport()` after `PersonResolver->resolve()` returns RESOLVED, **before** tier resolution
- **Args**: 4 (mirrors `post_membership_create` for cross-lifecycle consistency)
- **Verified**: fired.

### `wicket_import_resolve_membership_tier`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_resolve_membership_tier', int $tier, array $row): int|\WP_Error`
- **Fired from**: `ImportPipeline::runImport()` (one layer above `ImportAdapter`, so the adapter stays tier-agnostic)
- **Default**: `0` (no extension override). `ImportAdapter` produces a precise failure on tier=0.
- **Returns**: tier post ID (`int`), or `WP_Error` to short-circuit the row as `needs_review`.
- **Defensive**: `is_wp_error()` guard; WP_Error from a misbehaving extension short-circuits as `failed` rather than TypeError-aborting the batch.
- **Verified**: fired.

### `wicket_import_pre_membership_create`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_pre_membership_create', bool $proceed, array $row, array $person): bool|string|\WP_Error`
- **Fired from**: `ImportAdapter::create()`
- **Returns**: `true` (proceed), non-empty `string` (skip with reason), `WP_Error` (skip with error), or `false` (skip with generic reason, back-compat)
- **Contract**: the returned reason flows straight to the staging row; it is **NOT** read back from the (readonly) row.
- **Side effect**: a veto suppresses `wicket_import_create_subscription` for the row.
- **Verified**: fired.

### `wicket_import_membership_start_date`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_membership_start_date', ?DateTimeInterface $date, array $row): DateTimeInterface`
- **Fired from**: `ImportAdapter::create()`
- **Default**: `null`
- **Purpose**: OBA returns `admit_date` from the row.
- **Verified**: fired.

### `wicket_import_membership_status`
- **Type**: Filter
- **Signature**: `apply_filters('wicket_import_membership_status', string $status, array $row): string`
- **Fired from**: `ImportAdapter::create()`
- **Default**: `'active'`
- **Purpose**: OBA returns `'delayed'` when `admit_date` is in the future, `'active'` otherwise. Advisory: `create_local_membership_record()` may override for approval/delayed tiers.
- **Verified**: fired.

### `wicket_import_post_membership_create`
- **Type**: Action
- **Signature**: `do_action('wicket_import_post_membership_create', int $membership_id, array $person, array $row, int $stagingId)`
- **Fired from**: `ImportAdapter::create()` after `create_local_membership_record()` returns a valid ID, before return
- **Args**: 4. The 4th `$stagingId` lets extensions target the exact staged row via `ImportStagingTable::updateExtensionMetadata()`.
- **Purpose**: OBA reads the Bar ID back from MDP here (AD8 — MDP-provided, not locally generated) and writes Bar ID + tier + View-in-MDP URL into `extension_metadata`.
- **Verified**: fired.

---

## Commerce (Cheque flow — Phase 4, NOT YET FIRED)

These hooks are part of the documented contract but the cheque flow is not yet built. They are listed here so extension authors know the intended shape; do not subscribe to them yet.

### `wicket_import_create_subscription`
- **Type**: Action (planned)
- **Signature**: `do_action('wicket_import_create_subscription', int $orderId, int $personId, array $row)`
- **Purpose**: Extension creates the WC subscription. OBA subscribes (subscription, no order). Cheque code in core will subscribe (order-linked subscription).
- **Status**: **planned**. The OBA-path no-order variant currently fires from `ImportAdapter` with `$orderId = $membership_id`; the signature is stable, the order-linked variant is Phase 4.

### `wicket_import_create_order`
- **Type**: Action (planned, Phase 4)
- **Signature**: `do_action('wicket_import_create_order', int $personId, array $row, array $resolvedProducts)`
- **Purpose**: Cheque code in core subscribes; OBA does not subscribe.
- **Status**: **not yet fired**.

### `wicket_import_apply_late_fee`
- **Type**: Filter (planned, Phase 4)
- **Signature**: `apply_filters('wicket_import_apply_late_fee', array $fees, array $row, int $userId): array`
- **Purpose**: Adds late-fee line items. Cheque code in core subscribes.
- **Status**: **not yet fired**.

---

## See also

- [Architecture overview](architecture.md) — file structure, namespaces, decision records (AD1-AD14)
- [Import pipeline](import-pipeline.md) — the three-phase orchestrator (`runValidation`, `runConflictCheck`, `runImport`)
- [REST endpoints](rest-endpoints.md) — route table, request/response shapes
- Workspace `docs/importer-plan-architecture.md` — full AD catalog and the canonical extension-point table
