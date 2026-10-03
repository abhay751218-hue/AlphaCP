# Module: Zone Editor
- **Step:** S9   **Status:** live (panel 0.40.0 / agent 0.35.0)

## Purpose
cPanel Zone Editor slice 1. A/CNAME/MX/TXT rows in `~/etc/dns/zone.json`.
No BIND/named rewrite. Hostile name/value fail closed. Domain must belong
to the account.

## Agent
| type | payload |
|---|---|
| `dns.zone` | username, records=[{domain,name,type,value}] (empty clears JSON) |

## Permissions
dns.view / dns.manage — customer + reseller (cPanel tile, not WHM)
