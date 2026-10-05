# Module: Encryption
- **Step:** S7   **Status:** live (panel 0.31.0 / agent 0.27.0)

## Purpose
cPanel Encryption slice 1. GnuPG identity rows (local@domain + comment).
Writes `~/etc/mail/encrypt.json`. Does **not** run gpg. No private key. Pipe comment fail closed.

## Agent
| type | payload |
|---|---|
| `mail.encrypt` | username, keys=[{local, domain, comment}] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
