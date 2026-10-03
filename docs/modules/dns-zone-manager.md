# Module: DNS Zone Manager
- **Step:** S9   **Status:** live (panel 0.44.0 / agent 0.37.0 reuse)

## Purpose
WHM DNS Zone Manager. List/add/delete account domains (parked add). Sync
reuses `dns.zone`. No BIND rewrite. Hostile filter fail closed.
Customer cPanel does not see this tile.

## Agent
| type | payload |
|---|---|
| `dns.zone` (reuse) | username, records — WHM sync enqueues the account JSON |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
