# Module: Transfer or Restore a cPanel Account
- **Step:** S10   **Status:** live (panel 0.69.0 / agent 0.61.0)

## Purpose
WHM **Transfer or Restore a cPanel Account**: import a real cPanel account
archive (`cpmove-<user>.tar.gz`, legacy `backup-*.tar.gz`, or the older nested
`homedir.tar` form) into an existing AlphaCP account's home.

The panel does not read the archive. The operator places the tarball on the
server (`/home/cpmove-<user>.tar.gz`, or the `<ACP home>/incoming/` drop dir) and
the page queues the absolute path; the root agent validates it and performs the
home swap (engine: `docs/modules/cpanel-import.md`).

## Pieces
| Piece | Where | Job |
|---|---|---|
| form | `transfer-restore.index` (WHM) | action, username, archive path, optional sha256 |
| detected archives | `App\Support\CpanelArchives` | scans `/home` + `<ACP home>/incoming` for cpmove/backup archives |
| task | `backup.cpanel` (`destructive`, `_confirm`) | verified import + pre-restore copy |
| history | Review Transfers and Restores | status/result from the task queue |

## Agent
| type | payload |
|---|---|
| `backup.cpanel` | username, action (`transfer`/`restore`), archive_path, sha256?, `_confirm` |

Action allowlist: transfer, restore. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not
reserved. Archive path: absolute, `.tar`/`.tar.gz`/`.tgz`, no `..`, no pipe, no
path escape, ≤255 chars. sha256 optional but must be 64 hex.

The account must exist (Accounts → Create Account) — importing replaces its
home and keeps `/home/.acp-prerestore-<user>-<stamp>`. Only the `homedir` part of
the archive is imported; MySQL/mail/DNS sections are reported for the later S10
steps (see `docs/modules/cpanel-import.md`).

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
