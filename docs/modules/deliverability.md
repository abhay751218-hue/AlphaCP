# Module: Email Deliverability
- **Step:** S7   **Status:** live (panel 0.24.0 / agent 0.21.0)

## Purpose
cPanel Email Deliverability slice 1. Recommended SPF + DMARC TXT for account domains.
Writes `~/etc/mail/deliverability.json`. Does **not** write DNS (Zone Editor S9).
DKIM keygen later. SPF/DMARC strings are constants (not user-supplied).

## Agent
| type | payload |
|---|---|
| `mail.deliverability` | username, domains=[fqdn, ...] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
