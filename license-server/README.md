# license-server/ — AlphaCP License Server (Commercial)

**Stack:** Laravel 11 + MariaDB (separate small server, e.g. `license.alphacp.in`)
**Status:** ⏳ Not implemented yet — full build at **Step 15**; minimal activation API needed by Step 2.
**Design doc:** `../docs/05-license-system.md` (authoritative)

## Purpose
Central service that sells & manages AlphaCP licenses (cPanel-style business model):
issue keys, activate servers, heartbeat, renewals, transfers, revocations, reseller/OEM accounts.

## Public API (v1)
| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/trial` | Auto-issue 15-day trial |
| POST | `/api/v1/activate` | Activate license on a server (key + fingerprint) |
| POST | `/api/v1/heartbeat` | Daily check-in (accounts count, version) |
| POST | `/api/v1/transfer` | Move license to new hardware (rate-limited) |
| GET | `/api/v1/revocation-list` | Cached revocation list |
| GET | `/api/v1/license/{uid}` | Customer portal info |

## Admin UI
Customers · Licenses · Activations · Heartbeats · Plans/Tiers · Coupons · Resellers/OEM ·
Revocations · Reports (MRR, churn, health) · Audit log · Signing-key rotation.

## Cryptography
- Ed25519 keypair. Private key: encrypted at rest + passphrase from env, NEVER in Git.
- Public key embedded in panel (`panel/config/license_public.pem`); rotation procedure documented.
- License payload canonical JSON (see design doc §3) — verifiable offline by the panel.

## Golden rule (non-negotiable)
License failure **never** disables customer websites/email — only degrades the panel
(no new accounts, read-only admin after grace). See design doc §6.
