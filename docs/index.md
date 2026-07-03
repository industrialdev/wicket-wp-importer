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

## Guides (End Users)
- [Upload a Member CSV](guides/upload-a-csv.md) — Drag-and-drop CSV upload, limits, error troubleshooting
- [Review Flagged Rows](guides/review-flagged-rows.md) — Reading the Validation screen, Proceed vs Restart, common reasons
- [Add a Single Member Manually](guides/add-a-single-member.md) — Using the Individual entry form

---

## Scope note

This `docs/` folder is the **reference for what has shipped** in this plugin. It is kept in sync with the code.

The full **design plan** (architectural decisions AD1-AD15, phase breakdown, the not-yet-built Cheque flow, the OBA extension spec) lives at the workspace level, outside this repo:

- `docs/importer-plan-architecture.md` — canonical architecture (AD catalog, file structure, DB schema, hook signatures)
- `docs/importer-plan-workstreams.md` — Phases 0-7 task breakdown with effort estimates
- `docs/importer-plan-delivery-waves.md` — Wave sequencing (Wave 1 = OBA Onboarding, Wave 2 = Cheque)
- `docs/importer-plan-asana-tasks.md` — granular task tracker with per-subtask audit notes
- `docs/importer-flow-diagram.md` — system flow diagrams (Mermaid)
- `docs/importer-oba-reqs.md` — OBA requirements (tier logic, Bar ID, field sync, conflict tiers)

When this reference and the plan disagree, **the code is the source of truth** and the plan docs should be updated.
