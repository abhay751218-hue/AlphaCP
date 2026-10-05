# Module: MySQL Databases
- **Step:** S8   **Status:** live (panel 0.36.0 / agent 0.32.0)

## Purpose
cPanel MySQL Databases slice 1. Prefixed names under the account
(`username_<suffix>` in `~/etc/mysql/databases.json`). No mysql binary,
no GRANT, no phpMyAdmin. Users / privileges / remote hosts later.

## Agent
| type | payload |
|---|---|
| `db.set` | username, databases=[{name}] (empty clears JSON) |

## Permissions
databases.view / databases.manage — customer + reseller (cPanel tile, not WHM)
