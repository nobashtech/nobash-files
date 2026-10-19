# Contributing to Nobash Files

Thanks for helping improve Nobash Files. Contributions should be focused, documented, and compatible with GPL v2 or later.

## Before submitting a change

1. Search existing issues before opening a duplicate.
2. For a bug, explain expected/actual behavior, affected feature, minimal reproduction, WordPress/PHP versions, and hosting details. Remove personal data and credentials.
3. For significant feature work or security-sensitive changes, discuss the approach in an issue before implementation.
4. Keep PHP/JavaScript source readable and maintain WordPress capability, nonce, path authorization, and output escaping protections.
5. Include tests for fixes where possible; run the checks listed in the project README.

Filesystem changes require manual verification on staging, including failure recovery, permission boundaries, multisite (where applicable), Apache/Nginx differences, and interactions with `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`.

**Never publish active exploits, API keys, private diagnostics, or production file contents in an issue or pull request.** Report vulnerabilities privately according to `SECURITY.md`.

Pull requests should describe the reason for the change, implementation summary, verification steps, and known limitations. A passing lint check does not establish production safety.
