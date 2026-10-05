# Module: Nameserver Record Report
- **Step:** S9   **Status:** live (panel 0.48.0 / agent 0.41.0)

## Purpose
WHM nameserver record report slice 1. Writes `/usr/local/alphacp/etc/dns/ns-report.json`.
No BIND rewrite. Hostile domain/nameserver fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.nsreport` | records=[{domain,nameserver}] (empty clears JSON) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
