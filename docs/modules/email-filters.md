# Module: Email Filters
- **Step:** S7   **Status:** live (panel 0.23.0 / agent 0.20.0)

## Purpose
cPanel Email Filters slice 1. Per-mailbox contains-match (from/subject/to).
Writes `~/etc/mail/filters` JSON. Actions: discard or Maildir folder.
Pipe, regex, shell fail closed. Exim filter language later.

## Agent
| type | payload |
|---|---|
| `mail.filter` | username, filters=[{local, domain, field, needle, action, folder}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
