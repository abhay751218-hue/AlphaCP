# Module: Review Transfers and Restores
- **Step:** S10   **Status:** live (panel 0.64.0 / agent 0.57.0)

## Purpose
WHM Review Transfers and Restores slice 1. Writes
`/usr/local/alphacp/etc/backup/review.json` (username + status).
No tar, no rsync, no shell, no pipe. Hostile username/status fail closed.
Customer cPanel hides the tile. Copy later.

## Agent
| type | payload |
|---|---|
| `backup.review` | username, status |

Status allowlist: pending, ok, failed. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
