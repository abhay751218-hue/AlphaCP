# Module: Backup
- **Step:** S10   **Status:** live (panel 0.55.0 / agent 0.48.0)

## Purpose
cPanel Backup slice 1. Writes `~/etc/backup/jobs.json` (kind home/mail/mysql).
No tar, no shell, no pipe. Hostile kind/path fail closed. WHM hides the tile.

## Agent
| type | payload |
|---|---|
| `backup.create` | username, jobs[{kind, path}] |

Kind allowlist: home, mail, mysql. Path only for home (relative, PathGuard).

## Permissions
files.view / files.manage — customer cPanel. Mail 403. Root WHM has no tile.
