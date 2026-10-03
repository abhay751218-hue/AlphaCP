# Module: Backup Configuration (WHM)
- **Step:** S10   **Status:** live (panel 0.58.0 / agent 0.51.0)

## Purpose
WHM Backup Configuration slice 1 — schedule, retention and destination.
Writes `/usr/local/alphacp/etc/backup/config.json` (0640, dir 0750).
No tar, no shell, no pipe. Hostile remote host/path fail closed. Customer cPanel never sees this page.

## Panel
- Page: `/backup-configuration` (WHM only: `ModuleCatalog::modeFor() === 'whm'`; customer/mail → 403).
- Table: `backup_configs` (single row, latest wins) — enabled, schedule, retention_days, destination,
  remote_host, remote_user, remote_path.
- Permission: `accounts.view` (same as other WHM server tools); audit event `backup.config`.

## Agent
| type | payload |
|---|---|
| `backup.config` | enabled(bool), schedule(daily/weekly/monthly), retention_days(1–3650), destination(local/remote), remote_host, remote_user, remote_path |

Remote destination requires IPv4/FQDN host, `[a-z][a-z0-9._-]*` user and a **relative** path
(no leading `/`, no `..`, no `|`). PathGuard roots: `/usr/local/alphacp`.

## Later
Real tar/copy engine, remote destination transport, per-account overrides (S10 rows 177–179).
