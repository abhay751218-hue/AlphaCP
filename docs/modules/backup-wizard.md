# Module: Backup Wizard
- **Step:** S10   **Status:** live (panel 0.56.0 / agent 0.49.0)

## Purpose
cPanel Backup Wizard slice 1. Writes `~/etc/backup/wizard.json` (action + scope).
No tar, no shell, no pipe. Hostile action/scope fail closed. WHM hides the tile.

## Agent
| type | payload |
|---|---|
| `backup.wizard` | username, action, scope |

Action allowlist: backup, restore. Scope: full, home, mail, mysql.

## Permissions
files.view / files.manage — customer cPanel. Mail 403. Root WHM has no tile.
