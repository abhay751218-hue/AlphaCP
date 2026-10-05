# Module: Disk Usage
- **Step:** S6   **Status:** live (panel 0.17.0 / agent 0.14.0)

## Purpose
cPanel Disk Usage. Folder-wise space under the account home. Readonly.
Walk capped at 2000 nodes; symlinks skipped. Path `..` fail closed.

## Agent
| type | payload |
|---|---|
| `files.usage` | username, path (relative, default home) |

## Permissions
files.view — customer + reseller (cPanel tile, not WHM)
