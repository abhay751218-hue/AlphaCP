# Module: Setup/Edit Domain Forwarding
- **Step:** S9   **Status:** live (panel 0.52.0 / agent 0.45.0)

## Purpose
WHM domain forwarding slice 1. Writes `/usr/local/alphacp/etc/dns/forward.json`.
No BIND rewrite. Hostile domain/URL fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.forward` | forwards=[{domain,url,code}] (empty clears JSON) |

URL: `http(s)://host[/path]`. Code 301 or 302.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
