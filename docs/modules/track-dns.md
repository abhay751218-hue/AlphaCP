# Module: Track DNS
- **Step:** S9   **Status:** live (panel 0.42.0 / agent 0.37.0)

## Purpose
cPanel Track DNS slice 1. Search jailed `~/etc/dns/zone.json` and
`dynamic.json` by FQDN. No dig, no BIND rewrite. Hostile query fail closed.

## Agent
| type | payload |
|---|---|
| `dns.track` | username, query (FQDN), type A/MX/NS/TXT/CNAME/ALL (readonly) |

## Permissions
dns.view — customer + reseller (cPanel tile, not WHM)
