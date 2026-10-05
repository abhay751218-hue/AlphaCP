# Module: Mailing Lists
- **Step:** S7   **Status:** live (panel 0.26.0 / agent 0.23.0)

## Purpose
cPanel Mailing Lists slice 1. List local@domain + owner email.
Writes `~/etc/mail/lists.json`. Does **not** run Mailman. Pipe owner fail closed.

## Agent
| type | payload |
|---|---|
| `mail.list` | username, lists=[{local, domain, owner}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
