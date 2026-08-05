# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

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

