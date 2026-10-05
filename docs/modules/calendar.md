# Module: Calendar
- **Step:** S7   **Status:** live (panel 0.33.0 / agent 0.29.0)

## Purpose
cPanel Calendar & Contacts slice 1. Calendar + contact name rows.
Writes `~/etc/mail/calendar.json`. Does **not** run CalDAV/CardDAV. Pipe name fail closed.

## Agent
| type | payload |
|---|---|
| `mail.calendar` | username, calendars=[{name}], contacts=[{name}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
