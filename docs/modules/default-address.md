# Module: Default Address
- **Step:** S7   **Status:** live (panel 0.22.0 / agent 0.19.0)

## Purpose
cPanel Default Address. Unmatched mail on an account domain → destination email.
Writes `~/etc/mail/catchall` as `*@domain: dest@fqdn`. Pipe/shell dest fail closed.
`:fail:` / `:blackhole:` later. Exim router later.

## Agent
| type | payload |
|---|---|
| `mail.catchall` | username, catchalls=[{domain, dest}] (empty clears file) |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
