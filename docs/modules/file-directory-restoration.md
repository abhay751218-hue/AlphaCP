# Module: File and Directory Restoration
- **Step:** S10   **Status:** live (panel 0.61.0 / agent 0.54.0)

## Purpose
WHM File and Directory Restoration slice 1. Writes
`/usr/local/alphacp/etc/backup/filedir.json` (username + relative path).
No tar, no shell, no pipe. Hostile username/path fail closed.
Customer cPanel hides the tile (has File Restoration). Tar extract later.

## Agent
| type | payload |
|---|---|
| `backup.filedir` | username, path |

Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved. Path: relative under home.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
