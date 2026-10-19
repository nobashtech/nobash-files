=== Nobash File Integrity ===
Contributors: shofiqul
Tags: file integrity, security, diagnostics, checksum, site health
Requires at least: 6.5
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only WordPress core checksum and uploads security diagnostics for administrators.

== Description ==

Nobash File Integrity provides administrator-initiated, read-only diagnostics from Tools > File Integrity. It checks WordPress core files against the WordPress.org checksum manifest for the installed version and locale, reports files with suspicious executable extensions in the uploads directory, and flags world-writable files.

The plugin does not upload, download, modify, delete, quarantine, or edit files. It does not run executable code, manage archives, include a file manager, or install other plugins. Scans are limited by time and file count; an incomplete scan is explicitly reported and never described as clean. No account or license key is needed.

A finding does not necessarily mean that the site has been compromised. Inspect findings independently and maintain regular site backups.

== External services ==

When an administrator clicks Run integrity check, WordPress connects to the official WordPress.org core checksums service at https://api.wordpress.org/core/checksums/1.0/ using the installed WordPress version and locale. This is necessary for authoritative core verification. The request includes standard HTTP metadata. No requests are sent to Nobash.

Service website: https://wordpress.org/
Privacy policy: https://wordpress.org/about/privacy/

== Privacy ==

The plugin saves the time, status, counts, and up to 40 relative file paths from the latest scan in the local WordPress options database. No file contents are saved. There is no telemetry, tracking, marketing messaging, or external account requirement. Uninstall removes the stored scan report.

== Installation ==

1. Install the plugin through Plugins > Add New > Upload Plugin.
2. Activate it.
3. Open Tools > File Integrity and click Run integrity check.

== Frequently Asked Questions ==

= Does this plugin edit or repair files? =
No. It is intentionally read-only.

= Does it detect every malicious file? =
No. It checks official core checksums and a limited set of risky file indicators, not all malware.

= Is this the same as the Nobash Files GitHub file manager? =
No. Nobash File Integrity is a separate, read-only plugin with a different scope and slug.

== Changelog ==

= 1.0.0 =
* Initial read-only integrity diagnostic plugin.
