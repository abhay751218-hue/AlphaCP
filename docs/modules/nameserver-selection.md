# Module: Nameserver Selection
- **Step:** S9   **Status:** live (panel 0.54.0 / agent 0.47.0)

## Purpose
WHM nameserver selection slice 1. Writes `/usr/local/alphacp/etc/dns/nameserver.json`.
No BIND rewrite. Hostile software/ns fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.nameserver` | software, ns1, ns2 |

Software allowlist: bind, nsd, powerdns, disabled.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
