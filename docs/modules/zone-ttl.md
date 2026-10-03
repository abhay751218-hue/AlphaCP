# Module: Set Zone TTL
- **Step:** S9   **Status:** live (panel 0.51.0 / agent 0.44.0)

## Purpose
WHM set zone TTL slice 1. Writes `/usr/local/alphacp/etc/dns/ttl.json`.
No BIND rewrite. Hostile domain/TTL fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.ttl` | zones=[{domain,ttl}] (empty clears JSON) |

TTL allowlist: 60, 300, 3600, 14400, 86400.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
