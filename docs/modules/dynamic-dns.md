# Module: Dynamic DNS
- **Step:** S9   **Status:** live (panel 0.41.0 / agent 0.36.0)

## Purpose
cPanel Dynamic DNS slice 1. Hosts + tokens in `~/etc/dns/dynamic.json`.
No BIND/named rewrite. No public updater this slice. Hostile name/IP fail closed.
Domain must belong to the account. Token is generated (32 hex), never user-supplied.

## Agent
| type | payload |
|---|---|
| `dns.dynamic` | username, hosts=[{domain,name,token,ip}] (empty clears JSON) |

## Permissions
dns.view / dns.manage — customer + reseller (cPanel tile, not WHM)
