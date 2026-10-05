# Module: Synchronize DNS Records
- **Step:** S9   **Status:** live (panel 0.53.0 / agent 0.46.0)

## Purpose
WHM synchronize DNS records slice 1. Writes `/usr/local/alphacp/etc/dns/sync.json`.
No BIND rewrite. Hostile domain fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.sync` | domains=[fqdn] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
