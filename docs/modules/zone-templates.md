# Module: Edit Zone Templates
- **Step:** S9   **Status:** live (panel 0.46.0 / agent 0.39.0)

## Purpose
WHM zone templates slice 1. Writes `/usr/local/alphacp/etc/dns/templates.json`.
No BIND rewrite. Hostile name/body fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.templates` | templates=[{name,body}] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
