# Module: MIME Types
- **Step:** S5   **Status:** live (panel 0.13.0 / agent 0.10.0)

## Purpose
cPanel MIME Types. Account-level Apache `AddType` (Content-Type header only).
paneld writes `~/etc/mime.conf`. PHP/CGI/SSI extensions fail closed.

## Agent
| type | payload |
|---|---|
| `mime.set` | username, mappings=[{mime, ext}, …] (50 max; empty clears) |

## Permissions
mime.view / mime.manage — customer + reseller (cPanel tile, not WHM)
