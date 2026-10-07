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
