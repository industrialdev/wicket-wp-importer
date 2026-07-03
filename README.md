# Wicket Importer for WordPress

## Description

This Wicket plugin provides a productized bulk member import pipeline for the Wicket WP stack. It ships two flows on a single generic core:

1. **OBA bulk member import** — CSV upload (or manual entry) → validate → create MDP persons + memberships + subscriptions. Inline processor with a 200-row cap.
2. **Cheque renewal** *(planned, not yet built)* — Bulk Create On-Hold orders + Pending subscriptions, then Bulk Process to Processing + activate subscriptions. Uses Action Scheduler chunking.

The core plugin is generic by design. Client-specific logic (Bar ID, tier resolution, field mapping, conflict checks) lives in client extensions via `wicket_import_*` hooks. See `docs/engineering/hooks.md`.

## Requirements

- **WordPress**: 6.6+
- **PHP**: 8.2+
- `wicket-wp-base-plugin`: Active
- `woocommerce`: Active
- `woocommerce-subscriptions`: Active
- `wicket-wp-memberships`: Active
- **Composer**: For dependency management

The plugin's header declares `Requires Plugins`; WordPress blocks activation if any dependency is missing.

## Development

### Requirements

- WSL2 on Windows, or Linux/macOS with Bash 5.x or greater.
- [Composer](https://getcomposer.org/).
- [EditorConfig](https://editorconfig.org/) installed in your code editor.
- (Optional) PHP CS Fixer for VSCode or your editor of choice.

### Setup local dev environment

Clone the repository into an already-configured Wicket WordPress Baseline instance so you can work on the plugin live using Docker. From the plugin path:

```
composer install
```

### Day to day work

Do your work and have fun :)

When tested and ready, put your relevant changes into the `CHANGELOG.md` file (major changes, new features, or breaking changes).

If you added new libraries through Composer or classes on `src/`, regenerate the autoloader:

```
composer dump-autoload --optimize
```

Then commit and push.

## Coding Style & Naming Conventions

- PHP 8.2+, `declare(strict_types=1);`, PSR-12.
- PSR-4 namespaces under `WicketImporter\`.
- Naming: classes `PascalCase`, methods `camelCase`.
- Favor small methods, early returns, and WordPress-native APIs/hooks.

## Security & WordPress-Specific Requirements

- Sanitize, validate, and escape all input/output.
- Enforce capability checks (`manage_options`) and nonces for admin actions and REST endpoints.
- All DB queries via `$wpdb->prepare()`.
- All CSV exports use `Support\CsvExporter` for injection prevention (AD14).
- No direct WC Subscriptions API calls from core; commerce flows go through `wicket_import_*` hooks (AD10).

## Documentation

- **Shipped reference**: `docs/` in this repo (`docs/index.md` is the entry point). Product, engineering, and end-user guides.
- **Design plan** (architectural decisions, phase breakdown, the not-yet-built Cheque flow, OBA extension spec): workspace-level `docs/importer-plan-*.md`.

## Commit & Pull Request Guidelines

Git history favors short, imperative, scope-specific messages.

- Keep commits focused; avoid mixed refactor/feature changes.
- PRs should include: purpose, risk notes, test evidence.
