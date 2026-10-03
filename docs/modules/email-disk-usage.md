# Module: Email Disk Usage
- **Step:** S7   **Status:** live (panel 0.34.0 / agent 0.30.0)

## Purpose
cPanel Email Disk Usage slice 1. Per-folder sizes under `~/mail`.
Readonly walk. Symlinks skipped. Path `..` / pipe fail closed. Purge later.

## Agent
| type | payload |
|---|---|
| `mail.usage` | username, path (relative to `mail/`, default mail root) |

## Permissions
email.view — customer + mail (cPanel tile, not WHM)
