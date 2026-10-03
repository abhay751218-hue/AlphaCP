# Module: Error Pages
- **Step:** S5   **Status:** live (panel 0.11.0 / agent 0.8.0)

## Purpose
cPanel Error Pages. Customer sets HTML for 400/401/403/404/500/503. paneld
writes `~/errorpages/{code}.html` + `~/etc/errorpages.conf` (Alias +
ErrorDocument). PHP tags and SSI are rejected.

## Agent
| type | payload |
|---|---|
| `errorpages.set` | username, pages{code: html} |

Vhosts IncludeOptional `~/etc/errorpages.conf`.

## Permissions
errorpages.view / errorpages.manage — customer + reseller (cPanel tile, not WHM)
