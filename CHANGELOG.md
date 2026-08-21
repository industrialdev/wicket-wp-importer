# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

## [1.0.32] - 2026-08-21

### Fixed
- **importer:** spacing below cheque review summary, per-asset cache-bust


## [1.0.31] - 2026-08-21

### Added
- **importer:** gate Cheque Review tab, bare tab becomes cheque queue


## [1.0.30] - 2026-08-21

### Fixed
- **importer:** enforce session flow server-side, scope D3 to bulk orders


## [1.0.29] - 2026-08-21

### Fixed
- **importer:** skip cheque rows with an existing On Hold order (D3)


## [1.0.28] - 2026-08-21

### Added
- **importer:** cheque upload UI on the Upload tab (lockbox Story 1)

### Documentation
- **importer:** correct stale stub docblocks in ChequeReviewPage
- add PR description template #norelease


## [1.0.27] - 2026-08-20

### Added
- **importer:** Phase 2 admin parity in Import History (M8, WWID-2108)
- **importer:** payment CSV upload endpoint + review UI control (M7, Story 9)

### Fixed
- **importer:** fatal on admin_init from stale DbInstaller namespace in drift-notice hooks


## [1.0.26] - 2026-08-19

### Added
- **importer:** Phase 2 payment matching engine + REST (Slice 5, gated off by default)


## [1.0.25] - 2026-08-19

### Fixed
- **importer:** peer-review fixes (SKU discount + sync ordering + tierPostId)


## [1.0.24] - 2026-08-19

### Other
- fix+feat(importer): Story 1 sync seam + Story 6 user-role-keyed mappings


## [1.0.23] - 2026-08-19

### Added
- **importer:** batch IDs on orders and subscriptions (Slice 4.5, Stories 12-13)

### Fixed
- **importer:** renewal meta on all membership-linked order and subscription items
- **importer:** live-test fixes for the cheque flow end-to-end run


## [1.0.22] - 2026-08-19

### Added
- **importer:** cheque upload endpoint + phase1 closeout (cap, timestamps, conflicting_roles)


## [1.0.21] - 2026-08-19

### Fixed
- **admin:** flagged-rows table covered the first data row with its sticky header


## [1.0.20] - 2026-08-18

### Fixed
- **importer:** export "skipped" instead of "pending" in results CSV


## [1.0.19] - 2026-08-18

### Fixed
- **import:** require first and last name on bulk import (#2)


## [1.0.18] - 2026-08-17

### Fixed
- **admin:** spacing between batch actions and Rows heading on History detail


## [1.0.17] - 2026-08-17

### Fixed
- **admin:** opaque frozen headers + sticky header row on flagged-rows table


## [1.0.16] - 2026-08-17

### Fixed
- **admin:** freeze first three columns on flagged-rows table


## [1.0.15] - 2026-08-14

### Added
- **importer:** human-readable progress column in History list


## [1.0.14] - 2026-08-14

### Added
- **importer:** clear-session action column in History list


## [1.0.13] - 2026-08-14

### Fixed
- **importer:** add escape hatch for stuck import sessions


## [1.0.12] - 2026-08-14

### Fixed
- **importer:** show "Skipped" instead of "Pending" for rows excluded by validation


## [1.0.11] - 2026-08-13

### Other
- Prevent text from wrapping onto a new line. This fixes the issue when the table shows content in a stacked way.


## [1.0.10] - 2026-08-13

### Fixed
- **importer:** count conflict rows in cheque review summary and table


## [1.0.9] - 2026-08-13

### Fixed
- **importer:** surface skipped/conflict rows in import summary


## [1.0.8] - 2026-08-12

### Fixed
- **dates:** prevent inverted end<=start window on legacy memberships builds (WWID-2199)


## [1.0.7] - 2026-08-11

### Fixed
- **dates:** anchor imported membership dates to the admit start


## [1.0.6] - 2026-08-11

### Added
- **importer:** inline no-order subscription creator as default handler

### Fixed
- **importer:** split cheque subscription hook from OBA create-seam


## [1.0.5] - 2026-08-10

### Added
- **importer:** add wicket_import_manual_entry_enabled filter #patch


## [1.0.4] - 2026-08-10

### Added
- **importer:** add column order + label override filters


## [1.0.3] - 2026-08-09

_Maintenance release; no recorded changes._


# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.2] - 2026-08-09

### Maintenance
- update dependencies

## [1.0.1] - 2026-08-05

### Added
- **importer:** add sample template url filter hook
- **importer:** add the cheque Phase 1 Review UI + pending_review gate (WWID-2026)

### Changed
- adopt plugin-init pattern (instance plugin_setup)
- **importer:** pass membershipPostId into SubscriptionCreator::create (WWID-2028)

### CI
- **importer:** add automated release infrastructure

### Maintenance
- update dependencies
- **importer:** drop jetpack-autoloader #norelease
