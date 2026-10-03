# Module: Remote MySQL
- **Step:** S8   **Status:** live (panel 0.39.0 / agent 0.34.0)

## Purpose
cPanel Remote MySQL slice 1. Access hosts (`%`, IPv4, FQDN) in
`~/etc/mysql/remote.json`. No mysql GRANT, no bind-address rewrite.
Hostile host fail closed.

## Agent
| type | payload |
|---|---|
| `db.remote` | username, hosts=[{host}] (empty clears JSON) |

## Permissions
databases.view / databases.manage — customer + reseller (cPanel tile, not WHM)
