# 05 — License System (Commercial) + License Server

> **Hindi summary:** Ye wahi system hai jo aapko paisa kamane dega — jaise cPanel apni license
> bechta hai. Panel ko license key chahiye hogi, aur aap ek **License Server** chalaoge jo keys
> issue karega, verify karega, aur renewals handle karega. Sabse important rule: **license ki
> wajah se customer ki website/email kabhi band nahi hogi** — sirf panel limit hoga.

**Status:** ✅ v1 design
**Components:** Panel license client (in `panel/`) + License Server app (`license-server/`)

---

## 1. Business Models This Supports

| Model | Example | Who buys |
|---|---|---|
| **A. Self-use** | Aap apna panel apne servers pe chalate ho | Aap (owner license, free) |
| **B. Sell panel licenses** | Hosting company apne server pe aapka panel chalaye | Other hosts (like cPanel model) ✅ main revenue |
| **C. Reseller/OEM** | Koi company apne brand pe aapka panel beche | Partners (volume pricing) |
| **D. Multi-server packs** | Central + N nodes | Bigger providers |

## 2. Tiers (draft — final pricing aap decide karoge)

| Tier | Servers | Max accounts | Features | Notes |
|---|---|---|---|---|
| **Trial** | 1 | 20 | Full | 15 days, no key needed (auto-issued), watermark-free |
| **Starter** | 1 | 50 | Full v1 | |
| **Business** | 1 | 250 | Full v1 | |
| **Unlimited** | 1 | unlimited | Full v1 | |
| **Node add-on** | +1 node | inherits central | Multi-server | Per-node license |
| **OEM/Reseller** | 10+ | custom | White-label + API | Partner contract |

Feature flags in the signed payload (`features`) allow selling future add-ons
(e.g. `wordpress_toolkit_pro`, `premium_malware_db`) without shipping new license code.

## 3. License Key Format & Cryptography

- Key format: `ACPP-XXXX-XXXX-XXXX-XXXX` (Crockford base32, last group = checksum).
  Human-copyable, case-insensitive. `ACPP` = AlphaCP Panel.
- **Signing:** Ed25519 keypair.
  - Private key lives ONLY on the license server (KMS/HSM or encrypted file + passphrase).
  - Public key is embedded in the panel (`panel/config/license_public.pem`) and can be
    rotated via a release (support both keys for overlap window).
- **License payload (canonical JSON, JCS)** — what gets signed:
```json
{
  "license_uid": "L-2026-0001AB",
  "product": "alphacp",
  "tier": "business",
  "customer": "Acme Hosting",
  "features": ["core", "email", "dns", "backup", "installer"],
  "max_accounts": 250,
  "max_servers": 1,
  "issued_at": "2026-09-28T00:00:00Z",
  "expires_at": "2027-09-28T00:00:00Z",
  "grace_days": 14,
  "bindings": ["fingerprint"],     // fingerprint required at activation; multiple allowed
  "revocation_url": "https://license.alphacp.in/api/v1/revocation-list"
}
```
- Signature (base64 Ed25519) delivered alongside. Panel stores payload + signature and can
  verify **offline** at any time (important: internet down ≠ license down).

## 4. Activation & Heartbeat Flow

```
 First run / admin clicks "Activate"
 ┌──────────┐   POST /api/v1/activate            ┌──────────────────┐
 │  PANEL   │ ─────────────────────────────────► │ LICENSE SERVER   │
 │  client  │   {license_key, fingerprint,        │ (Laravel app)    │
 │          │    hostname, version, ip}          │                  │
 │          │ ◄───────────────────────────────── │ verifies:        │
 └──────────┘   signed payload + signature       │ • key valid      │
      │                                          │ • not revoked    │
      │ store in DB + /usr/local/alphacp/etc/    │ • not expired    │
      │ license.json (0600); verify signature    │ • binding rules  │
      ▼                                          │ • max activations│
  License active ✅                                └────────┬─────────┘
                                                            ▼
                                                    records activation

 Daily heartbeat: POST /api/v1/heartbeat {fingerprint, accounts_count, version}
   → server can extend expiry (renewal), or mark revoked/expired
   → panel refreshes payload if returned

 Offline behavior:
   • Heartbeat fail → status stays `active` until `grace_until`
   • After grace → status `grace` (panel degrades; see §6)
   • Signed payload expiry also respected offline (no infinite offline use)
```

**Endpoints (license server):**

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/activate` | First activation (key + fingerprint) |
| POST | `/api/v1/heartbeat` | Daily check-in |
| GET | `/api/v1/revocation-list` | Compact revocation list (cached) |
| POST | `/api/v1/transfer` | Move license to new server (rate-limited) |
| GET | `/api/v1/license/{uid}` | License info (customer portal) |
| POST | `/api/v1/trial` | Auto-issue trial (rate-limited by email/IP/host) |

## 5. Machine Fingerprint

- `fingerprint = HMAC-SHA256(hardware_id, app_secret)` where `hardware_id` combines:
  `/etc/machine-id` + primary MAC (filtered) + CPU model + root disk serial.
- Hostname/IP are **not** part of the fingerprint (they change legitimately). They are stored
  at activation for support purposes only.
- Fingerprint changes (hardware migration) → panel flags `license.fingerprint_mismatch`,
  admin sees a clear UI to request a **transfer** (self-service, limited to e.g. 2/30 days).

## 6. Failure Modes — THE GOLDEN RULE

> **Customer websites and email NEVER stop because of license state. Ever.**

Degradation ladder (panel-side only):

| Level | Trigger | What happens |
|---|---|---|
| Normal | active | Everything works |
| Notice | expiry in ≤ 15 days | Yellow banner in admin, email to owner |
| Grace | heartbeat failed > grace_days | Red banner; **no new account creation**; existing features work; update system warns |
| Locked | grace exhausted / revoked / invalid | Admin panel: read-only + billing/license screens only. Client panel shows renewal notice. **Websites, mail, DNS, backups keep running.** |
| (Never) | — | We never delete data, never suspend customer accounts, never break sites |

Rationale: our customers' customers are innocent — punishing them kills the vendor's reputation.
This is also a competitive differentiator vs aggressive licensing.

## 7. Multi-Server Licensing

- Central panel needs a license (with `max_servers` ≥ 2 for nodes).
- Each **node license**: half-price "Node" tier, same key mechanism, fingerprint of node.
- Central panel verifies node licenses locally (node stores its own license file) and reports
  counts to license server via central heartbeat.
- Node without valid license → node stops accepting **new** accounts; existing accounts run.

## 8. License Server App (`license-server/`)

Standalone Laravel app (deploy on its own small VPS, e.g. `license.alphacp.in`).

| Area | Contents |
|---|---|
| Public API | §4 endpoints (+ idempotency keys, rate limits, HMAC for partner APIs) |
| Admin UI | customers, licenses, activations, heartbeats, revocations, resellers, plans, coupons, audit |
| Billing integration | works with your billing software (webhook: payment → auto-issue/renew). v1 = manual issue + API |
| Emails | activation, renewal reminders (30/15/7/3/1 days), failed heartbeat, revocation notices |
| Reports | MRR, active licenses, top resellers, churn, heartbeat health |
| Security | 2FA for admins, IP allowlist for issue endpoints, append-only audit, encrypted private key |
| Self-service portal (v1.1) | customer login → view/download license, request transfer, renew |

Tables: see `docs/02-database-schema.sql` §11 (ls_* tables).
Private signing key: encrypted + passphrase from env; documented key-rotation procedure.

## 9. Anti-Abuse — Pragmatic, Not Hostile

| Measure | Detail |
|---|---|
| Signed payloads | Panel can't forge licenses (public-key verify, no online check needed) |
| Revocation list | Short list, cached 24h, checked at activate/heartbeat |
| Activation limits | e.g. 1 active fingerprint per single-server license; transfers rate-limited |
| Trial abuse | 1 trial per email + per fingerprint + per IP range; phone/email verification optional |
| Update channel auth | Updates require valid license (opt-in; open source? no — commercial) |
| Obfuscation | Optional layer (e.g. IonCube/SourceGuardian) for release builds — decision deferred; never rely on it alone |
| Watermark | None — honest customers get clean product |

**Ethics rule:** no hidden phone-home beyond heartbeat + revocation; panel must disclose in docs
what data is sent (hostname, version, account count, fingerprint hash). Owner must show this in
Terms of Service.

## 10. What the Panel Sends (privacy disclosure — must be in ToS)

```
activate/heartbeat payload:  license_key, fingerprint(hash), hostname, panel_version,
                             accounts_count, country_code (from IP at server side only)
NOT sent:                    customer data, emails, file contents, account names, domains
```

## 11. Implementation Plan (maps to roadmap)

| Step | Work |
|---|---|
| S2 | License client: fingerprint, local store, offline verify, trial issue, admin UI card |
| S2 | Heartbeat scheduler + status states + banners |
| S12 | Usage reporting with heartbeat (accounts count) |
| S15 | Multi-server node licenses + transfer flow + license server app (full) |

## 12. Testing Checklist (v1)

- [ ] Forged payload (bad signature) → status `invalid`, panel locked, customer services OK
- [ ] Expired offline payload → grace → locked timeline behaves per §6
- [ ] Fingerprint mismatch → transfer flow works, rate limit enforced
- [ ] Revoked key → next heartbeat locks panel, sites stay up
- [ ] Clock tampering (set date back) → detected via monotonic marker file + heartbeat
- [ ] Trial limits: second trial from same fingerprint refused
- [ ] License server down for 30 days → grace honored, then locked, nothing breaks for customers
