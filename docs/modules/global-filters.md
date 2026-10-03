# Module: Global Email Filters
- **Step:** S7   **Status:** live (panel 0.29.0 / agent 0.26.0)

## Purpose
cPanel Global Email Filters slice 1. Account-wide contains-match (from/subject/to → discard/folder).
Writes `~/etc/mail/global-filters.json`. No mailbox local. Pipe/regex/shell fail closed.

## Agent
| type | payload |
|---|---|
| `mail.gfilter` | username, filters=[{domain, field, needle, action, folder}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
