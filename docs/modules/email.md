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

## SpamAssassin + greylistd (cPanel #147)

`mail.server` has an opt-in server-wide configuration action. Both protections are
**off by default** and are switched only through this action so their local daemons
and Exim ACLs stay in sync:

```bash
sudo /usr/local/alphacp/agent/bin/paneld --run mail.server '{"action":"spamassassin"}'
# SpamAssassin; optional settings: required_score (1.0–15.0), reject_score (0–30.0)
sudo /usr/local/alphacp/agent/bin/paneld --run mail.server '{"action":"spamassassin","enabled":true,"required_score":5.0,"reject_score":8.0}'
# greylistd; first-time remote sender/recipient triplets may receive SMTP 451
sudo /usr/local/alphacp/agent/bin/paneld --run mail.server '{"action":"spamassassin","greylisting":true}'
# disable independently
sudo /usr/local/alphacp/agent/bin/paneld --run mail.server '{"action":"spamassassin","enabled":false,"greylisting":false}'
```

- `required_score` updates only AlphaCP's marked block in `/etc/spamassassin/local.cf`;
  other administrator lines are preserved. `reject_score` is Exim's reject threshold;
  `0` means tag/header only, no score-based rejection. Exim adds `X-Spam-Score`.
- Spam scanning requires `exim4-daemon-heavy` (`Content_Scanning`) plus SpamAssassin.
  The updater ensures these packages; enabling rejects cleanly if they are unavailable.
  The `spamassassin` service is enabled only after opt-in. Exim uses `/defer_ok`, so
  a later `spamd` outage lets mail through unscanned rather than holding the queue.
- Greylisting uses the official greylistd `--grey` triplet query over its Unix socket.
  Only verified local recipients from unauthenticated, non-relay remote senders are
  checked; null senders (DSN/callout) and trusted relays bypass it. If greylistd goes
  down, the socket failure is fail-open. Disabling it validates/restarts Exim first,
  then stops/disables greylistd.
- `mail.server` status reports package/build support, service/socket health, configured
  state, and warnings. Generic `eximconf` cannot bypass the service-safe feature action.
