# Module: Backup
- **Step:** S10   **Status:** partial (panel 0.65.0 / agent 0.58.0; deployed 3 Oct, HTTP 200)

## What works now
- Panel 0.65.0 / agent 0.58.0 are deployed; the server snapshot confirms HTTP 200. The update itself succeeded, but a live customer archive/download has not yet been end-to-end exercised.
- Customer can queue an actual GNU tar + gzip archive of their AlphaCP home directory.
- paneld validates the account, checks for free disk space, refuses symlinked storage roots, writes to root-owned storage, verifies the archive by listing it, records a SHA-256 manifest, and publishes it atomically.
- Retries are idempotent by a panel-generated 128-bit archive id; existing files are returned only after manifest and checksum verification.
- The customer download route is account-scoped, requires `files.view`, checks the agent task result, rejects symlinks, verifies size + SHA-256 again, and streams the file. Archive storage is `/usr/local/alphacp/backups/accounts/<username>/`, root:`alphacp`, directory `0750`, archive `0640`.
- The updater adds only that dedicated directory to PHP-FPM `open_basedir`; the secret-bearing `/usr/local/alphacp/etc` and root-only `/usr/local/alphacp/var` are not widened.

## Deliberate limits (S10 is NOT complete)
- Home files only. Current S7 mailbox and S8 database tools write JSON settings rather than provisioning real Exim/Dovecot mailboxes or MySQL databases, so no mail/database backup is claimed.
- `backup.create` still stores a home/mail/mysql job configuration; it is not a scheduler or a dump job.
- No automated schedule, remote destination, archive restore/extraction, cPanel `.tar.gz` import, or transfer/copy yet. Archives are not encrypted at rest. Restore is intentionally not enabled until archive extraction and path ownership have a separate security review and tests.
- Therefore this is not yet a complete cPanel-equivalent backup system and must not be advertised as one.

## Agent
| type | payload | behavior |
|---|---|---|
| `backup.create` | username, jobs[{kind, path}] | Saves configuration JSON only |
| `backup.archive` | username, archive_id | Creates and verifies a real home `.tar.gz` |

Archive safety: `tar` is an explicit command allowlist entry; invocation is argv-only, uses fixed GNU tar flags, a validated account name and an ID matching `^[a-f0-9]{32}$`. Symlinks inside the account are stored as links, not followed. The task scans file sizes and requires an additional 64 MiB free-space reserve. It prunes expired, checksum-manifested archives before the free-space check, using the existing WHM retention setting; scheduled creation is not wired yet.

## Permissions and audit
- Customer: `files.manage` to queue; `files.view` to download their own completed archives.
- WHM/root: customers only; no server-wide archive endpoint is implemented by this feature.
- Each queue request creates a task row and audit event. No archive path is accepted from a customer.

## S10 completion gates
| Checklist row | Current reality | Still required for parity |
|---|---|---|
| 8 Manual backup download | Real home-files `.tar.gz` create + verified download | Include real mail and database exports; add full account/package metadata and usable remote destinations |
| 9 Backup Wizard | Action/scope JSON plan | Wizard must queue real create/restore actions and show outcomes |
| 10 File & Directory Restoration | Request JSON only | Safe archive selection, staged extraction, path validation, ownership/rollback, and UI verification |
| 176 Backup Configuration | Schedule/retention JSON | Timer/worker integration, retention enforcement across runs, remote destinations and health reporting |
| 177 Backup Restoration | Full/partial/account JSON request | Actual, permission-gated restore workflow with typed confirmation and rollback |
| 178 Backup User Selection | Username JSON list | Scheduler must honor selected accounts; limits and account existence revalidation |
| 179 File and Directory Restoration | Username/path JSON request | Restore from verified archives and review logs/results |
| 185 Transfer Tool | Source/username JSON request | Authenticated transfer from cPanel with preflight, progress, cancellation and rollback |
| 186 Transfer or Restore a cPanel Account | Action JSON request | Parse/import a real cPanel archive and provision all supported services |
| 188 Review Transfers and Restores | Last JSON username/status only | Persistent job history, real task linkage, result/error/progress review |
| 199 cPanel backup import | Not implemented | Safe `.tar.gz` preflight, format support matrix, staging, restore and migration tests |

**Prerequisite gaps:** S7 does not yet configure/test full Exim/Dovecot mailflow; S8 does not yet create MySQL databases/users/grants; S9 does not write/reload BIND zones. S10 cannot truthfully claim mail, database, DNS, or full cPanel-account backups until these host backends exist.

## Verification
- Agent tests: fake tar executor covers allowlist schema, success, checksum manifest, retry idempotence, hostile id, and cleanup on tar failure.
- GNU tar integration check: create/list/extract round-trip and symlink-not-followed.
- Panel tests cover queueing, account-scoped download, tamper rejection, cross-account isolation, and existing backup-job behavior.
