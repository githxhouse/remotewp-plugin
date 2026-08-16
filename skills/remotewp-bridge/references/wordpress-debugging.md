# RemoteWP utility: WordPress debugging

Use this playbook for PHP warnings, fatal errors, broken requests, plugin
conflicts and regressions.

## Workflow

1. Verify `/status`, `/wp/info`, the current capability context and v2 health.
2. Read the relevant error output or approved file before changing anything.
3. Search approved files for the exact error, hook, function or template involved.
4. Build the smallest reproducible explanation and identify the likely owner: theme, custom plugin, third-party plugin or WordPress configuration.
5. Propose a minimal patch, including risk and rollback path.
6. After approval, write with the reviewed hash, idempotency key and operation tracking.
7. Re-read the result, check the operation status and clear cache when applicable.
8. Verify the affected HTTP route or browser flow; if verification is unavailable, say so explicitly.

## Guardrails

- Never use SSH, FTP, WP-CLI, application passwords or XML-RPC as a workaround.
- Never disable security plugins or production safeguards merely to hide an error.
- Stop on `409 file_changed`, `428 expected_sha256_required` or a failed verification.
- Do not delete logs, backups or plugin data as a debugging shortcut.
