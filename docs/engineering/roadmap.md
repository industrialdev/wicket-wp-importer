---
title: "Roadmap & Remaining Scope"
audience: [developer, agent]
---

# Roadmap & Remaining Scope

What is built, what remains, and the sequencing rules. Phases 0-3 (OBA Onboarding path) are shipped; the phases below are the forward-looking scope. Source of truth for "what's left" before v1.

## Phase status

| Phase | Scope | Status |
|-------|-------|--------|
| Phase 0: Foundation | DB, service locator, constants | ✅ Done |
| Phase 1: Core Importer | Parsing, validation, staging, REST upload | ✅ Done |
| Phase 2: Admin UI | Upload / Validation / Confirmation screens | ✅ Done |
| Phase 3: Pipeline + Logic | `runImport`, MDP integration, `ImportAdapter` | ✅ Done |
| Phase 4: Cheque Bulk Create | Mapping, resolvers, order + sub creation, `processPhase1` | ⏳ Wave 2 |
| Phase 5: Cheque Bulk Process | Payment match, sub activation, reports | ⏳ Wave 2 |
| Phase 6: OBA Extension | Bar ID, tier logic, validation, field sync (child theme) | ⏳ Wave 1 (next) |
| Phase 7: Polish + Tests | E2E both flows, robustness, docs | ⏳ After 4-6 |

Critical path (single owner, serial): Phase 0 → 1 → 3 → 2 → **6 → 7** (OBA Onboarding ships first), then **4 → 5** (Cheque, Wave 2).

---

## Phase 6: OBA Extension (in the OBA child theme)

**Goal:** Build the OBA-specific extension as the proving ground for the core importer. Hooks all `wicket_import_*` filters/actions from the OBA child theme.

**Depends on:** Phase 0-3 (core must be functional).

**Phase 6 total: 31 points (~6 working days)**

### 29. Bar ID sourcing (MDP-provided)

**Note:** Bar ID (OBA barcode) is assigned by the MDP. The original local atomic-sequence design (`oba_bar_id_sequence` table / WP-option mutex) is **superseded and removed from scope**. The extension reads the value back from MDP; it does not mint one.

| # | Task | Effort |
|---|------|--------|
| 29.1 | `BarIdReader.php`: hooks `wicket_import_post_membership_create` (receives `$membership_id, $person, $row, $staging_id`) to fetch the Bar ID from the MDP person/membership record once the MDP exposes it. Writes into `extension_metadata` via `ImportStagingTable::updateExtensionMetadata()`. Until MDP ships the field, no-op placeholder returning null | **2** |
| 29.2 | Uniqueness verification is the MDP's responsibility. Extension should still defensively check: if a Bar ID is returned and already present on another MDP person, flag the row for review rather than overwriting | **1** |
| 29.3 | "Next Bar ID" upload-screen display: **removed**. No local sequence to preview. The `wicket_import_upload_page_meta` slot remains available but OBA no longer populates it with a Bar ID | **0** |

### 30. Membership tier logic

| # | Task | Effort |
|---|------|--------|
| 30.1 | `ObaTierResolver.php`: hook `wicket_import_resolve_membership_tier`. `Type = "S"` → Special Temporary tier. `Type in {M, E, U}`: `$years = floor((today - earliest_admit_date) / 365.25)` → map to tier (Active Year 0, 1, 2, 3, Active) per OBA config | **3** |
| 30.2 | Return `WP_Error` if `earliest_admit_date` missing for M/E/U types | **1** |
| 30.3 | `admit_date` in future → status = Delayed. Otherwise → status = Active (via `wicket_import_membership_status` filter) | **1** |

### 31. OBA validation rules

| # | Task | Effort |
|---|------|--------|
| 31.1 | Hook `wicket_import_validators`. Register Gender (M/F enum, case-insensitive, required), Type (M/E/U/S enum, required), Degree Code (in list of 200+ codes from `ObaDegreeCodeList`, required), Admit Date (date YMD required) | **3** |
| 31.2 | Bar ID dedup: if Bar ID present in row, query MDP Service Identities, flag `duplicate` if found | **2** |

### 32. Full data sync field mapping

| # | Task | Effort |
|---|------|--------|
| 32.1 | Hook `wicket_import_post_membership_create`. PATCH all OBA fields to MDP via `wicket_import_person_data` filter: **Bar ID is MDP-provided (see §29)**, so this step no longer writes a Bar ID to MDP, profile (last/first/middle/suffix/birthdate/gender), address, contact (phone/fax/email), admit type, law school (degree code + date), other state licenses (repeater). The extension may still read the Bar ID back from MDP here and store it in `extension_metadata` (via the 4th `$staging_id` arg) | **4** |
| 32.2 | `other_states` repeater handling: each state + admit_date row → one Service Identity entry. Create or update, do not delete existing entries not in import | **2** |
| 32.3 | Status value = "Good Standing" set on Person Profile | **1** |

### 33. OBA UI extensions

| # | Task | Effort |
|---|------|--------|
| 33.1 | Hook `wicket_import_individual_form_fields`. Add: Middle Name, Suffix, Birthdate, Gender (select M/F), Fax, Law School Code (select from `ObaDegreeCodeList`), Law School Grad Date, Type (select M/E/U/S), Admit Date, dynamic Other State repeater (JS add/remove rows). **Email is OPTIONAL for individual form (different from bulk, which requires it).** Registers context-aware column definitions via `wicket_import_csv_columns` with different required-set for `context='individual'` vs `context='bulk'` | **3** |
| 33.2 | Hook `wicket_import_confirmation_columns`. Add: Bar ID (value read back from MDP, populated in `extension_metadata`), Membership Tier (resolved tier name), View in MDP (link to OBA admin URL) | **1** |

### 34. OBA 4-tier email conflict check (CRITICAL)

Without this, OBA dedup is broken.

| # | Task | Effort |
|---|------|--------|
| 34 | Hook `wicket_import_check_conflict`. For each row with `mdp_uuid` populated: (a) query MDP active memberships for UUID, (b) query MDP service identities for Bar ID. Return `['skip' => true, 'message' => '<tier-specific reason>']` based on combination: (1) email+active+Bar ID → "Email already assigned with active membership and Bar ID. Please review." (2) email+active+no Bar ID → "Email already assigned in the system with active membership. Please review." (3) email+no active+Bar ID → "Email already assigned in the system with Bar ID. Please review." (4) email+no active+no Bar ID+name mismatch → "Email already assigned in the system with no name match. Please review." (5) email+no active+no Bar ID+name match → Scenario B proceed (return `['skip' => false]`). **The `$result` parameter received by the filter contains: `['mdp_uuid' => ?string, 'row' => array, 'conflict' => bool]`.** | **3** |

**Reference:** the OBA extension requirements (tier logic, Bar ID, field sync) are the canonical hook-implementation example. See workspace `docs/importer-oba-reqs-tasks.md`.

**Verify:** Full OBA sample CSV import. All 7 rows: Bar IDs generated sequentially, tiers assigned correctly, MDP profiles populated, other states synced, confirmation screen shows Bar ID + Tier + View in MDP link.

---

## Phase 4: Cheque Renewal - Bulk Create Orders

**Goal:** Build the cheque-specific code: mapping settings, product resolution, order + subscription creation, `BatchProcessor` Phase 1 with multi-phase state machine.

**Depends on:** Phase 0, 1, 3 (core importer + MemberLookup + ImportAdapter). Phase 2 (Admin UI) can run in parallel.

**Phase 4 total: 44 points (~9 working days)**

### 15. Mapping Settings (HyperFields)

| # | Task | Effort |
|---|------|--------|
| 15.0 | Register cheque CSV columns via `wicket_import_csv_columns` filter: `bar_id` (required), `order_total` (required, numeric), `check_id` (required), `member_uuid` (optional), `status` (optional, reserved). Includes validation rules per column | **1** |
| 15.1 | `MappingEntry.php` value object: `$roleSlug, $mappingType, $applicationType, $productId, $productSku, $couponCode, $label, $isActive`. `roleSlug` is stable identifier | **1** |
| 15.2 | `MappingSettings.php`: `register()` calls `HyperFields::registerOptionsPage()` with 3 repeater sections (late fees, discounts, sections) | **3** |
| 15.3 | `MappingSettingsPage.php`: admin page under Importer submenu, "Seed/Reset Defaults" button (8 late fee defaults from the original spec) | **1** |
| 15.4 | `MappingRepository.php`: CRUD on HyperFields option, stable identity by `roleSlug` (not array index), `getSnapshotForSlugs()` for immutable per-job snapshot | **3** |

### 16-19. Resolver chain

| # | Task | Effort |
|---|------|--------|
| 16 | `TierResolver.php`: code-level lookup table (`DEFAULT_TIER_MAP`), seeded on activation, read from `wicket_import_tier_map` WP option for env portability (production vs staging product IDs) | **1** |
| 17 | `SectionResolver.php`: query member's active sections from membership data, match against mapping snapshot by slug, return WC product IDs. No match = skip + warn, don't block | **3** |
| 18 | `MappingResolver.php`: load WP roles fresh from DB (not stale MemberData), filter mappings by matching role/section, separate into late_fee / discount / section buckets, apply coupon vs product logic | **3** |
| 19 | `ProductResolver.php`: orchestrate Tier + Section + Mapping resolvers, validate products exist, check `WC_Subscriptions_Product::is_subscription()` for membership/section products, calculate expected total vs CSV total, return `ResolvedProducts` | **3** |

### 19a. Shared resolver contract (bundle renewal x Lockbox)

**Status:** agreed 2026-07-14 (Esteban + Adrian). Single source of truth: workspace `docs/importer-bundle-renewal-consensus.md`.

The Phase 4 resolvers (16-19) are a **shared service**, not cheque-internal. The Memberships plugin's bundle-renewal flow calls them through three `wicket_mship_bundle_*` filters it fires. This Importer subscribes to two of them. No duplication of tier / promo / late-fee logic across the two flows.

Architecture is **PULL**: the bundle renewal order is owned by the Memberships plugin; this Importer only answers per-member queries. The cheque divergence check (order total vs bank-file total) does not apply to bundles, so PULL is clean.

| # | Filter (working name) | Fires in (Memberships plugin) | Lockbox action | Contract |
|---|---|---|---|---|
| 1 | `wicket_mship_bundle_line_item_extra_meta` | `add_subscription_line_item()` (member-add, not renewal) | **does not subscribe** (out of scope) | returns `meta_key => meta_value` array |
| 2 | `wicket_mship_bundle_renewal_member_tier_product` | `process_bundle_renewal_members()` (once per member, every renewal) | **TierResolver answers** | full override; returns `['tier_post_id', 'product_id']` or null to let core proceed. Bypasses core `renewal_type` / `next_tier_id` when non-null |
| 3 | `wicket_mship_bundle_renewal_line_item_price` | action on WCS `wcs_renewal_order_created` (once per line item) | **MappingResolver answers** (promo + late-fee) | single-channel: callback mutates the passed `$renewal_order` (add promo / late-fee lines), returns nothing load-bearing. **Memberships plugin owns the recalc** afterward |

**Late-fee tally** ("tally # of late fee roles, match qty of late fee product") is owned by the Filter 3 implementer (MappingResolver here), reading the shared late-fee mapping table. The filter contract does not encode it.

**Open:** OD1, where the shared discount/promo + late-fee mapping tables physically live. Esteban's lean is this repo's HyperFields (`MappingSettings`, task 15.2 already specs the 3 repeater sections). Does not block the filter contracts. See consensus doc.

**Naming:** all three filter names are working names pending a naming-convention pass on the Memberships side. Update the consensus doc when that lands.

### 20. Order Creator (cheque, On Hold)

| # | Task | Effort |
|---|------|--------|
| 20 | `OrderCreator.php`: `create(MemberData, ResolvedProducts, string $batchId): OrderResult`. `wc_create_order()` with customer ID, payment method = `cheque`, status = `on-hold`, line items with `_membership_post_id_renew` meta, apply discount coupons from `ResolvedMappings::$discountCoupons`, calculate totals, add order meta (`_importer_batch_id`, `_importer_bar_id`, `_importer_check_id`, `_importer_csv_total`). Returns `OrderResult` with orderId + total + status | **3** |

### 21. Subscription Creator (order-linked)

| # | Task | Effort |
|---|------|--------|
| 21 | `SubscriptionCreator.php`: `create(int $orderId, MemberData, ResolvedProducts): SubscriptionResult`. Follows `Membership_Subscription_Controller::create_subscriptions` patterns but adapted for async. Membership sub via `wcs_create_subscription()` (status pending, line item, `_membership_post_id_renew` meta, end date from config). Section sub via `wcs_create_subscription()` (section line items, same meta). No `wc_add_notice()` (AS context) — use Logger + return error result | **3** |

### 22. BatchProcessor.processPhase1 (Bulk Create, multi-phase state machine)

| # | Task | Effort |
|---|------|--------|
| 22.0 | `Cheque/Rest/ProcessController.php`: register `POST /wicket/v1/import/batch/{id}/run-phase1` endpoint. Creates batch record in `wicket_import_batches`, links staged rows via `batch_id`, transitions batch `pending → phase1_running`, schedules first AS chunk. **Required in Phase 4 to test Phase 1 end-to-end.** | **2** |
| 22.1 | `processPhase1($batchId, $offset, $chunkSize)`: per-row — collision check (D3, On Hold orders for Bar ID), resolve user, resolve products, create order, write `order_id` to DB (crash recovery point), create subscriptions, write sub IDs, set `phase1_complete`. Action Scheduler reschedule on remaining rows | **8** |
| 22.2 | Concurrency guard: check batch status == `phase1_running` before processing. Use `as_schedule_single_action` (not `as_enqueue_async_action`) | **1** |
| 22.3 | Reschedule cap: track `$attempt`, abort after `ceil($totalRows / $chunkSize) + 5` | **1** |
| 22.4 | Terminal condition: `updatePhaseCounts()`, `transitionStatus('pending_review')`, set `phase1_completed_at`, detect role conflicts → `conflicting_roles` JSON | **2** |

### 23. Review UI (cheque-specific, Phase 1 results)

| # | Task | Effort |
|---|------|--------|
| 23 | `ReviewPage.php`: render Phase 1 results, batch summary (total/processed/failed/needs_review), ErrorLogTable for failed rows, per-row detail (Bar ID, reason, suggested fix), "Proceed to Phase 2" button (only when `pending_review`), CSV export of errors, total divergence warning (CSV total vs WC total) | **5** |

**Verify:** Upload cheque-renewal sample CSV (e.g. 10 rows with Bar IDs, Order Totals, Check #s, membership tier, section info). Phase 1 processes all. 10 orders created (On Hold, cheque), 10 sets of subscriptions created (Pending, linked to orders). Batch transitions to `pending_review`. Review page shows correct counts, errors, totals. Collision check fires if duplicate Bar ID.

---

## Phase 5: Cheque Renewal - Bulk Process Orders

**Goal:** Match payment CSV to On Hold orders, move matched orders to Processing, activate subscriptions, generate final report.

**Depends on:** Phase 4 (Bulk Create Orders) ships testable rows.

**Phase 5 total: 28 points (~5.5 working days)**

### 24. BatchProcessor.processPhase2 (Bulk Process)

| # | Task | Effort |
|---|------|--------|
| 24.1 | `processPhase2($batchId, $offset, $chunkSize)` shell: concurrency guard (check `phase2_running`), load only `phase1_complete` rows | **1** |
| 24.2 | Per-row Phase 2 flow: load order by `order_id`, verify On Hold (else `needs_review`), update to Processing, add internal note `Payment received – Cheque #[check_id]`, **explicitly activate subscriptions** (load via `wcs_get_subscriptions_for_order($order, ['order_type' => 'parent'])`, call `$sub->update_status('active', $note)` on each), MDP membership sync via `woocommerce_order_status_processing` → `Membership_Controller::catch_order_completed` | **5** |
| 24.3 | Subscription activation rationale: use per-subscription meta flag `_importer_skip_auto_payment` set before `$sub->update_status('active')`. A custom filter on `woocommerce_subscription_payment_complete` checks this meta and returns false to skip double-activation. Remove meta after activation. **This avoids the concurrency risk of removing a global WCS hook across parallel AS jobs.** Audit trail: every `$sub->update_status` call includes descriptive `$note` (e.g. `'Importer: Phase 2 bulk activation for batch {batch_id}'`) | **2** |
| 24.4 | Action Scheduler integration: chunking, reschedule on remaining rows, `as_schedule_single_action` (not enqueue) | **1** |
| 24.5 | Terminal condition: `updatePhaseCounts()`, `transitionStatus('completed')`, set `phase2_completed_at` + `finished_at`, `generateReport()` (CSV to `wp-content/uploads/importer/` with `.htaccess` deny) | **1** |

### 25. Phase 2 endpoints

| # | Task | Effort |
|---|------|--------|
| 25.1 | `Cheque/Rest/ProcessController.php` (extends Phase 4's controller): add `POST /wicket/v1/import/batch/{id}/run-phase2` (transition `pending_review → phase2_running`, schedule first AS chunk), `GET /wicket/v1/import/batch/{id}/progress` (counts + status), `POST /wicket/v1/import/batch/{id}/retry` (reset failed rows, re-schedule) | **3** |

### 26. Job Detail UI (cheque-specific)

| # | Task | Effort |
|---|------|--------|
| 26.1 | `AdminPage::renderJobDetail()`: job metadata, status timeline (visual: preparing → pending → phase1 → review → phase2 → completed), phase breakdowns (succeeded/failed/needs_review with filtered links), contextual action buttons per state (Start Phase 1, Start Phase 2, View Errors, Retry, Download Report), conflicting roles table | **5** |
| 26.2 | `ErrorLogTable.php`: extends `WP_List_Table`, columns (Line #, Bar ID, Order Total, Check #, Processing Status, Failure Reason, Order ID), filter by phase (Phase 1 / Phase 2 / All) and status (failed / needs_review), bulk action for CSV export of filtered view | **3** |

### 27. Reports

| # | Task | Effort |
|---|------|--------|
| 27.1 | CSV report generation (full): all import rows with Bar ID, Check #, CSV Total, WC Total, Processing Status, Failure Reason, Order ID, Subscription IDs, Phase 1/2 times. Served via AJAX with capability check | **3** |
| 27.2 | CSV export (errors only): failed + needs_review rows. Triggered from ErrorLogTable bulk action or "Export Errors" button | **1** |

### 28. Dashboard

| # | Task | Effort |
|---|------|--------|
| 28.1 | Job list table (`WP_List_Table` on dashboard): columns (Job Name, Status badge, CSV File, Created By, Phase 1/2 counts, Actions), sort (created_at, status, name), filter by status, search by name, pagination (20/page), row actions (View / Report / Delete with confirmation) | **3** |

**Verify:** After Phase 4 creates test batch with `phase1_complete` rows, trigger Phase 2. Orders move to Processing. Subscriptions activate. Batch transitions to `completed`. Final report CSV generated. Status timeline on Job Detail shows full lifecycle.

---

## Phase 7: Polish + Integration Testing

**Goal:** Production-ready. Full lifecycle validated against real data for BOTH OBA and cheque flows.

**Depends on:** Phase 4, 5, 6.

**Phase 7 total: 24 points (~5 working days)**

### 37. Integration testing

| # | Task | Effort |
|---|------|--------|
| 37.1 | **Cheque flow E2E**: 20+ rows, new + existing members, role conflicts, duplicate Bar IDs, total divergence. Full lifecycle: upload → Phase 1 → review → Phase 2 → report. Verify order states, subscription states, membership CPTs, payment matching | **3** |
| 37.2 | **OBA flow E2E**: 7-row sample CSV + extensions. Verify MDP profiles populated, Bar IDs sequential (11111 → 11117), tiers correct per Type + Admit Date logic, other states synced, subscriptions created, confirmation table correct | **3** |
| 37.3 | Edge case tests: empty CSV (0 rows), all invalid rows, single row, large CSV (1000+ rows for cheque — test Action Scheduler; 200+ for OBA — test inline cap), concurrent uploads (test `hasActiveSession()` enforcement), retry paths (Phase 1 fail → retry, Phase 2 fail → retry) | **3** |
| 37.4 | Individual form tests (OBA): required field validation, dynamic state rows, submission flows through same validation + processing pipeline as CSV | **1** |

### 38. Robustness

| # | Task | Effort |
|---|------|--------|
| 38.1 | CSV header normalization: handle all common variations (spaces, caps, underscores, hyphens, BOM, semicolon vs comma delimiter) | **2** |
| 38.2 | Large file handling: monitor memory within inline processing (OBA) and AS chunks (cheque), test approaching 5MB limit | **2** |
| 38.3 | Session TTL: auto-expire sessions older than 24h via WP cron | **1** |
| 38.4 | Error UX: friendly error messages for all REST failure modes (4xx, 5xx), per-row error states on Job Detail | **1** |

### 39. Documentation

| # | Task | Effort |
|---|------|--------|
| 39.1 | Plugin `readme.txt` (WP plugin repo format): description, installation, configuration, FAQ, changelog | **2** |
| 39.2 | Inline code documentation: all public methods have PHPDoc with `@param`, `@return`, `@throws` | **2** |
| 39.3 | Extension developer guide: short doc covering all 19 `wicket_import_*` hooks with examples (the OBA extension is the reference implementation) | **2** |

---

## Deferred Scope (Post-v1)

Out of scope for v1. Designed and built post-validation.

### Persistent import history (v2)

`wp_wicket_import_history` table for "show me imports from last month" admin UX. Defer until at least one client asks for it.

### Other client extensions (post-v1)

The core importer is generic. Once v1 ships, additional client extensions (other than OBA and cheque) can be built by following the OBA extension as a reference. Each is a separate workstream.

### Advanced cheque features (post-v1)

The v1 cheque flow covers the happy path. Not in v1:
- Multi-payment-method support (currently cheque only)
- Payment partial matching (currently requires exact Bar ID + total match)
- Refund / cancel / re-issue flows
- Cheque bounce handling
- Direct bank reconciliation file parsing (out of scope per D2.1)

---

## Resolved decisions (reference)

- **Action Scheduler for cheque** — AS is needed from day one (cheque batches are 1000+ rows). OBA remains inline (v1.5). `as_schedule_single_action` calls go in Phase 4 (task 22.2).
- **Cheque CSV columns** — Confirmed: `bar_id` (required), `order_total` (required), `check_id` (required), `member_uuid` (optional), `status` (optional, reserved). Registered via `wicket_import_csv_columns` in Phase 4 task 15.0.
- **Late fee timing** — Late fees apply at Phase 1 / order creation time (via `wicket_import_apply_late_fee` filter in `OrderCreator`). No Phase 2 re-evaluation. The line item is on the order.
- **OBA "Scenario B + no Bar ID"** — When existing person has no Bar ID, always proceed with update.
- **Partial name match** — Email matches but name differs → flag and halt via `wicket_import_check_conflict` returning `['skip' => true, 'message' => '...']`.

## Phase transition gates

- **Phase 6 → Phase 7 (OBA extension → polish):** OBA extension functional E2E in child theme; cheque flow functional E2E (Phase 4 + 5); both tested against sample data; E2E tests added; docs written; v1 ready to ship.
- **Phase 4 → Phase 5 (cheque create → cheque process):** Cheque flow functional end-to-end through the review gate (CSV upload → validation → resolve products → create On Hold orders + Pending subscriptions); `wicket_import_create_order` and `wicket_import_apply_late_fee` hooks registered and firing; Job Detail page renders Phase 1 results, error table, 'Proceed to Phase 2' button; test fixtures written for Phase 2 scenarios.
