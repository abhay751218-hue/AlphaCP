# Module: Transfer Tool
- **Step:** S10   **Status:** live (panel 0.62.0 / agent 0.55.0)

## Purpose
WHM Transfer Tool slice 1 (cPanel→AlphaCP). Writes
`/usr/local/alphacp/etc/backup/transfer.json` (username + source FQDN).
No tar, no rsync, no shell, no pipe. Hostile username/source fail closed.
Customer cPanel hides the tile. Copy later.

## Agent
| type | payload |
|---|---|
| `backup.transfer` | username, source |

Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved. Source: FQDN.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
