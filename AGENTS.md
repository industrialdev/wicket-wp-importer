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
- **AD1 (capabilities vs. rules)**: The importer plugin owns the reusable, client-agnostic *capabilities* that do real work against external systems: importing/creating a user, creating a membership, creating a subscription, and creating or attaching an order (`src/BulkImport/`, incl. `Subscriptions/` and the cheque/lockbox flow under `Subscriptions/Cheque/`). These are generic methods the shell team invokes from a client (child theme); they are never reimplemented in a child theme. A child theme supplies only its own *rules, data, and config*: tier maps, code lists, validators, field→MDP-path mappings, column registration, and hook handlers that route into the importer's capabilities. A child theme MUST NOT call WCS, MDP, or order APIs directly. Test for where code belongs: if it talks to WooCommerce, MDP, or the order system, it belongs in the importer plugin; if it expresses a client decision (which tier, which fields, which conflict scenario), it belongs in the client. (Supersedes the prior "no cheque/order/subscription in core" wording — those are now core importer capabilities. Generalizes the D-LOCKBOX-1 engine/map split.)
- **AD10**: Two subscription-creation paths with distinct hook names. (a) Cheque flow: the core `WicketImporter\BulkImport\Subscriptions\SubscriptionCreator` creates subscriptions directly and fires `wicket_import_subscriptions_created` (past tense; extensions react/adjust AFTER creation). (b) OBA inline flow: `ImportAdapter` does not call WCS itself; it fires `wicket_import_create_subscription` and an extension creates the subscription there. The two names are distinct so a create-handler on the OBA path never double-fires on the cheque path.
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
