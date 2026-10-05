# Module: Email Routing
- **Step:** S7   **Status:** live (panel 0.27.0 / agent 0.24.0)

## Purpose
cPanel Email Routing slice 1. Per-domain mode: auto / local / backup / remote.
Writes `~/etc/mail/routing.json`. Does **not** rewrite Exim localdomains.
Hostile domain and unknown mode fail closed. No user-supplied MX/host/pipe.

## Agent
| type | payload |
|---|---|
| `mail.routing` | username, routes=[{domain, mode}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
