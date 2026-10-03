# Module: Forwarders
- **Step:** S7   **Status:** live (panel 0.20.0 / agent 0.17.0)

## Purpose
cPanel Forwarders. Source address on an account domain → destination email.
Writes `~/etc/mail/aliases`. Pipe, shell, `:include:` dest fail closed.
Exim router later.

## Agent
| type | payload |
|---|---|
| `mail.forward` | username, forwards=[{local, domain, dest}] (empty clears aliases) |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
