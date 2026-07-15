# Repository Guidelines

## Project Structure & Module Organization
This is a WordPress plugin rooted at `wicket-wp-importer.php`.
- `src/`: PSR-4 PHP code under `WicketImporter\\` (Admin, BulkImport, Mapping, ValueObjects, Validators, Services, Support).
- `assets/`: admin CSS (`assets/css/admin.css`) and vanilla JS (`assets/js/admin.js`). No build step; JS is hand-maintained.
- `docs/`: shipped reference docs (product / engineering / guides). `docs/index.md` is the entry point.
- No `tests/` directory yet. QA is centralized in the workspace `qa/` (wicket-warden) repo.

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies.
- `composer dump-autoload --optimize`: regenerate the autoloader after adding classes on `src/`.
- `php -l <file>`: syntax check (run on touched files before committing).
- `node --check assets/js/admin.js`: JS syntax check.
- Tests are not in this repo. Run the QA suite from the workspace: `wicket test <branch-suffix>` (see workspace `wicket-wp-stack/AGENTS.md`).

## Coding Style & Naming Conventions
- PHP 8.2+, `declare(strict_types=1);`, PSR-12.
- Use PSR-4 namespaces (`WicketImporter\\...`) and keep classes in `src/`.
- Naming: classes `PascalCase`, methods `camelCase`.
- Favor small methods, early returns, and WordPress-native APIs/hooks.

## Architectural rules (load-bearing)
- **AD1**: Core stays generic. No OBA / Bar ID / cheque / order / subscription domain knowledge in core. Client logic rides on `wicket_import_*` hooks (see `docs/engineering/hooks.md`).
- **AD10**: `ImportAdapter` fires hooks; core never calls WC Subscriptions directly.
- **AD14**: Every CSV export goes through `Support\CsvExporter` (injection prevention).
- **AD15**: MDP integration priority ladder — reuse `wicket-wp-memberships` → `wicket-wp-base-plugin` → `wicket-wp-account-centre` → direct MDP API (last resort; document WHY at the call site).

## Commit & Pull Request Guidelines
Git history favors short, imperative, scope-specific messages.
- Keep commits focused; avoid mixed refactor/feature changes.
- PRs should include: purpose, risk notes, test evidence.

## Security & WordPress-Specific Requirements
- Sanitize, validate, and escape all input/output.
- Enforce capability checks (`manage_options`) and nonces for admin actions and REST endpoints.
- All DB queries via `$wpdb->prepare()`.
- CSV download links rendered as `<a href>` must be wrapped in `wp_nonce_url($url, 'wp_rest', '_wpnonce')` (an anchor cannot send the `X-WP-Nonce` header).

## Release & Branch Workflow
All work happens on branches. `main` is locked; changes land via peer-reviewed
Pull Request (devs cross-review each other). Never commit to `main` directly, and never push or open a
PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub
App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or
create tags by hand. The bump level comes from a marker in the PR title
(squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`,
`#major`, or `#norelease` (no release; use for docs/tooling-only merges).
Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog
grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
