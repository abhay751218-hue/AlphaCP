# Module: Email Routing Configuration (global)
- **Step:** S9   **Status:** live (panel 0.47.0 / agent 0.40.0)

## Purpose
WHM global email routing slice 1. Writes `/usr/local/alphacp/etc/mail/global-routing.json`.
No Exim rewrite. Hostile domain/mode fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `mail.globalrouting` | routes=[{domain,mode}] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
