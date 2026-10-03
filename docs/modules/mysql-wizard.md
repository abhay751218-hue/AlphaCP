# Module: MySQL Database Wizard
- **Step:** S8   **Status:** live (panel 0.37.0 / agent 0.32.0)

## Purpose
cPanel MySQL Database Wizard. Step-by-step database name (session confirm),
then reuses `db.set`. No mysql binary, no GRANT, no new paneld task.
Users / privileges later.

## Agent
| type | payload |
|---|---|
| `db.set` | username, databases=[{name}] (existing task) |

## Permissions
databases.view / databases.manage — customer + reseller (cPanel tile, not WHM)
