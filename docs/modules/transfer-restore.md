# Module: Transfer or Restore a cPanel Account
- **Step:** S10   **Status:** live (panel 0.63.0 / agent 0.56.0)

## Purpose
WHM Transfer or Restore a cPanel Account slice 1. Writes
`/usr/local/alphacp/etc/backup/cpanel-account.json` (username + action).
No tar, no rsync, no shell, no pipe. Hostile username/action fail closed.
Customer cPanel hides the tile. Copy later.

## Agent
| type | payload |
|---|---|
| `backup.cpanel` | username, action |

Action allowlist: transfer, restore. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
