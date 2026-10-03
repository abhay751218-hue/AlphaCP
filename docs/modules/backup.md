# Module: Backup
- **Step:** S10   **Status:** partial (panel 0.67.0 / agent 0.60.0; server runs 0.66.0 / 0.59.0, HTTP 200)

## What works now
- Panel 0.66.0 / agent 0.59.0 are deployed; the server snapshot (3 Oct 16:49Z) confirms HTTP 200. A live customer archive/download has not yet been end-to-end exercised in a browser.
- Customer can queue an actual GNU tar + gzip archive of their AlphaCP home directory.
- paneld validates the account, checks for free disk space, refuses symlinked storage roots, writes to root-owned storage, verifies the archive by listing it, records a SHA-256 manifest, and publishes it atomically.
- Retries are idempotent by a panel-generated 128-bit archive id; existing files are returned only after manifest and checksum verification.
- The customer download route is account-scoped, requires `files.view`, checks the agent task result, rejects symlinks, verifies size + SHA-256 again, and streams the file. Archive storage is `/usr/local/alphacp/backups/accounts/<username>/`, root:`alphacp`, directory `0750`, archive `0640`.
- The updater adds only that dedicated directory to PHP-FPM `open_basedir`; the secret-bearing `/usr/local/alphacp/etc` and root-only `/usr/local/alphacp/var` are not widened.
- **Real restore (0.67.0 / agent 0.60.0, task `backup.recover`)** — a completed archive can be restored into the account. The agent re-verifies the manifest, SHA-256, size and root ownership, extracts into a fresh root-owned `0700` staging directory *next to* the home (same filesystem), audits the staged tree, and only then swaps the home in with `rename()` (automatic rollback if the swap fails, previous home deleted afterwards).
- Restore audit rules (all fail-closed, proven by tests): the staged tree must contain exactly one account directory; special files (fifo/socket/device) are rejected; setuid/setgid bits are cleared and reported; symlinks whose target is absolute or escapes the account subtree are deleted and reported instead of recreated; ownership is transferred to the account, and a single ownership failure (for example a disk-quota `EDQUOT`) aborts the restore while the live home is still untouched; nesting is capped at 40 levels and 250 000 entries.
- GNU tar itself refuses to write *through* a staged symlink (`Cannot open: Not a directory`, exit 2), and strips leading `/` and `../` from member names — `tools/sim/backup-tar-sim.sh` v0.2.0 proves all three behaviours as user and as root with the exact flags the agent uses.

## Deliberate limits (S10 is NOT complete)
- Home files only. Current S7 mailbox and S8 database tools write JSON settings rather than provisioning real Exim/Dovecot mailboxes or MySQL databases, so no mail/database backup is claimed.
- `backup.create` still stores a home/mail/mysql job configuration; it is not a scheduler or a dump job.
- Restore is **whole-home only**: it replaces the account home with the archive. Per-file/per-directory restore (cPanel "File and Directory Restoration"), mail/MySQL restore, and cPanel `.tar.gz` import are not implemented.
- A restore also rolls back the panel-managed `~/etc/*.json` state files (privacy, ssh keys, mail/DNS/database settings) to whatever the archive contained; the database stays authoritative and the next config save rewrites them. Nothing re-provisions vhost/pool/cron automatically after a restore yet.
- No automated schedule, remote destination, or transfer/copy yet. Archives are not encrypted at rest.
- Therefore this is not yet a complete cPanel-equivalent backup system and must not be advertised as one.

## Agent
| type | payload | behavior |
|---|---|---|
| `backup.create` | username, jobs[{kind, path}] | Saves configuration JSON only |
| `backup.archive` | username, archive_id | Creates and verifies a real home `.tar.gz` |
| `backup.recover` | username, archive_id, `_confirm` | **Destructive.** Restores that verified archive: staged extraction → audit → ownership → atomic home swap with rollback |

Archive safety: `tar` is an explicit command allowlist entry; invocation is argv-only, uses fixed GNU tar flags, a validated account name and an ID matching `^[a-f0-9]{32}$`. Symlinks inside the account are stored as links, not followed. The task scans file sizes and requires an additional 64 MiB free-space reserve. It prunes expired, checksum-manifested archives before the free-space check, using the existing WHM retention setting; scheduled creation is not wired yet.

Restore safety (`backup.recover`): registered as `destructive` with `confirm = backup.recover`, so TaskRunner rejects any payload without `_confirm`; the panel additionally requires the customer to type the username (the same confirmation model as `account.terminate`) and audits the action as `critical`. The archive path is never taken from the customer — it is derived from the validated username + `^[a-f0-9]{32}$` id inside the root-owned store, every path passes PathGuard with symlink-chain checks, extraction needs `archive_size × 8 + 64 MiB` free space, and the live home is renamed aside (not deleted) until the staged tree has passed the audit and the swap succeeded.

## Permissions and audit
- Customer: `files.manage` to queue an archive or a restore; `files.view` to download their own completed archives.
- WHM/root: customers only; no server-wide archive endpoint is implemented by this feature.
- Each queue request creates a task row, an `account_events` row and an audit event (`backup.recover` = `critical`, with archive id + SHA-256). No archive path is accepted from a customer. The restore route pins `{archiveId}` to `^[a-f0-9]{32}$` and the controller re-checks it, so a hostile id can never reach the agent.

## S10 completion gates
| Checklist row | Current reality | Still required for parity |
|---|---|---|
| 8 Manual backup download | Real home-files `.tar.gz` create + verified download + real restore of that archive | Include real mail and database exports; add full account/package metadata and usable remote destinations |
| 9 Backup Wizard | Action/scope JSON plan | Wizard must queue real create/restore actions and show outcomes |
| 10 File & Directory Restoration | Request JSON only (staged extraction/audit/ownership/rollback primitives now exist in `backup.recover`) | Per-path selection from a verified archive, copy-into-place instead of whole-home swap, and UI verification |
| 176 Backup Configuration | Schedule/retention JSON | Timer/worker integration, retention enforcement across runs, remote destinations and health reporting |
| 177 Backup Restoration | Full/partial/account JSON request; full-home restore is real through `backup.recover` | WHM wiring to the real engine, partial (mail/db/home-subset) modes, per-account batch restore and job progress |
| 178 Backup User Selection | Username JSON list | Scheduler must honor selected accounts; limits and account existence revalidation |
| 179 File and Directory Restoration | Username/path JSON request | Restore from verified archives and review logs/results |
| 185 Transfer Tool | Source/username JSON request | Authenticated transfer from cPanel with preflight, progress, cancellation and rollback |
| 186 Transfer or Restore a cPanel Account | Action JSON request | Parse/import a real cPanel archive and provision all supported services |
| 188 Review Transfers and Restores | Last JSON username/status only | Persistent job history, real task linkage, result/error/progress review |
| 199 cPanel backup import | Not implemented | Safe `.tar.gz` preflight, format support matrix, staging, restore and migration tests |

**Prerequisite gaps:** S7 does not yet configure/test full Exim/Dovecot mailflow; S8 does not yet create MySQL databases/users/grants; S9 does not write/reload BIND zones. S10 cannot truthfully claim mail, database, DNS, or full cPanel-account backups until these host backends exist.

## Verification
- Agent tests: fake tar executor covers allowlist schema, success, checksum manifest, retry idempotence, hostile id, and cleanup on tar failure.
- Restore tests (`provision-sim`): destructive/confirm gating + schema fail-closed, real staged restore into the home (previous home replaced, staging cleaned up), tampered-archive rejection with the live home untouched, missing archive / hostile id / foreign manifest rejection, escaping symlink drop with in-home symlinks preserved, setuid+setgid clearing with normal mode bits preserved, and rejection of a staged tree that is not exactly the account home.
- GNU tar integration check (`backup-tar-sim.sh` v0.2.0, runs as user and as root): create/list/gzip round-trip, the exact staged-extract flag set, symlink-through-extraction blocked, `..` members contained, extra top-level entries visible to the agent's "exactly one account directory" rule.
- Panel tests cover queueing, account-scoped download, tamper rejection, cross-account isolation, restore queueing with the typed username, wrong/missing confirmation, unknown and foreign archive ids, hostile ids, suspended-account blocking, and existing backup-job behavior.
