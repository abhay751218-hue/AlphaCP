# Module: Park a Domain
- **Step:** S9   **Status:** live (panel 0.49.0 / agent 0.42.0)

## Purpose
WHM park-a-domain slice 1. Writes `/usr/local/alphacp/etc/dns/parked.json`.
No BIND rewrite. Hostile domain/target fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.park` | parks=[{domain,target}] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
