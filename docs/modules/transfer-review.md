# Module: Review Transfers and Restores
- **Step:** S10   **Status:** partial JSON plan only (introduced in panel 0.64.0 / agent 0.57.0; carried in 0.65.0 / 0.58.0)

## Purpose
WHM Review Transfers and Restores currently writes
`/usr/local/alphacp/etc/backup/review.json` (username + status). It is not connected to a real transfer/restore queue or historical job records yet.
No tar, no rsync, no shell, no pipe. Hostile username/status fail closed.
Customer cPanel hides the tile. Real job review is pending.

## Agent
| type | payload |
|---|---|
| `backup.review` | username, status |

Status allowlist: pending, ok, failed. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
