# 10 — Server ko "Proper Live" + Project Complete karne ka A–Z Plan

**Banaya:** 7 Oct 2026 · **Base:** `server-snapshot/STATE.md` (sync `af47c8f`, 6 Oct 22:11Z) + sandbox-verified tests.
**Niyam (START-HERE §3):** ek message me **sirf EK command**. Output bhejo, phir agla step.
Untested command kabhi nahi. Har script version-banner print karti hai.

---

## 0. Abhi ki asli state (7 Oct ko verify kiya)

| Cheez | Value | Source |
|---|---|---|
| Panel code | **0.75.0** (`.env` ACP_VERSION 0.83.0) | STATE.md |
| Laravel / PHP | 13.33.0 / 8.4.26 | STATE.md |
| Panel HTTP | **200** | STATE.md |
| Migrations | **60** Ran | STATE.md |
| Web routes | **207** | STATE.md |
| Services | nginx, apache2, mariadb, php7.4–8.4-fpm, paneld, redis, fail2ban — sab **active** | STATE.md |
| License | `local_trial`, max 20 accounts, **expires 2026-10-13** (⚠️ 6 din) | STATE.md |
| Repo vs server | **barabar** (completeness khaali, sync v1.5 live) | sync run |
| Panel test suite | **453 pass, 0 fail** (asli code par) | sandbox |
| Parity checklist | 14 ✅ / 21 🟡 / 174 ⏳ — **STALE** ("Updated: Step 0,1,2A") | docs/09 |
| `license-server/` | sirf `README.md` | repo |

---

## 1. Jo is session me **diya** gaya (complete + verified)

1. **alphacp-sync v1.3→v1.5** — snapshot completeness bug fix. Ab `server-snapshot/` = server (byte-barabar).
   Server par live; `completeness: panel ki har source file snapshot me hai`.
2. **Repo me asli 4 missing files** wapas aayi (backup-destinations/transfer-tool views, SshTest, TransferToolTest).
3. **Poora test harness** — `tools/sim/panel-tests-deployed.sh` (453 pass) + `sync-sim` (72/72).
4. **Docs theek** — START-HERE §2b (source-of-truth), §4c (refresh step), COMMANDS, CHANGELOG.
5. **PR #8** merge-ready (sirf sync-fix + tools + docs).

## 2. Jo **reh gaya** hai

| # | Kaam | Priority | Note |
|---|---|---|---|
| R1 | **License/trial** — 13 Oct ko trial khatam | 🔴 URGENT | Ya trial extend karo, ya `license-server/` API (Step 2C) banao |
| R2 | **Proper live** — domain + Let's Encrypt TLS (abhi IP:8090 self-signed) | 🔴 | Domain chahiye (user input) |
| R3 | **Parity checklist reconcile** (174 pending → server se milao) | 🟡 | Docs kaam, sandbox me ho sakta hai |
| R4 | Steps 11–15: monitoring, WHM-API billing, WAF, app-installer, reseller | 🟢 | Roadmap ka bacha hua |
| R5 | Admin 2FA + password policy enforce (production hardening) | 🟡 | |

---

## 3. A–Z Steps (ek baar me EK chalao, output bhejo)

### Phase A — Live health confirm (aaj)
- **A1.** Panel health:
  ```bash
  for u in / /up /login; do curl -sk -o /dev/null -w "https://127.0.0.1:8090$u -> %{http_code}\n" "https://127.0.0.1:8090$u"; done
  ```
  Expected: `/`→302(login), `/up`→200, `/login`→200. Output bhejo.

### Phase B — License (13 Oct se pehle)
- **B1.** Trial status dekho: `sudo cat /usr/local/alphacp/panel/storage/app/private/license.json | python3 -m json.tool | grep expires`
- **B2.** (Decision) Trial extend karna ho to batao — ya `license-server/` API banani hai (main code likhunga, sandbox-test karunga, phir deploy command dunga).

### Phase C — Proper live (domain + TLS)
- **C1.** Domain ka A-record `13.207.123.177` par lagao (aapke DNS provider me). Domain naam batao.
- **C2.** Phir panel ko 443 par Let's Encrypt: main nginx vhost + certbot command dunga (server par test karke).

### Phase D — Parity reconcile + aage ke steps
- **D1.** `docs/09` ko server se milana (main karunga, verify karke).
- **D2.** Steps 11–15 ek-ek karke (roadmap).

---

## 4. Abhi turant: **sirf A1 chalao**, output bhejo.

Uske baad B (license) sabse zaroori hai kyunki 13 Oct aa raha hai.
