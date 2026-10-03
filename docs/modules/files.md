# Module: File Manager
- **Step:** S6   **Status:** live (panel 0.15.0 / agent 0.12.0) — first slice

## Purpose
cPanel File Manager (slice 1). Browse / mkdir / edit (256 KiB) / delete / rename
inside the account home. Zip, chmod, upload-drag, trash later.

## Agent
| type | payload |
|---|---|
| `files.list` | username, path (relative, default home) |
| `files.set` | username, op=mkdir\|write\|delete\|rename, path, content?, to? |

Paths fail closed: no `..`, ASCII segment names, never mutate home root.

## Permissions
files.view / files.manage — customer + reseller (cPanel tile, not WHM)
