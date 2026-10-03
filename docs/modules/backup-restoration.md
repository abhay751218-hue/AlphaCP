# Module: Backup Restoration
- **Step:** S10   **Status:** live (panel 0.59.0 / agent 0.52.0)

## Purpose
WHM Backup Restoration slice 1. Writes `/usr/local/alphacp/etc/backup/restoration.json`
(mode + username). No tar, no shell, no pipe. Hostile mode/username fail closed.
Customer cPanel hides the tile (has File Restoration). Tar extract later.

## Agent
| type | payload |
|---|---|
| `backup.restoration` | mode, username |

Mode allowlist: full, partial, account. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
