# Nobash Files 1.0.0.1

**GitHub distribution · Free and open source · GPL v2 or later**

This maintenance release improves key safety boundaries for the WordPress file manager, including private recovery storage placement, delegated write restrictions, protected file editing rules, input handling, and prepared database identifier handling.

## Notable changes

- Recovery storage fails closed unless a private writable directory outside the public web root is available.
- Delegated write operations are restricted to approved uploads and executable filenames are blocked.
- Privileged archive extraction and recovery actions remain administrator-only.
- Improved request handling, Explorer output framing, and several database queries.
- Includes local access-policy smoke tests and basic GitHub CI.

## Before installing

**Back up your site and database, then test this release on staging.** Confirm private recovery storage, uploads, archive operations, editing, restore/undo and permission policies work on your host. Disable previous companion extensions before upgrading.

**Known validation gaps:** This release has source-level checks, not a verified full runtime test matrix, external penetration test, or successful official Plugin Check rerun. Do not treat a clean PHP syntax check as security certification. See `docs/REVIEW-AND-RELEASE.md`.

**Distribution:** The plugin's full file-management / code-editing feature set is for GitHub distribution; do not submit this ZIP as a new WordPress.org plugin.
