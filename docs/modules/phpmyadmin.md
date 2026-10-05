# Module: phpMyAdmin
- **Step:** S8   **Status:** live (panel 0.38.0 / agent 0.33.0)

## Purpose
cPanel phpMyAdmin slice 1. Enabled flag in `~/etc/mysql/phpmyadmin.json`.
No phpMyAdmin install, no SSO. Hostile enabled fail closed.

## Agent
| type | payload |
|---|---|
| `db.phpmyadmin` | username, enabled (bool) |

## Permissions
databases.view / databases.manage — customer + reseller (cPanel tile, not WHM)
