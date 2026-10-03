# Module: Backup Config
- **Step:** S10   **Status:** live (panel 0.58.0 / agent 0.51.0)

## Purpose
WHM Backup Configuration slice 1. Writes `/usr/local/alphacp/etc/backup/config.json`
(schedule + retention). No tar, no shell, no pipe. Hostile schedule/retention fail closed.
Customer cPanel hides the tile. Remote destination later.

## Agent
| type | payload |
|---|---|
| `backup.config` | schedule, retention |

Schedule allowlist: daily, weekly, monthly, disabled. Retention 1–365 days.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
