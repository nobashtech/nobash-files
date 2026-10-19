=== Nobash Files ===
Contributors: shofiqul
Tags: file manager, file explorer, code editing, recovery, developer tools
Requires at least: 6.5
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 1.0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress file manager with safe editing, recovery, access controls, monitoring and security tools.

== Description ==
Nobash Files provides one WordPress file workspace. All features are included with no paid edition, activation key, or Nobash account required.

* File browsing, chunked uploads, copy/move/rename/duplicate, search and previews.
* Recoverable Trash, supported-operation Undo, ZIP/TAR archives and guarded extraction.
* Multi-tab code editing, syntax validation, conflict detection and configurable safety versions (up to 100 per file).
* Role/user folder policies and operation permissions.
* WordPress core checksums, trusted plugin/theme baselines and suspicious-file review.
* Optional scheduled monitoring and email alerts.
* Quarantine, permission diagnostics, verified core repair and rollback.
* Multisite workspaces, agency policy templates and audit export.


= Safety model =
File access is bounded to the WordPress root and rejects traversal and symbolic links. Sensitive actions require administrator permissions. Delegated writes are limited to non-executable upload content, even when an older access policy grants broader permissions; archive extraction, restore and undo are administrator-only. Code saves use safety versions when Safe Mode is enabled. Recovery and rollback apply to supported operations; maintain a separate site/database backup.

Private recovery storage must be writable outside the public web root. This build fails closed when private storage cannot be created; it does not put backups or recovery versions inside web-accessible wp-content.

== External services ==
Local file management requires no external account. Scheduled monitoring and email alerts are optional and disabled by default.

= Official checksum service =
Administrator-initiated integrity checks retrieve official core checksums using the installed WordPress version and locale. Administrator-initiated repair and component verification can download official WordPress core, plugin or theme packages. Requests may contain the installed version, locale, component slug/version and standard HTTP request metadata. Verified repair changes only selected files with recovery safeguards.
Service: https://wordpress.org/
Privacy: https://wordpress.org/about/privacy/

= Email =
When an administrator enables monitoring email, configured recipients receive site/integrity alert details through WordPress mail and the site's configured email delivery provider.

== Privacy ==
Nobash Files stores WordPress user IDs with activity, version and recovery metadata, per-user Favorites/Recent paths, and monitoring settings/alerts. Safety versions may contain historical file content. WordPress Privacy Tools can export linked Nobash metadata and anonymize user associations. Settings controls whether plugin data is kept or removed at uninstall. No Nobash licensing SDK, telemetry, remote scripts or GitHub updater is included.

== Installation ==
1. Upload the nobash-files directory or install its ZIP through Plugins.
2. Activate Nobash Files and follow the optional Setup Guide.
3. Keep Safe Mode enabled and maintain independent site/database backups.
4. Configure optional access policies and monitoring as needed.

== Frequently Asked Questions ==

= Are any features paid or locked? =
No. All plugin features are included. No third-party storage account is needed.

= Does this GitHub release update itself? =
No custom GitHub updater is included. This full file-manager edition should not be submitted as a new WordPress.org plugin: current directory guidance disallows new arbitrary code editors and file managers. See the repository review notes.

= How do I upgrade from the former two-plugin setup? =
Back up the site and deactivate the old extension before updating the main plugin to 1.0.0.1. Existing option/table names are preserved. The old extension is no longer needed and must remain inactive to avoid duplicate classes.

== Changelog ==

= 1.0.0.1 =
* Security and compatibility maintenance release.
* Require recovery storage outside the public document root.
* Improve input validation, streaming response handling, and query preparation.

= 1.0.0.0 =
* One free plugin by Nobash, with all features included.
* Included extended history, access policies, advanced integrity, monitoring, security, Multisite and agency tools.
* Removed licensing, SDK dependencies, upgrade screens and paid feature locks.
* Hardened file access, recovery, private storage and local file operations.
* Corrected security route dispatch.
* Removed third-party storage integrations and their settings, APIs and UI.
