# Module: File Restoration
- **Step:** S10   **Status:** live (panel 0.57.0 / agent 0.50.0)

## Purpose
cPanel File & Directory Restoration slice 1. Writes `~/etc/backup/restore.json`
(relative paths). No tar, no shell, no pipe. Hostile path fail closed. WHM hides the tile.

## Agent
| type | payload |
|---|---|
| `backup.restore` | username, paths[{path}] |

Path relative under account home (PathGuard). Copy later.

## Permissions
files.view / files.manage — customer cPanel. Mail 403. Root WHM has no tile.
