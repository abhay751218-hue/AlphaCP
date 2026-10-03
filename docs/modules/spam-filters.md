# Module: Spam Filters
- **Step:** S7   **Status:** live (panel 0.25.0 / agent 0.22.0)

## Purpose
cPanel Spam Filters slice 1. Required score (1–10) plus blacklist/whitelist emails.
Writes `~/etc/mail/spam.json`. Does **not** start SpamAssassin. Pipe dest fail closed.

## Agent
| type | payload |
|---|---|
| `mail.spam` | username, required_score, blacklist=[], whitelist=[] |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
