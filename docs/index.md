---
title: "Wicket Importer Documentation Index"
audience: [implementer, support, developer, end-user]
---

# Wicket WP Importer Documentation

## Product Docs (Operators & Support)
- [Overview](product/overview.md) — What the importer does, where to find it, limits, gotchas

## Engineering Docs (Developers & Agents)
- [Plugin Architecture](engineering/architecture.md) — Identity, file structure, constants, service locator, AD catalog summary
- [Plugin Entrypoint](engineering/plugin-entrypoint.md) — Bootstrap flow, activation hook, `plugin_setup()`
- [Hooks: Filters & Actions](engineering/hooks.md) — Full `wicket_import_*` extension surface (the only customization point)
- [Import Pipeline](engineering/import-pipeline.md) — Three-phase orchestrator, status mapping, PersonResolver, ImportAdapter
- [REST Endpoints](engineering/rest-endpoints.md) — Route table, request/response shapes, concurrency, nonce requirement
- [System Flow Diagrams](engineering/flow-diagrams.md) — Two-lane system map, hook surface, cheque batch lifecycle (Mermaid)
- [Roadmap & Remaining Scope](engineering/roadmap.md) — Phase status, Phases 4-7 task breakdown, deferred scope, transition gates
## Guides (End Users)
- [Upload a Member CSV](guides/upload-a-csv.md) — Drag-and-drop CSV upload, limits, error troubleshooting
- [Review Flagged Rows](guides/review-flagged-rows.md) — Reading the Validation screen, Proceed vs Restart, common reasons
- [Add a Single Member Manually](guides/add-a-single-member.md) — Using the Individual entry form

---

## Scope note

This `docs/` folder is the **reference for what has shipped** in this plugin. It is kept in sync with the code.

The not-yet-built **Cheque flow** (Phases 4-5) and the **OBA extension spec** (Phase 6, lives in the client child theme) are forward-looking scope:

- [engineering/roadmap.md](engineering/roadmap.md) — phase status, remaining task breakdown, deferred scope, transition gates (now in this repo)
- `docs/importer-oba-reqs-tasks.md` — OBA product requirements (tier logic, Bar ID, field sync, conflict tiers). Extension spec, not core; stays at workspace level

When this reference and the code disagree, **the code is the source of truth**.
