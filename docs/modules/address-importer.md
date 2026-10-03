# Module: Address Importer
- **Step:** S7   **Status:** live (panel 0.30.0 / agent 0.26.0)

## Purpose
cPanel Address Importer slice 1. Paste CSV of mailboxes. Reuses `mail.set`.
Pipe/shell/foreign domain fail closed. Plaintext password never queued (bcrypt only).

## CSV
`local,domain,password[,quota_mb]` or `email,password`

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)
