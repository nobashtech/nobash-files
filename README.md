# Nobash Files

**A free, open-source WordPress file manager for managing, maintaining, and recovering website files from one workspace.**

[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-3c4657)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.0.0.1-3c4657)](changelog.txt)

Developed by **[Nobash](https://nobash.com/)** · [Issues](https://github.com/nobashtech/nobash-files/issues) · [Security policy](SECURITY.md)

Nobash Files brings practical file operations, code editing, recovery history, integrity checks, and site-safety tools into the WordPress dashboard. It is designed for administrators who want more control over website files without switching between multiple management tools.

**Free and open source:** All features shipped in the plugin are available without a paid plan, a license key, or a Nobash account. This is a **GitHub-distributed plugin**; see [distribution notes](#distribution-and-wordpressorg) before installing.

## Features

| Area | What it includes |
| --- | --- |
| **File workspace** | Browse folders; preview, search, upload, move, rename, copy, and manage files. |
| **Code editing** | Multi-tab editor, syntax checks, conflict detection, and configurable safety versions. |
| **Archives and recovery** | ZIP/TAR tools, guarded extraction, Trash, supported-operation undo, and local version history. |
| **Integrity and security** | Core checksum checks, component baselines, suspicious-file review, quarantine, and permission diagnostics. |
| **Site monitoring** | Optional scheduled checks, alerts, and activity history. |
| **Access controls** | Role- and user-based folder policies, restricted delegated operations, and site administration tools. |
| **Multisite and agency tools** | Workspaces, policy templates, and audit export where supported. |

Features and permissions depend on your site setup and user role. File editing, recovery, and archive operations can have serious consequences on a live website. **Always maintain independent website and database backups.**

## Install

1. Download the **installable `nobash-files-release-1.0.0.1.zip`** asset from this project's release. Do **not** install GitHub's automatically generated *Source code* ZIP.
2. Back up your website and database. Try the upgrade on a staging site first.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and choose the release ZIP.
4. Activate **Nobash Files** and follow the setup guide. Keep Safe Mode enabled during initial configuration.
5. Confirm that your host can create a **private, writable directory outside the public document root** for file versions and recovery data. The plugin intentionally does not fall back to a publicly accessible `wp-content` folder.
6. Test browsing, uploads, editing, restore/undo, and permission restrictions before using it on production.

**Upgrading from the former two-plugin setup?** Deactivate the older extension before installing 1.0.0.1 to avoid duplicate class loading. Read the [release notes](docs/RELEASING.md) and back up first.

## Requirements

| Requirement | Minimum / condition |
| --- | --- |
| WordPress | 6.5 or later |
| PHP | 8.1 or later |
| Private recovery storage | Writable directory outside the web-served document root |
| License | GPL version 2 or later |

**Security behavior:** Directory traversal and symbolic-link paths are rejected. Delegated writes are restricted to non-executable upload content; privileged operations remain administrator-only. Supported editor operations also respect WordPress file-editing restrictions. These measures reduce risk but do not replace server hardening or a security audit.

## Privacy and external services

Normal local file management does not require a vendor account or send usage telemetry to Nobash. Administrator-triggered integrity and repair actions can contact WordPress.org for official checksums and verified packages. Optional monitoring email uses your site's WordPress mail configuration. File history, activity, and recovery metadata may contain user identifiers or previous file content.

See [readme.txt](readme.txt) for full information, including data retention and external-service disclosures.

## Developers

This repository contains the readable PHP, JavaScript, and CSS source; dedicated offline regression tests; and GitHub Actions checks. No front-end build step is required for installation.

```bash
# PHP 8.1+ and Node.js are recommended for local checks.
php tests/access-policy-smoke.php
php tests/integrity-smoke.php
find . -name '*.php' -print0 | xargs -0 -n 1 php -l
node --check assets/js/admin.js
python3 scripts/build-release.py
```

The build command creates the **installable plugin ZIP** in `dist/` without adding tests, project metadata, or the separate integrity candidate to the shipped plugin. Source-level tests are not equivalent to a live WordPress compatibility or penetration test.

### Project structure

| Location | Purpose |
| --- | --- |
| `nobash-files.php` | WordPress plugin entry point |
| `includes/` | File management, authorization, safety, monitoring, and integrations |
| `assets/` | WordPress admin styles and scripts |
| `tests/` | Targeted offline tests |
| `scripts/` | Repeatable build and package verification |
| `.github/workflows/` | CI lint and packaging checks |
| `docs/` | Review history and release checklist |
| `distribution/wporg-file-integrity/` | **Separate**, read-only plugin candidate (not part of Nobash Files release ZIP) |

For development expectations, see [CONTRIBUTING.md](CONTRIBUTING.md).

## Distribution and WordPress.org

**Nobash Files is distributed through GitHub, not as a new WordPress.org directory submission.** WordPress.org currently excludes new plugins whose core purpose enables arbitrary code modification/execution, including full file managers and PHP/JavaScript editors. That restriction is unrelated to open-source licensing.

A **separate read-only Nobash File Integrity prototype** is present under `distribution/` for independent evaluation. It is not the full file manager and has **not** been approved by WordPress.org. See [review and release notes](docs/REVIEW-AND-RELEASE.md).

This repository does **not** bundle a custom GitHub updater. For new versions, obtain the designated release ZIP and follow the release instructions.

## Contributing and security

Focused bug reports and pull requests are welcome. Please include reproducible steps, environment versions, and sanitized logs when reporting an issue. See [CONTRIBUTING.md](CONTRIBUTING.md). **Do not post secrets, personal information, site configuration dumps, or exploit details publicly.** For suspected vulnerabilities, use the private reporting guidance in [SECURITY.md](SECURITY.md).

## License

Nobash Files is licensed under the [GNU General Public License v2 or later](LICENSE).

---

**[Nobash](https://nobash.com/)** · Building Digital Excellence
