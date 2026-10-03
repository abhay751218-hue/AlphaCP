# Module: Autoresponders
- **Step:** S7   **Status:** live (panel 0.21.0 / agent 0.18.0)

## Purpose
cPanel Autoresponders. Vacation auto-reply on an account domain.
Writes `~/etc/mail/autorespond` JSON. Pipe, shell, backticks fail closed.
Exim transport later.

## Agent
| type | payload |
|---|---|
| `mail.autorespond` | username, responders=[{local, domain, subject, body, interval_h}] (empty writes `[]`) |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
