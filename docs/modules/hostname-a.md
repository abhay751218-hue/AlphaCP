# Module: Add an A Entry for Your Hostname
- **Step:** S9   **Status:** live (panel 0.45.0 / agent 0.38.0)

## Purpose
WHM hostname A slice 1. Writes `/usr/local/alphacp/etc/dns/hostname.json`.
No BIND rewrite. Hostile hostname/IP fail closed. Customer cPanel hides the tile.

## Agent
| type | payload |
|---|---|
| `dns.hostname` | hostname (FQDN), ip (IPv4) |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
