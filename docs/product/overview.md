---
title: "Importer Overview"
audience: [implementer, support]
wp_admin_path: "Wicket → Import"
---

# Importer Overview

## What this plugin does

Wicket Importer is a **bulk member import pipeline**. An operator uploads a CSV (or fills a manual form), the importer validates and stages the rows, then creates/updates MDP persons and their memberships in one pass. A confirmation screen reports what happened per row.

The core plugin is generic. Client-specific rules (Bar ID logic, tier resolution, field mapping, conflict checks) live in a **client extension** loaded by the site's theme. At present the only consumer is the OBA extension (in the OBA child theme).

## Where to find it

WP admin → **Wicket → Import**. The screen has three views, switched automatically as you progress:

1. **Upload** — choose CSV file upload or Individual (manual form). Shows any extension-provided info bar (e.g. "Next Bar ID" when the extension supplies one).
2. **Validation** — summary bar (`N valid · M flagged · K duplicates`), a table of flagged rows with the reason per field, and two actions: **Proceed with Valid Rows** or **Restart Upload**. You can also download the flagged rows as CSV.
3. **Confirmation** — summary of processed/succeeded/failed/needs-review, a per-row result table (name, email, MDP UUID, status, plus extension columns such as Bar ID / tier / View-in-MDP link), and a full-results CSV download.

## What you need before you start

- A CSV in the format the client extension expects. **Use the "Download CSV template" button on the Upload screen** to get a header row with the exact expected columns — it is generated from the extension's registered columns.
- A client extension installed and active. Without one, the template download returns an error ("enable an importer extension") and no columns are recognized.
- The Wicket stack active: `wicket-wp-base-plugin`, `woocommerce`, `woocommerce-subscriptions`, `wicket-wp-memberships`. The plugin refuses to activate if any are missing.

## Limits and gotchas

| Item | Value / behavior |
|---|---|
| Max upload size | 4 MB default (filterable by the extension) |
| Max rows per import | 200 (the OBA inline path). Larger files return HTTP 413 and are rejected at run time. |
| Concurrent imports | One active session at a time. A second upload is rejected with 409 until the current session completes or is cleared. |
| CSV delimiter | Comma by default; an extension can override (OBA uses semicolon). |
| BOM / encoding | UTF-8 BOM, UTF-16LE, UTF-16BE are handled. |
| Flagged rows | Flagged rows are skipped entirely on Proceed — they do not create partial records. |
| Session expiry | Sessions auto-expire after 24 hours. |

## "Needs review" status

Some rows may end with status **Needs review**. This means the MDP person was created or merged, but the membership could not be created afterward (e.g. tier resolution failed, or an error occurred mid-row). The person exists in MDP but has no membership. These rows are **not** retried automatically — an admin must address the person record in MDP manually. This is intentional, to avoid orphaned or duplicate memberships.

## What is NOT shipped yet

- **Cheque renewal flow** (bulk create On-Hold orders + process payments). Planned for a later release. Do not attempt cheque imports yet.
- **Persistent import history.** Only the current session is visible; completed sessions are cleared on the next upload.

## See also

- [Upload a CSV](../guides/upload-a-csv.md) — step-by-step
- [Review flagged rows](../guides/review-flagged-rows.md) — what the validation screen shows
- Workspace `docs/importer-oba-reqs.md` — OBA-specific requirements (tier logic, Bar ID, field sync)
