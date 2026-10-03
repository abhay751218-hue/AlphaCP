# Module: Apache Handlers
- **Step:** S5   **Status:** live (panel 0.14.0 / agent 0.11.0)

## Purpose
cPanel Apache Handlers. Account-level `AddHandler` for allowlisted handlers
(`cgi-script`, `server-parsed`, `imap-file`, `type-map`, `default-handler`,
`send-as-is`). paneld writes `~/etc/handlers.conf`. PHP/proxy/fcgi fail closed.

## Agent
| type | payload |
|---|---|
| `handlers.set` | username, mappings=[{handler, ext}, …] (50 max; empty clears) |

## Permissions
handlers.view / handlers.manage — customer + reseller (cPanel tile, not WHM)
