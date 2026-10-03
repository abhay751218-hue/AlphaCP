# Module: Perform a DNS Cleanup
- **Step:** S9   **Status:** live (panel 0.50.0 / agent 0.43.0)

## Purpose
WHM DNS cleanup slice 1. Writes `/usr/local/alphacp/etc/dns/cleanup.json`.
No BIND rewrite. Hostile domain fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.cleanup` | domains=[fqdn] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
