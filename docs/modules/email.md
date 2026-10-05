# Module: Email Accounts
- **Step:** S7   **Status:** live (panel 0.19.0 / agent 0.16.0)

## Purpose
cPanel Email Accounts slice 1. Virtual mailboxes under the account home
(`~/mail/<domain>/<local>` Maildir + `~/etc/mail/passwd` Dovecot passwd-file).
Bcrypt hashes only (`{BLF-CRYPT}`). Plaintext never reaches the agent.
Exim LMTP / Roundcube / forwarders later.

## Agent
| type | payload |
|---|---|
| `mail.set` | username, mailboxes=[{local, domain, hash, quota_mb}] (empty clears passwd) |

## Permissions
email.view / email.manage — customer + mail + reseller (cPanel tile, not WHM)
