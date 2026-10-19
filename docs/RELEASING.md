# Release process for Nobash Files

## Release 1.0.0.1

This is a GitHub-distributed WordPress file manager. Do not use the repository's automatic *Source code* archive as the installable ZIP. The release artifact must contain **one `nobash-files/` folder** with only the 26 production files.

### Release verification checklist

- [ ] Confirm WordPress 6.5+ and PHP 8.1+ compatibility on a **real staging site**.
- [ ] Back up all relevant website files and the database before upgrading.
- [ ] Validate that private recovery storage is outside the public document root and cannot be requested over HTTP.
- [ ] Check archive extraction, chunked uploads, code saves, conflict handling, Trash/undo, and rollback after failures.
- [ ] Check non-administrator access, path traversal, symlinks, and executable upload restrictions.
- [ ] Validate Apache/Nginx compatibility, private storage errors, database upgrades, multisite and uninstall behavior.
- [ ] Perform Plugin Check and WordPress Coding Standards review as applicable. Prior audit warnings remain unresolved.
- [ ] Run tests and CI; verify release source matches the staged revision.
- [ ] Review `SECURITY.md`, privacy disclosures, license, and repository version metadata.

### Build

At repository root:

```bash
python3 scripts/build-release.py
```

Output: `dist/nobash-files-release-1.0.0.1.zip`. Attach **this ZIP** to GitHub Release `v1.0.0.1` only after staging acceptance and security review. The workflow also produces a build artifact for review; it does not automatically publish GitHub Releases.

### Important distribution limitation

The full Nobash Files product is **not a candidate for submission as a new WordPress.org directory plugin** because it includes a file manager / code editor. The read-only integrity prototype in `distribution/` is a distinct project and needs its own review and testing.

### Source provenance

The `1.0.0.1` plugin files in this repository are byte-for-byte equivalent to the earlier reviewed `nobash-files-1.0.0.1-github-edition.zip`; project documentation, build tooling, and GitHub automation are maintained separately from the production plugin files.
