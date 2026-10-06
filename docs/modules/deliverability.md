# Module: Email Deliverability
- **Step:** S7   **Status:** live + **server default** (panel 0.75.0 / agent 0.83.0)

## Purpose
cPanel Email Deliverability: apne account ke domains ke liye SPF + DMARC (+ DKIM) records.
Do raaste hain, dono ek hi code par jaate hain:

1. **Server default (0.83.0 se)** — customer ko kuch click karne ki zaroorat **nahi**:
   `mail.server sync` (aur har mail task ke baad wala auto-sync, `MailServer::syncIfConfigured()`)
   khud har mail domain ke liye likhta hai:
   - `@` TXT → `v=spf1 a mx -all` (constant),
   - `_dmarc` TXT → `v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:postmaster@<domain>`,
   - `default._domainkey` TXT → `v=DKIM1; k=rsa; p=<asli 2048-bit public key>`.
   Domain list `~/etc/mail/deliverability.json` + `~/etc/mail/passwd` ke mailboxes se aati hai —
   panel ka page khula ho ya na ho.
2. **Manual action** — panel ke Email Deliverability page se `mail.deliverability`
   (`deliverability` task → `MailProvisioner::enqueueDeliverability` → `AccountOs::setDeliverability`)
   sirf `deliverability.json` (recommended rows) likhta hai; records phir bhi sync likhta hai.

## DNS / Exim
- Records `~/etc/dns/zone.json` me merge hote hain (user ke apne TXT/A/CNAME records chhoote nahi),
  phir BIND live ho to `writeZone` + `dig` verify (S9).
- **Idempotent**: `deliverabilityUpToDate()` records compare karta hai — pehle se sahi hain to
  zone.json/BIND ko chhue bina skip (`changed=false`, `dns.reason=already-present`), isliye har mail
  task ke baad ka sync sasta hai. Naya DKIM key banega to records badalte hain aur reload hota hai.
- DKIM key: `<dkim-dir>/<domain>.key` **0640** group `Debian-exim`, `.pub` 0644
  (default `/usr/local/alphacp/etc/mail/dkim`, env `ACP_MAIL_DKIM_DIR`).
- Exim template (`renderEximTemplate`) me `dkim_domain = $sender_address_domain`,
  `dkim_selector = default`, `dkim_private_key = ${if exists{…}{…}{0}}` — `exim -bV` me DKIM support
  ho to hi signing lines aati hain (warna fail-closed, jhoothi "DKIM on" kabhi nahi).
- Galti (zone likhna fail / BIND error) **sync ko fail nahi karti** — `deliverability.ok=false` +
  per-domain `failed[]` me saaf error aata hai.

## Agent
| type | payload | result |
|---|---|---|
| `mail.deliverability` | username, domains=[fqdn, ...] | `ok, count, domains[], failed[]` |
| `mail.server sync` | — | `…, deliverability{ok,count,changed,domains[],failed[]}` |

## Permissions
email.view / email.manage — customer + mail (cPanel tile, not WHM)

## Tests / verification
- agent tests: `agent/tests/run-tests.php` — 212/0 (sync auto-apply, idempotent skip, DNS fail-safe).
- verifier: `tools/verify/s7-deliverability-default-check.sh` (15 checks — manual action ke bina
  sirf `mail.server sync` se records, key perms, zone `p=` = `.pub`, Exim signing, `exim -bV` valid).
- simulation: `tools/sim/s7-mail-sim.sh` — S7 mail SIM 27/0.
