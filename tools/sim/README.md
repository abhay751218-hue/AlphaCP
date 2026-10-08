# tools/sim — panel-doctor ka local simulation test

Server (Ubuntu 24.04 + Ondrej php8.4-fpm) jaisi **asli** condition bana kar doctor test karta hai:

* `ProtectSystem=full` ko **sach me** simulate kiya jata hai — har panel request ek alag
  mount-namespace me chalti hai jahan `/usr` read-only bind-mount hai (bilkul wahi jo systemd
  karta hai). Drop-in (`ReadWritePaths=-/usr/local/alphacp`) + `daemon-reload` ke baad
  `/usr/local/alphacp` rw bind hota hai — jaise systemd karta hai.
* Panel = asli `panel-bundle-0.3.0` (Laravel 13.33.0, vendor ke saath), asli migrations +
  seeders, asli `alphacp:admin-password` command. PHP 8.4 = php-wasm (`@php-wasm/cli`).
* Sirf `systemctl` aur `curl` stub hain (sandbox me systemd/nginx nahi chalta).

Chalana (sudo chahiye, sirf throwaway sandbox/container me — `/usr/local/alphacp` banata hai):

```bash
sudo bash tools/sim/doctor-sim.sh            # scenario A + B + C
```

## login-entry-sim.sh — `installer/login-fix.sh` v1.0

Login tootne wali poori chain sandbox me banata hai: **live server ka exact panel code**
(`server-snapshot/files/usr/local/alphacp/panel`, v0.75.0) + asli `vendor/` (bundle se) +
migrated SQLite DB + 2 seeded users (jaan-boojh kar `locked_until` ke saath). PHP = php-wasm,
nginx/ss/systemctl/ufw/curl stub hain. `/usr/bin/php8.4` ka stub banta hai — isliye **sirf
throwaway sandbox me** chalao.

```bash
sudo bash tools/sim/login-entry-sim.sh       # P0..P9  -> 53/53
```

| Phase | Kya |
|---|---|
| P0 | build drift — `login-fix.sh` payload ke saath byte-for-byte sync me (`tools/build-login-fix.py --check`) |
| P1 | `acp-entry-ports`: nginx ke ASLI listen ports hi truth file me jaayein (6 scenario, B1 samet) |
| P2 | `--diagnose` read-only hai — code bilkul nahi badalta |
| P3 | full run: 4 PHP/route fix + truth file + cron + unlock + SELFTEST (B5 recursion check samet) + live login probe. Ownership layout LIVE jaisi (code root, storage alphacp, pool conf alphacp) taaki `PANEL_USER` detection ka imtihaan ho |
| P4 | idempotent — dobara chalane par "skip", routes dobara nahi judte |
| P5 | `--enable-ports`: vhost me 2083/2087/2096, `nginx -t` fail par auto-rollback |
| P6 | `--rollback` — backup se purani files wapas |
| P7 | PHPUnit: `EntryLoginTest` 10/10, `AuthTest` 8/8 (`ports.json` me 2083 maujood hone par bhi) |
| P8 | **BUG PROOF**: purana v1 `EntryLoginController` + `ports.json(2083)` → `AuthTest` FAIL hona chahiye |
| P9 | **BUG PROOF (B5)**: purana v1 `ResellerScopeProvider` → `SessionAuthTest` par PHP fatal (recursion); v2 → 12/12 OK; `AuthorizationTest` bhi OK |

P8/P9 isliye zaroori hain: agar kabhi fixture itna weak ho jaye ki purana (toota) code bhi
"pass" karne lage, to sim khud FAIL hokar bata dega.

Logs: `/tmp/acp-loginfix-sim/{run1..run4,p7a,p7b,p8,p9-v1,p9-v2,p9-authz}.log`.

## ftp-fix-sim.sh — `installer/ftp-fix.sh` v1.0 (B1 part 1: FTP via root agent)

Fake `ACP_HOME` me **PRE-FTP live state** banata hai (`git show 35cd630^` se: agent registry 80
types, `src/Ftp.php` absent, allowlist me `pure-pw` nahi, panel `Support/Ftp` me `Process::` ×4) —
yani wahi bug jo live par HTTP 500 deta tha. Phir poora lifecycle prove karta hai:
reproduce → `--diagnose` (read-only) → `apply` (backup, 9 files lint, static smoke, agent suite
gate `passed>=215 failed=0`, registry 83, panel `Process::` 0 / `enqueue` 3, embedded payloads
byte-for-byte match) → `--diagnose` post → `--rollback` (80 types + Process-wala panel wapas) →
re-apply (idempotent).

```bash
bash tools/sim/ftp-fix-sim.sh                # -> 40/40   (PHPBIN=/path/to/php override)
```

Note: sim `unset PHP` karta hai aur apna binary `PHPBIN` me rakhta hai — php-wasm `PHP` env ko
version maanta hai (warna har php call chup-chaap fail hota hai).

## b1-fix-sim.sh — `installer/b1-fix.sh` v1.0 (Git/Terminal/Apps via root agent)

Live ki maujooda state (`047d974`: registry 83 types + Process-wale Git/Terminal/Apps
controllers) fake ACP_HOME me reproduce karta hai, phir poora lifecycle: reproduce →
`--diagnose` → apply (lint 10+4, smoke, suite gate `passed>=218 failed=0`, registry 89,
panel code me Process:: 0, byte-for-byte payloads) → diagnose post → rollback → re-apply.

```bash
bash tools/sim/b1-fix-sim.sh               # -> 40/40
```

## sec-fix-sim.sh — `installer/sec-fix.sh` v1.0 (Security suite via root agent)

Live state `b97d76f` (89 types + Process-wali Support/Firewall+Waf) reproduce karke
poora lifecycle: reproduce → diagnose → apply (lint 9+2, smoke, suite gate
`passed>=220 failed=0`, registry 95, Support files Process-free, byte-for-byte
payloads) → diagnose post → rollback → re-apply.

```bash
bash tools/sim/sec-fix-sim.sh              # -> 40/40
```

## b2-fix-sim.sh — `installer/b2-fix.sh` v1.0 (Metrics via root agent)

PRE state = `935a3e3`-era (95 types, panel parser `Support/Metrics::parse` web-FPM se)
reproduce karke poora lifecycle: reproduce → diagnose PRESENT → dry-run (no mutation) →
apply (lint 4+2, smoke, suite gate `passed>=221 failed=0` — pdo_sqlite absent par
skip-note path bhi tested, registry 96, Support `function parse`=0, controller
`Paneld::run`) → diagnose ABSENT/PRESENT post → rollback (b2fix-* backup se exact bytes) →
re-apply. Payload pins `715a9e2` era, byte-for-byte.

```bash
bash tools/sim/b2-fix-sim.sh               # -> 40/40
```

## b3-fix-sim.sh — `installer/b3-fix.sh` v1.0 (WebDisk/WebDAV via root agent)

PRE state = `2e9bc49`-era (96 types, DB-only WebDiskController, bina password view)
reproduce karke poora lifecycle: reproduce → diagnose PRESENT/MISSING → apply
(lint 7+2, smoke, suite gate `passed>=222 failed=0`, registry 99, controller
Paneld::run, view password field, routes `webdisk/{login}`) → diagnose post →
rollback (96 types + DB-only controller wapas) → re-apply. Payload pins `ee6b659`
era, byte-for-byte (tasks.php + controller + blade).

```bash
bash tools/sim/b3-fix-sim.sh               # -> 41/41
```

## suite-enable-sim.sh — `installer/suite-enable.sh` v1.0 (pdo_sqlite + live suite)

Sandbox PHP me pdo_sqlite pehle se hai → idempotent skip-path + full suite gate
(222/0) + diagnose + negative path (stub suite `failed: 3` → installer exit 1).

```bash
bash tools/sim/suite-enable-sim.sh         # -> 8/8
```

## Installer suite-gate pattern (pipefall silent-death se bacho)

`set -Eeuo pipefail` me `sum="$(php suite | grep … | tail -1)"` jaisa assignment
suite fatal/no-summary par **bina message ke** script maar deta hai (live par
suite-enable v1.0 ke saath hua). Sahi pattern (suite-enable v1.1 se): output
pehle file me lo, `if ! php … >file; then tail dikhao + die`, phir
`sum="$(grep … file | tail -1 || true)"`. Naye installers me yahi use karo.

## ui1-fix-sim.sh — `installer/ui1-fix.sh` v1.0 (Paper-Lantern theme + customer dash)

PRE = `e2e2d6a`-era panel (dark theme, koi search/sidebar nahi) reproduce karke:
diagnose → apply (structural asserts: navy token / search box / dash-cols /
General Information / sections partial; payloads era `24baf55` byte-for-byte) →
diagnose post → rollback (purana theme wapas) → re-apply.

```bash
bash tools/sim/ui1-fix-sim.sh              # -> 29/29
```

## tests-sync-sim.sh — `installer/tests-sync.sh` v1.0 (SUPERSEDED by mail-fix; history)

PRE = `935a3e3`-era stale test files (live jaisi): count != 222 reproduce →
diagnose → apply (lint 2, suite 222/0, byte-for-byte era `6c00f97`) → diagnose
post → rollback (stale wapas) → re-apply.

```bash
bash tools/sim/tests-sync-sim.sh           # -> 17/17
```

## mail-fix-sim.sh — `installer/mail-fix.sh` v1.1 (live-parity fixes)

PRE = `3195ff6`-era (mail-fix v1.0 applied = live state: self-healing MailServer
lekin is_executable()-based installed() + uid<=0 Maildir skip + binsAbsent-less
tests). Marker-based reproduce (canChown/binsAbsent/probeBin = 0) → diagnose →
apply (lint 4, suite 222/0, 4 files byte-for-byte era `380ce91`) → **ROOT-RUN
suite green ka direct assert (sudo — live failure-mode ka sandbox proof)** →
diagnose post → rollback (v1.0-era wapas) → re-apply.

```bash
bash tools/sim/mail-fix-sim.sh             # -> 36/36
```

## ui2-fix-sim.sh — `installer/ui2-fix.sh` v1.0 (WHM left sidebar parity)

PRE = `4526624` (pre-P-UI-2 panel = live state: koi WHM sidebar nahi):
reproduce (markers 0 / partial MISSING) → diagnose → apply (lint + 6 structural
asserts + 4 files byte-for-byte era `0beb285`) → diagnose post → rollback
(partial delete samet) → re-apply.

```bash
bash tools/sim/ui2-fix-sim.sh              # -> 32/32
```

## ui3-fix-sim.sh — `installer/ui3-fix.sh` v1.0 (WHM sidebar Favorites)

PRE = `0beb285` (ui2-era live state: sidebar hai, Favorites nahi): reproduce
(stars/key/css-fav = 0) → diagnose → apply (4 asserts + 2 files byte-for-byte
era `8ee6f85`) → diagnose post → rollback → re-apply.

```bash
bash tools/sim/ui3-fix-sim.sh              # -> 26/26
```

## webmail-fix-sim.sh — `installer/webmail-fix.sh` v1.0 (Roundcube 2096 + SSO)

ACP_SIM=1 (apt/systemctl/nginx -t/curl/mysql skip; system paths env se fake).
PRE = `2dcfd2f`: reproduce (plugin/vhost/secrets/master-passdb = 0) → diagnose →
apply (NGX_VER=1.24 http2 asserts + 9 payloads byte-for-byte era `fdaf253` + suite 223/0 + vhost/config/
dovecot/internal-lock asserts) → diagnose post → rollback (sys files samet) →
re-apply.

```bash
bash tools/sim/webmail-fix-sim.sh          # -> 50/50
```
