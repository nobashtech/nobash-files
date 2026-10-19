# Nobash Files 1.0.0.1 — Review & release notes (October 16, 2026)

## Key blocker for WordPress.org

Official WordPress.org developer FAQ states new plugins allowing arbitrary code insertion or execution are not accepted, with file managers and PHP/JavaScript editors explicitly named. Therefore, the original full **Nobash Files** code cannot be honestly described as WP.org submission-ready simply by clearing automated warnings. Use the full plugin on GitHub; the distinct read-only **Nobash File Integrity** package is a potential directory candidate, subject to review and uniqueness checks.

## Original automated Plugin Check report

Source file: `nobash-files-nobash-files-php-20261016-064107.md` supplied by the project owner.

- 360 total entries: 234 ERROR, 126 WARNING. Counts were calculated from the supplied report (not from a new run).
- Main categories: direct filesystem operations, unescaped exception strings, database query preparation, nonce recommendations, and input sanitization.
- Direct filesystem streaming/atomic writes require careful functional refactors and regression testing; replacing them mechanically with WP_Filesystem may break large files, locking, and crash safety.

## Changes actually implemented

- Upgraded full plugin metadata/readme/changelog to 1.0.0.1; recorded current WordPress readme schema (`Tested up to`).
- Fail-closed private storage. A public `wp-content/.nobash-files` storage fallback is removed; requires a secure writable parent folder outside the WordPress/docroot.
- Restricted delegated file writes to uploads, refused executable extension segments and server config names, and reserved archive extraction/history restoration/undo to administrators. This can change permissions previously granted to delegated users.
- Honored WordPress `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` for `save_file` in core, theme, plugin directories. Other admin-initiated filesystem operations need separate runtime policy verification.
- Migrated the major Nobash_Files_DB read queries to `%i` prepared identifier placeholders (WordPress 6.5+); improved agency query identifier handling and privacy table identifier handling. Some plugin-check sniffs may still flag dynamic static column lists and prepared SQL variables despite their bounded construction.
- Hardened Explorer nonce/path input and safely escaped framed payload, reduced GET path/range input issues, escaped history footer, and used wp_delete_file for temporary admin downloads.
- Added targeted regression smoke tests and a syntax CI workflow. Prepared distinct standalone read-only integrity submission candidate for WP.org.

## Verification performed

- PHP 8.4.24 syntax: all relevant PHP sources passed.
- Node 22 JavaScript `--check`: `assets/js/admin.js` passed.
- Offline access-policy smoke: upload allow and code/traversal/path protection deny tests passed.
- Offline read-only integrity smoke: checksum mismatch, missing file, executable upload, correct final scan status passed.
- Both ZIPs use one folder at root; stable/readme/plugin header versions match.

## NOT verified / remaining tasks

- Official Plugin Check rerun against a running WordPress install, and PHPCS with WordPress Coding Standards; tooling not available in this audit environment. Do not claim the original 360 findings were all eliminated. Some filesystem API and query warnings will remain.
- WordPress 6.5/7.1 real installations, PHP 8.1, multisite, Apache/Nginx, custom uploads, cron, email, backups/version restore, large uploads, ZIP/TAR archive extraction and rollback, database schema upgrades and uninstall preservation.
- Full plugin's broader admin-initiated filesystem operations and all JS template rendering require a separate penetration review. This audit does not constitute an independent penetration test.
- Official WordPress.org directory reviewers may reject the **read-only candidate** for other guideline reasons or insufficient differentiation; acceptance is not guaranteed.
- `Tested up to: 7.1` is supplied as compatibility metadata targeting latest 7.1 branch; a genuine runtime verification on WordPress 7.1 remains mandatory before public submission.

## Suggested release steps

1. Use full plugin ZIP only on staging first; compare with previous 1.0.0.0 install and backup all files/database. Verify the new required private storage folder is writable and inaccessible over HTTP.
2. Run Plugin Check in the WordPress admin on the ZIP; address security findings without hiding them behind indiscriminate PHPCS suppressions.
3. Test every mutation flow including restore, undo, archive jobs, upload chunks, canceled transfers, secure file read, and permissions with non-admin accounts.
4. Publish the full code to a public GitHub repository; add license, docs, GitHub Actions, and tag `v1.0.0.1` only after runtime acceptance.
5. For WordPress.org, review distinct read-only plugin scope/slug and run its own tests, then submit that separate ZIP to the official directory.
6. After WP.org approval, deploy its directory package to the SVN repository provided by WordPress.org (GitHub upload alone does not publish on WordPress.org).

References: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/ ; https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/ ; https://developer.wordpress.org/apis/filesystem/ ; https://wordpress.org/download/releases/
