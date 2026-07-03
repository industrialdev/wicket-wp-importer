---
name: Documentation Agent
role: Senior Full-Stack Engineer with a master's degree in journalism, writes documentation
---

# Documentation Rules — wicket-wp-importer

Always read the code currently checked out alongside these files before writing or updating any doc.

---

## Audiences

Three distinct audiences. Every doc targets one primary audience. Know which before writing.

| Audience | Who | What they need |
|---|---|---|
| `implementer` | Implementation team (also called: operator, implementor) — configures the plugin for a client | What settings do, when to use them, defaults, gotchas |
| `support` | Support team — answers client questions, troubleshoots issues | Same as implementer; also needs troubleshooting tips and warnings |
| `developer` | Engineers and AI agents writing or reading code | Hooks, filters, class architecture, source file references |
| `end-user` | Client staff using the WP admin UI | Plain-language task guides, no technical detail |

> **Alias note for LLMs:** When a user says "implementation team", "implementer", "implementor", or "operator" — they mean the `implementer` audience. When they say "support team" or "support" — they mean the `support` audience. Both read `docs/product/` primarily.

---

## Directory Structure

```
docs/
  product/      ← implementer + support: one file per WP admin settings page/section
  engineering/  ← developer + agent: hooks, filters, architecture, source reference
  guides/       ← end-user: task-oriented how-tos in plain language
  index.md      ← entry point — list all docs by directory
  AGENTS.md     ← this file
```

### Decision rules for agents

- Does the doc explain a WP admin UI screen, setting, or configuration option? → `product/`
- Does the doc explain hooks, filters, PHP classes, source files, or non-UI developer contracts? → `engineering/`
- Does the doc walk a non-technical person through completing a task? → `guides/`
- When in doubt between `product/` and `engineering/`: if a support team member needs it to configure the plugin, it's `product/`. If a developer needs it to write code, it's `engineering/`.

---

## Frontmatter Schema

Every doc **must** have frontmatter. Fields marked ✱ are required on all docs.

```yaml
---
title: "Human-readable title"           # ✱ used in index and HTML builds
audience: [implementer, support]        # ✱ one or more of: implementer, support, developer, agent, end-user
wp_admin_path: "Wicket → Importer"     # product/ docs only — exact WP admin menu path
php_class: WicketImporter               # engineering/ and product/ — primary PHP class
db_option_prefix: wicket_import_        # product/ docs — WP option key(s) or prefix pattern
source_files: ["src/BulkImport/ImportPipeline.php"]  # engineering/ docs — relevant source files relative to plugin root
---
```

`db_option_prefix` bridges the gap between "what does this setting do" (prose) and "where is it stored" (code). Use the exact prefix that `get_option()` calls use. Check source to verify before writing.

`php_class` and `source_files` let agents and developers locate code without guessing. Always verify they exist before writing them.

---

## File Naming

- kebab-case, no spaces
- `product/`: `settings-{tab-name}.md` or `{feature}.md` — mirrors the WP admin area
- `engineering/`: descriptive slug matching the feature, e.g. `hooks.md`, `import-pipeline.md`
- `guides/`: verb-first, e.g. `upload-a-csv.md`, `review-flagged-rows.md`
- No `user-` prefix — the `guides/` directory replaces it

---

## Content Rules

**Be concise.** Every word earns its place. Short sentences. No filler.

### product/ docs

One heading per setting/screen. For each setting include:

- What it does (one sentence)
- When to use it / when not to
- Default value
- Warnings or gotchas if any

Technical metadata goes in a table at the end of each setting block — not in prose, not in inline `### Technical Note` sub-sections:

```markdown
## Max File Size

Maximum accepted CSV upload size in bytes...

| | |
|---|---|
| Option key | `WICKET_IMPORT_DEFAULT_MAX_FILE_SIZE` (constant) |
| Filter | `wicket_import_max_file_size` |
| Default | `4194304` (4 MB) |
```

This pattern keeps docs readable for support staff while giving developers and agents exact lookup values.

### engineering/ docs

Include: class and method references, hook/filter signatures with priority, source file paths, decision flow diagrams (plain text or tables), troubleshooting. No settings configuration explanations — link to the relevant `product/` doc instead.

### guides/ docs

Plain language only. No option keys, no class names, no code blocks unless showing exact UI input. Task-oriented: "How to upload a CSV", "How to review flagged rows". Written for someone who has never seen the codebase.

---

## Index Maintenance

`docs/index.md` is the entry point for all audiences. Update it whenever a doc is added, moved, or removed. Organize by directory:

```markdown
## Product Docs (Operators & Support)
- [Title](product/filename.md) — one-line description

## Engineering Docs (Developers & Agents)
- [Title](engineering/filename.md) — one-line description

## Guides (End Users)
- [Title](guides/filename.md) — one-line description
```

---

## HTML Generation

Build pipelines can target directories:

- `docs/guides/**` → client-facing HTML (public support portal)
- `docs/product/**` → internal implementer/support manual
- `docs/engineering/**` → developer reference site

Frontmatter `audience` field is the secondary filter for pipelines that need finer control.

---

## LLM and Agent Guidelines

When an agent is asked to answer a question about configuring the plugin, read `docs/product/` first. When asked about code, hooks, or implementation, read `docs/engineering/` first. When asked to write end-user documentation, write to `docs/guides/`.

Before writing any frontmatter field that references code (`php_class`, `db_option_prefix`, `source_files`):
1. Verify the class exists — grep the codebase
2. Verify the option key exists — grep `src/` for `get_option` / `update_option` calls
3. Verify the source file path is correct relative to the plugin root

Never invent option keys or class names. If uncertain, omit the field and note that it needs verification.

---

## Source of truth for Importer architecture

The shipped architecture is documented **inside this repo** under `docs/engineering/`:

- [architecture.md](engineering/architecture.md) — identity, file structure, constants, service locator, AD catalog
- [hooks.md](engineering/hooks.md) — full `wicket_import_*` extension surface (the only customization point)
- [import-pipeline.md](engineering/import-pipeline.md) — three-phase orchestrator, status mapping, ImportAdapter
- [flow-diagrams.md](engineering/flow-diagrams.md) — two-lane system map, hook surface, cheque batch lifecycle (Mermaid)
- [roadmap.md](engineering/roadmap.md) — phase status, remaining task breakdown, deferred scope

One doc stays at the **workspace level** because it is OBA extension product spec (child theme territory, not core):

- `docs/importer-oba-reqs-tasks.md` — OBA product requirements (tier logic, Bar ID, field sync, conflict tiers)

When a doc and the code disagree, **the code is the source of truth**.

---

## Clarification

If the purpose or audience of a doc is unclear, ask before writing. Do not guess and produce a doc that will mislead an LLM or a support agent.
