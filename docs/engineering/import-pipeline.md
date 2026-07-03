---
title: "Import Pipeline"
audience: [developer, agent]
php_class: WicketImporter\BulkImport\ImportPipeline
source_files: ["src/BulkImport/ImportPipeline.php", "src/BulkImport/PersonResolver.php", "src/BulkImport/ImportAdapter.php"]
---

# Import Pipeline

## Scope: OBA flow only

`ImportPipeline` is the **OBA inline processor**. The Cheque flow uses `Cheque\BatchProcessor` (Phase 4, not yet built) and does **not** go through this class (AD6). Different REST endpoints, different state machines, different concurrency models.

## Three phases

```
runValidation($sessionId)    → ValidationSummary
runConflictCheck($sessionId) → array (tally)   // read-only MDP pre-pass
runImport($sessionId, $skipFlagged = true) → array{summary, duration_sec} | WP_Error
```

The admin UI exposes two transitions (validate → import); the conflict check runs as part of the import step (`POST /session/{id}/run` calls `runConflictCheck` then `runImport`).

### `runValidation()` — defensive re-pass

Reconstructs `CsvRow`s from staged `raw_data`, re-runs `ValidationService` + the `wicket_import_csv_columns` registry, persists fresh `validation_status` / `validation_message` / `flagged_fields`. **Skips rows at terminal `import_status`** (imported/updated/skipped/failed/email_conflict) so re-running cannot desync the UI. Not called by `/run`; triggered explicitly when an extension's validators may have changed between upload and run.

### `runConflictCheck()` — read-only MDP pre-pass

Uses `PersonResolver::checkConflict()` (no create/merge). Per row:

- exact match → write `mdp_uuid`
- partial match → `import_status = email_conflict`
- no match → untouched (`runImport` creates later)
- API error → tallied under an `error` key so failures are distinguishable from genuine no-matches

Fires `wicket_import_check_conflict` (AD12) per row with the core verdict as the starting point; the extension can override. Defensive `is_array()` guard resets a bad return to 'none'. The active-membership guard (AD12) intentionally runs in `resolve()` at import time, not here — membership state can change between check and import.

### `runImport()` — destructive per-row loop

Entry guards:
- `set_time_limit(0)` + `ignore_user_abort(true)`
- Row-count cap: `countImportableInSession() > WICKET_IMPORT_INLINE_MAX_ROWS` → `WP_Error` (HTTP 413)
- Empty-tally fast path returns immediately

**Concurrency:** claims rows atomically (`import_status='pending' → 'processing'` via `claimImportableInSession`) before processing. Re-entry on a running session returns HTTP 409.

Per-row flow:

1. `extractPerson($row)` via `wicket_import_extract_person` filter (default `guessPerson()`). `null` → row `failed`.
2. `PersonResolver->resolve($person, $row, $stagingId)`.
3. Map `PersonResolutionResult` onto staging (`updatePersonUuid` + status).
4. On RESOLVED:
   - fire `wicket_import_post_person_resolved`
   - resolve tier via `wicket_import_resolve_membership_tier` (WP_Error → `needs_review`)
   - build `MemberData`, call `ImportAdapter->create()`
5. Per-row `try { ... } catch (\Throwable)` so one bad row never halts the batch.

### Status mapping (import_status)

| Trigger | Status written |
|---|---|
| RESOLVED + created, pre-existing `mdp_uuid` (Scenario B merge) | `updated` |
| RESOLVED + created, no pre-existing `mdp_uuid` (Scenario A create) | `imported` |
| RESOLVED + skipped (pre-membership veto) | `skipped` |
| RESOLVED + tier WP_Error / adapter error / exception **after** person touched | `needs_review` |
| RESOLVED + exception **before** person touched | `failed` |
| Resolver returns email-conflict | `email_conflict` |
| Resolver returns skipped-active-membership | `skipped_active_membership` |
| Resolver STATUS_FAILED | `failed` |
| `extractPerson` returns null | `failed` |

**`needs_review` is the post-RESOLVED failure status.** The MDP person was created/merged but the membership could not be created, leaving an orphan person + stale WP relationship. An admin must address it manually; the row is excluded from re-runs.

## PersonResolver decision tree

`PersonResolver->resolve($person, $row, $stagingId)` returns a `PersonResolutionResult`:

- **no match** → Scenario A create (`POST /people` via `WicketMdpClient`)
- **match + name mismatch** → `email_conflict`
- **match + active membership** → `skipped_active_membership`
- **match + no active** → Scenario B merge (non-destructive `PATCH`)

Name match is case-insensitive + whitespace-folded; accents are **not** folded (transliteration mismatch surfaces for review). Empty-fold guard: both sides empty → treated as partial match.

## ImportAdapter

Creates the `wicket_membership` CPT only. Per AD10, it fires hooks; it does **not** call WC Subscriptions directly.

Flow inside `create(MemberData $data): MembershipResult`:

1. `wicket_import_pre_membership_create` gate → `true` / `string` / `WP_Error` / `false`. Veto → `MembershipResult::skipped`; suppresses `wicket_import_create_subscription`.
2. Resolve WP user (`wicket_create_wp_user_if_not_exist`).
3. `wicket_import_membership_start_date` + `wicket_import_membership_status` filters.
4. MDP membership assignment via `Membership_Controller::create_mdp_record()` (AD15 rung 1; dedup-aware).
5. CPT via `Membership_Controller::create_local_membership_record()` (AD15 rung 1).
6. Fire `wicket_import_create_subscription`.
7. Fire `wicket_import_post_membership_create` (4-arg; the 4th lets extensions write `extension_metadata` for the exact staged row).

Dates flow from `Membership_Config::get_membership_dates()` (source of truth: anniversary/seasonal/align-end-dates + grace + early-renew in one call).

## WicketMdpClient (AD15)

Uses base plugin's SDK client object `wicket_api_client()` (rung 2) for create/update — **not** the opinionated `wicket_create_person` / `wicket_update_person` helpers. Why:

- `wicket_create_person` has a fixed signature with no AD11 hook point.
- `wicket_update_person` is a destructive fetch-then-overwrite (coerces blanks to NULL, merges over existing), which violates the non-destructive merge contract.

`updatePerson` runs `stripEmpty()` on the attributes map before PATCH, dropping nulls and whitespace-only strings so existing MDP data is never overwritten with nothing. Both `createPerson` and `updatePerson` fire `wicket_import_person_data` (AD11) with `($payload, $row, 'create'|'update')`.

Lookup delegates to rung-2 helper `wicket_get_person_by_email` + secondary-email filter fallback. `hasActiveMembership` queries the client directly (`people/{uuid}/membership_entries?filter[active_at]=now`), not the base helper, because the base helper uses an unkeyed `static` cache that poisons batches.

## See also

- [Hooks](hooks.md) — the lifecycle filters/actions fired by this pipeline
- [REST endpoints](rest-endpoints.md) — the `/run` endpoint that triggers this
- Workspace `docs/importer-plan-workstreams.md` Phase 3 Task 12 audit notes for the post-RESOLVED `needs_review` semantic
