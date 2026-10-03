# Module: Backup User Selection
- **Step:** S10   **Status:** live (panel 0.60.0 / agent 0.53.0)

## Purpose
WHM Backup User Selection slice 1. Writes `/usr/local/alphacp/etc/backup/users.json`
(username list, max 10). No tar, no shell, no pipe. Hostile username fail closed.
Customer cPanel hides the tile. Tar later.

## Agent
| type | payload |
|---|---|
| `backup.users` | users[] username |

Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
