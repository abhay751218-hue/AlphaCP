# Module: BoxTrapper
- **Step:** S7   **Status:** live (panel 0.32.0 / agent 0.28.0)

## Purpose
cPanel BoxTrapper slice 1. Enabled flag + allowlist emails.
Writes `~/etc/mail/boxtrapper.json`. Does **not** send challenge mail. Pipe dest fail closed.

## Agent
| type | payload |
|---|---|
| `mail.boxtrapper` | username, enabled, allowlist=[email, ...] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
