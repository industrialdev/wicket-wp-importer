=== Wicket Importer ===
Contributors: wicket, estebanforge
Tags: wicket, import, csv, members, membership, mdp
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk member import pipeline for the Wicket member data platform in WordPress.

== Description ==

Wicket Importer is a high-performance bulk member import pipeline integrating WordPress with the Wicket Member Data Platform (MDP). It provides an automated, multi-phase staging system to process CSV uploads or single-member manual entries, validate records, resolve person identity, and provision memberships into WordPress and WooCommerce Subscriptions.

= Core Features =
* **Multi-Phase Staging Pipeline:** CSV parsing, schema validation, conflict detection, identity resolution, and MDP synchronization.
* **Extensible Architecture:** Over 18 extension hooks (`wicket_import_*`) allowing client themes and plugins to define custom CSV columns, field validators, tier resolution logic, and post-import triggers.
* **Interactive Admin UI:** Full-featured WordPress Admin interface (`Wicket -> Import`) providing CSV drag-and-drop, real-time file validation preview, flagged row resolution, manual single-member creation, and result summary screens.
* **Lockbox & Cheque Support:** Built-in engine for bulk renewal order processing, cheque payment handling, and subscription creation.
* **Data Safety:** Zero destructive ALTER TABLE operations on extension updates; all staged records and extension metadata are stored as JSON blobs in standard staging tables.

== Installation ==

1. Ensure required Wicket stack plugins are active: `wicket-wp-base-plugin`, `woocommerce`, `woocommerce-subscriptions`, and `wicket-wp-memberships`.
2. Upload the `wicket-wp-importer` directory to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Navigate to **Wicket -> Import** in the WordPress Admin dashboard.

== Configuration ==

No initial database configuration is required. Default mappings and CSV column definitions are registered automatically via default core hooks and any active Wicket client extensions.

Available filter hooks for basic setup:
* `wicket_import_max_file_size`: Customize maximum CSV upload size (default: 4 MB).
* `wicket_import_csv_delimiter`: Customize CSV column delimiter (default: `,`).
* `wicket_import_session_ttl_hours`: Adjust staging session expiry duration (default: 24 hours).

== Frequently Asked Questions ==

= Why does template download fail with an error? =
A client extension (or client theme hook) must be active to register custom domain columns and CSV definitions. Without an active extension, template download returns an alert asking to enable an importer extension.

= What file formats are supported? =
Standard UTF-8 CSV files are supported. Automatic encoding handling is provided for UTF-8 with BOM, UTF-16LE, and UTF-16BE.

= What is the maximum file size and row limit? =
The default maximum upload file size is 4 MB (filterable via `wicket_import_max_file_size`). The default maximum row count for inline batch processing is 200 rows.

= What happens to flagged rows? =
Flagged rows failing field validation or conflict checks are displayed on the Validation screen. When choosing "Proceed with Valid Rows", flagged rows are skipped entirely without creating partial records. Flagged rows can be exported as a CSV for manual correction and re-upload.

= Where do developer hook references live? =
Per Wicket stack architecture rules (ADR A0002), developer documentation and hook references live in the Atlas knowledge base (`atlas/packages/wicket-wp-importer/hooks.md`), while end-user manuals live in `docs/`.

== Changelog ==

= 1.0.0 =
* Initial release of the Wicket Importer plugin.
* Bulk CSV import pipeline with validation, conflict check, and confirmation screens.
* Manual single-member entry form.
* Core extension hook surface (`wicket_import_*`).
