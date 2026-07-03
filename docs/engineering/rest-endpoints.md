---
title: "REST Endpoints"
audience: [developer, agent]
php_class: WicketImporter\BulkImport\Rest\UploadController
source_files: ["src/BulkImport/Rest/UploadController.php", "src/Support/SecuresRequests.php"]
---

# REST Endpoints

All routes live under namespace `wicket/v1`. All require `permission_callback` → `current_user_can('manage_options')` via `SecuresRequests::restPermissionCheck`. CSV exports use `Support\CsvExporter` (AD14 injection prevention: prefixes cells starting with `= + - @ \t \r` with a tab).

All controllers are consolidated in `BulkImport\Rest\UploadController`. Cheque endpoints (Phase 4) will live in `Cheque\Rest\ProcessController`.

## Upload / Validation (Phase 1)

| Method | Route | Purpose | Errors |
|---|---|---|---|
| `POST` | `/wicket/v1/import/upload` | Receive CSV, parse, validate, stage rows. Returns `{session_id, total_rows, valid_count, flagged_count, duplicate_count}`. | 400 (validation), 413 (size), 415 (type), 409 (active session) |
| `GET` | `/wicket/v1/import/session/{id}` | Session summary (counts by validation_status) | 404 |
| `GET` | `/wicket/v1/import/session/{id}/flagged` | Flagged rows with reasons | |
| `GET` | `/wicket/v1/import/session/{id}/flagged-csv` | Download flagged subset as CSV (AD14) | |
| `GET` | `/wicket/v1/import/session/{id}/results` | All rows with `import_status`, `import_message`, `mdp_uuid`, `order_id`, `subscription_ids`, `extension_metadata` (JSON decoded). Used by the confirmation screen. | |
| `GET` | `/wicket/v1/import/session/{id}/results-csv` | Full results CSV (AD14) | |
| `DELETE` | `/wicket/v1/import/session/{id}` | Clear the session | |
| `GET` | `/wicket/v1/import/template` | Download CSV template (extension-registered columns). `wp_die(400)` when no columns registered. | 400 |

## Import (Phase 3)

| Method | Route | Purpose | Errors |
|---|---|---|---|
| `POST` | `/wicket/v1/import/session/{id}/run` | Run `runConflictCheck()` then `runImport()`. Returns `{session_id, summary, conflict_tally, duration_sec}`. | 404 (no session), 413 (`import_too_many_rows` — over `WICKET_IMPORT_INLINE_MAX_ROWS`), 409 (`import_session_active` — re-entry while running), 500 |
| `POST` | `/wicket/v1/import/individual` | Validate + stage + import a single row from the manual entry form. On failure → 400 with `{field_errors}`. Returns `{session_id}` on success. | 400 (validation, per-field errors) |

## Cheque (Phase 4-5 — not yet built)

These are the planned routes. They live in `Cheque\Rest\ProcessController` when built.

| Method | Route | Purpose | Phase |
|---|---|---|---|
| `POST` | `/wicket/v1/import/batch/{id}/run-phase1` | Create batch, link staged rows, schedule first AS chunk | 4 |
| `POST` | `/wicket/v1/import/batch/{id}/run-phase2` | Trigger Phase 2 | 5 |
| `GET` | `/wicket/v1/import/batch/{id}/progress` | Phase 1/2 progress | 5 |
| `POST` | `/wicket/v1/import/batch/{id}/retry` | Reset failed rows, re-schedule | 5 |
| `GET` | `/wicket/v1/import/batch/{id}/report` | Download full report CSV | 5 |
| `GET` | `/wicket/v1/import/batch/{id}/errors-csv` | Download error rows CSV | 5 |

## Concurrency

- **Active session:** the upload endpoint rejects if any row with `import_status='pending'` exists for any session (409 `import_session_active`).
- **Re-entrant `/run`:** `handleRun()` checks for in-flight `processing` rows and returns 409 on re-entry. The atomic claim (`pending → processing`) is the durable guard; the 409 is the fast-fail layer.

## Auth notes for downloads

CSV download links (`/template`, `/flagged-csv`, `/results-csv`) are rendered as plain `<a href>` and **must be wrapped in `wp_nonce_url($url, 'wp_rest', '_wpnonce')`**. An `<a href>` cannot attach the `X-WP-Nonce` header, so without the nonce WP REST treats the cookie-authenticated request as anonymous and returns 401 `rest_forbidden`.

`fetch` calls from `admin.js` always send `X-WP-Nonce`; the nonce requirement applies only to browser-initiated downloads.

## See also

- [Import pipeline](import-pipeline.md) — what `/run` actually does
- [Architecture](architecture.md) — constants, service locator
