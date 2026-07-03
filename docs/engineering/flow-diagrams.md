---
title: "System Flow Diagrams"
audience: [developer, agent]
php_class: WicketImporter
source_files: ["src/BulkImport/ImportPipeline.php", "src/BulkImport/ImportAdapter.php"]
---

# System Flow Diagrams

Visual maps of how the importer works. Mirrors the original hand-drawn two-lane flow, enriched with the planned implementation. Text detail lives in [import pipeline](import-pipeline.md) and [hooks](hooks.md); this page is the at-a-glance visual companion.

## Legend

| Shape / line | Meaning |
|---|---|
| `[box]` | component or step |
| `{diamond}` | decision |
| `[(cylinder)]` | data store (DB table or external API) |
| dotted `-.->` | a shared engine, or a `wicket_import_*` hook firing into the extension layer |
| solid `-->` | data or control flow |

---

## 1. Two-lane system map

The importer is the SAME tool across two processes; it is EXTENDED to follow client-specific details via hooks (AD1). One generic core, two flows, client logic in extensions.

Lane 1 (Renewals / LockBox) is Wave 2; Lane 2 (Onboarding) is Wave 1.

```mermaid
flowchart TD
    THESIS["THESIS: the Importer tool is the SAME across processes;<br/>it is EXTENDED to follow OBA-specific details via hooks (AD1).<br/>One generic core, two flows, client logic in extensions."]

    subgraph LANE1["LANE 1: RENEWALS (Cheque, 'LockBox') - Wave 2"]
        direction TB
        BANK["INPUT: OBA's bank file<br/>cheque #, total, etc."]
        subgraph LOCKBOX["LockBox = Cheque Renewal flow (Phase 4 + 5)"]
            direction TB
            LVAL["Importer File Validation (Bar ID)<br/>shared engine + OBA Bar-ID dedup"]
            LCREATE["Bulk Order Creation<br/>BatchProcessor.processPhase1 - Action Scheduler, 50/chunk<br/>-> On Hold orders + Pending subscriptions"]
            LPROCESS["Bulk Order Processing (logging)<br/>BatchProcessor.processPhase2<br/>orders -> Processing, activate subs, report CSV"]
            LVAL --> LCREATE --> LPROCESS
        end
        TIER["Logic to determine renewal tier<br/>TierResolver reads wicket_import_tier_map (WP option)<br/>RESOLVED 'MDP or Plugin?' -> PLUGIN, env-portable<br/>(tier is also a WP user role*)"]
        TOTAL["Total $ = Tier + Section + Late Fees + Discounts (by role)<br/>ProductResolver + MappingResolver (HyperFields settings)<br/>Order total MUST match file total -> divergence check"]
        BANK --> LVAL
        LCREATE --> TIER
        LCREATE --> TOTAL
    end

    subgraph LANE2["LANE 2: FIRST MEMBERSHIP CREATION / ONBOARDING (OBA) - Wave 1"]
        direction TB
        OBAFILE["INPUT: OBA-specific logic (building import file)<br/>date logic (admit dates), same shape as LockBox<br/>ObaTierResolver: type + earliest admit date -> tier"]
        subgraph IMPORTER["Productized Importer Tool = OBA Onboarding flow (Phase 3 + 6)"]
            direction TB
            OVAL["Importer File Validation (AORM)<br/>shared engine + OBA validators<br/>(gender, type, degree code, admit date)"]
            OCREATE["User Creation + Membership Creation<br/>ImportPipeline.runImport (INLINE, 200-row cap)<br/>+ ImportAdapter -> wicket_membership CPT"]
            OVAL --> OCREATE
        end
        PROFILE["Profile (AORM) + Additional Info Updates (MDP)<br/>wicket_import_person_data (status = Good Standing)<br/>post_membership_create (Bar ID, tier, View-in-MDP URL)"]
        OBAFILE --> OVAL
        OCREATE --> PROFILE
    end

    CORE["SHARED ENGINE (identical in both lanes)<br/>FileParserService + ValidationService + staging table<br/>+ 19 wicket_import_* hooks (the only extension surface)"]
    LVAL -.->|"same engine"| CORE
    OVAL -.->|"same engine"| CORE
    THESIS -.-> CORE

    subgraph EXT["External systems & extensions"]
        direction LR
        MDP[("MDP API<br/>persons, service identities (Bar ID)")]
        WC[("WooCommerce<br/>orders, products")]
        WCS[("WC Subscriptions")]
        OBA_EXT["OBA Extension (child theme)<br/>implements all OBA-specific hooks"]
    end

    OCREATE <-->|"person create / update"| MDP
    PROFILE -->|"field sync"| MDP
    LCREATE --> WC
    LCREATE --> WCS
    LPROCESS --> WCS
    OVAL -.-> OBA_EXT
    OCREATE -.-> OBA_EXT
```

\* Tier is resolved via `TierResolver` + the `wicket_import_tier_map` option. Discounts/fees are role-based; `MappingResolver` loads roles fresh from DB (not stale member data).

### Hand-drawn original to implementation mapping

| Original element | Lane | Planned implementation |
|---|---|---|
| OBA's bank file (cheque #, total) | Renewals | Cheque CSV input, Phase 4 task 15.0 |
| LockBox: Importer File Validation (Bar ID) | Renewals | FileParserService + ValidationService + OBA Bar-ID dedup |
| LockBox: Bulk Order Creation | Renewals | `Cheque\BatchProcessor::processPhase1` (Action Scheduler) |
| LockBox: Bulk Order Processing (logging) | Renewals | `processPhase2` + `Reports\ReportGenerator` |
| Logic to determine renewal tier + "(MDP or Plugin?)" | Renewals | `TierResolver` on WP option. Resolved: plugin-side, env-portable |
| Total $ = Tier + Section + Late Fees + Discounts | Renewals | `ProductResolver` + `MappingResolver` (HyperFields) |
| Order total must match file total | Renewals | total divergence check on ReviewPage |
| OBA import file logic (date logic) | Onboarding | `ObaTierResolver` admit-date mapping |
| Productized Importer Tool: Validation (AORM) | Onboarding | FileParserService + ValidationService + OBA validators |
| Productized Importer Tool: User + Membership Creation | Onboarding | `ImportPipeline::runImport` + `ImportAdapter` |
| Profile (AORM) + Additional Info Updates (MDP) | Onboarding | `wicket_import_person_data` + `wicket_import_post_membership_create` |
| Note: same tool, extended for OBA | both | AD1: generic core, client logic via hooks |

---

## 2. Shared engine and extension hook surface

Why it is one tool. The core has zero client domain knowledge; every customization is a `wicket_import_*` hook. This shows the hook categories and who consumes them.

```mermaid
flowchart LR
    COREHUB["Core pipeline<br/>(generic, fires hooks)"]

    subgraph CATS["Hook categories (19 total)"]
        direction TB
        H1["Columns & parsing<br/>csv_columns · dynamic_columns · csv_delimiter · max_file_size"]
        H2["Validation<br/>validators"]
        H3["UI<br/>upload_page_meta · individual_form_fields · confirmation_columns"]
        H4["Conflict<br/>check_conflict"]
        H5["Person & membership<br/>person_data · pre_membership_create · start_date · status ·<br/>resolve_tier · post_person_resolved · post_membership_create"]
        H6["Commerce<br/>create_order · apply_late_fee · create_subscription"]
    end

    subgraph CONS["Consumers"]
        direction TB
        OBA_C["OBA Extension<br/>(child theme, Phase 6)"]
        CHK_C["Cheque code<br/>(in core, Phase 4-5)"]
    end

    COREHUB --> CATS
    H1 --> OBA_C
    H2 --> OBA_C
    H3 --> OBA_C
    H4 --> OBA_C
    H5 --> OBA_C
    H6 -->|"no-order sub"| OBA_C
    H6 -->|"order-linked sub + order + late fee"| CHK_C
```

Full signatures for all 19 hooks: [hooks](hooks.md).

---

## 3. LockBox (Cheque) batch lifecycle

The `wicket_import_batches` record drives the two-phase cheque flow (Phase 4-5, Wave 2). Each phase is an Action Scheduler loop that reschedules itself until rows are exhausted.

```mermaid
stateDiagram-v2
    direction LR
    [*] --> pending
    pending --> phase1_running : POST run-phase1 (create batch, link rows)
    phase1_running --> pending_review : all rows phase1_complete or failed
    phase1_running --> failed : fatal error
    pending_review --> phase2_running : POST run-phase2
    phase2_running --> completed : all rows phase2_complete or failed
    phase2_running --> failed : fatal error
    failed --> pending : retry (Phase 1 path)
    failed --> pending_review : retry (Phase 2 path)
    completed --> [*]
```

Row-level `import_status` within a batch: `pending` -> `phase1_complete` (or `needs_review` / `failed`) -> `phase2_complete` (or `needs_review` / `failed`).

The OBA flow instead uses: `pending` -> `imported` / `updated` / `skipped` / `failed` / `email_conflict` / `skipped_active_membership` / `needs_review`. `needs_review` on the OBA flow means a post-RESOLVED failure (ImportAdapter error, or pipeline exception after the MDP person was created/merged) — the orphan-person / stale-WP-relationship case that an admin must address manually.

---

## Key design rules (encoded in the diagrams)

- **AD1 / AD7:** core stays generic. OBA logic lives in the child-theme extension; cheque logic lives in core but subscribes to its own hooks internally. Two flows, one core.
- **AD6:** two processors, no shared path. OBA = `ImportPipeline::runImport()` inline (200-row cap, 413 over). Cheque = `BatchProcessor` + Action Scheduler (50/chunk) from day one.
- **AD10:** `ImportAdapter` fires `wicket_import_create_subscription`; core never calls WC Subscriptions directly.
- **AD11:** `wicket_import_person_data` filter fires before every MDP create/update (avoids a double PATCH per row).
- **AD12:** `runConflictCheck` is a thin shell + `wicket_import_check_conflict` filter (the OBA 4-tier dedup).
- **AD14:** every CSV export prefixes cells starting with `= + - @ tab CR` with a tab. Injection-safe.
- **Sequencing:** Wave 1 ships the OBA path; Wave 2 adds cheque. See [roadmap](roadmap.md).
