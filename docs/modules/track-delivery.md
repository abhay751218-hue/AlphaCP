# Module: Track Delivery
- **Step:** S7   **Status:** live (panel 0.28.0 / agent 0.25.0)

## Purpose
cPanel Track Delivery slice 1. Search recipient in `~/etc/mail/track.json`.
Does **not** read Exim mainlog. Pipe/shell query fail closed. Hostile rows skipped.

## Agent
| type | payload |
|---|---|
| `mail.track` | username, query (email) |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
