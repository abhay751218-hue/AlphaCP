# Module: Webmail
- **Step:** S7   **Status:** live (panel 0.35.0 / agent 0.31.0)

## Purpose
cPanel Webmail slice 1. Enabled flag + preferred client (`roundcube`/`horde`).
Writes `~/etc/mail/webmail.json`. Does **not** install Roundcube/Horde. No SSO. Hostile client fail closed.

## Agent
| type | payload |
|---|---|
| `mail.webmail` | username, enabled, client=roundcube|horde |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
