# CHANGELOG

All notable changes to AlphaCP are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: SemVer.

## [Unreleased]
### Added
- **WHM admin panel + Reseller panel (09 Oct)** — sabhi panels ab cPanel company-grade:
  naye `layouts/whm.blade.php` shell (dark charcoal sidebar, categorized WHM menu,
  sidebar search, WHM/RESELLER badge, orange #FF6C2C accents), `config/whm_menu.php`
  (admin = 12 sections / 49 items, reseller = 5 sections / 13 items — har item parity
  row + roadmap step ke saath), `AdminController` + `PanelAdmin`/`PanelReseller`
  middleware, routes `/admin` + `/reseller`. WHM home = Server information (hostname,
  OS, kernel, uptime, load — parity #182) + Accounts table (parity #105) + Service
  status (#171) + task queue + quick links + audit. accounts/packages tables Step 3/4
  me aati hain — `Schema::hasTable()` guard panel ko green rakhta hai. Client panel
  topbar se WHM/Reseller cross-links (real cPanel companies jaisa navigation).
  Demo me WHM + Reseller live mockups add. Gate: `tools/sim/theme-check.py` **42/42**.
- **Paper Lantern theme (09 Oct)** — panel UI ab cPanel company-grade look me hai:
  cPanel signature orange `#FF6C2C` accent, light canvas, thin orange left-edge stripe
  (Jupiter signature), top tool-search (press `/`), left sidebar nav with per-section
  colours, dashboard = Statistics cards + cPanel icon grid (9 sections · 82 tools, har
  tool ka apna inline SVG icon), right rail = General information + Services +
  Recent activity. 51 hand-drawn SVG stroke icons (`partials/icons.blade.php`) — no
  emoji, no icon font. Files: `panel/public/css/panel.css` (rewrite),
  `layouts/panel.blade.php`, `dashboard.blade.php`, `auth/login.blade.php`,
  `config/panel_modules.php` (+ per-section `color`, per-tile `icon`).
  Demo: `demo/cpanel-theme-demo.html` (self-contained; `tools/sim/build-theme-demo.py`
  se generate hota hai). No-error gate: `tools/sim/theme-check.py` **21/21 green**.
- **Private repo support (29 Sep)** — `alphacp-sync v1.2`: `sudo alphacp-sync get <commit> <path> <out> [sha256]`
  deploy key se file laata hai (raw.githubusercontent private repo par 404 deta hai). Squash-merge ke baad bhi
  PR refs se commit milta hai. sync-sim **60/60**. `panel-update 0.3.0`: artifact/sync-tool pehle `get` se,
  fallback public URL; update-sim **54/54**.
- **Deployed (29 Sep 00:17Z):** panel 0.3.2 + alphacp-sync v1.1 via updater 0.2.1 — HTTP 200, trial preserved.
- **alphacp-sync LIVE (29 Sep)** — server par setup hua, `main` par pehla snapshot `d5ae8d2` (314 files).
  Audit: koi secret nahi (sirf code; `.env`/keys/tokens/license.json nahi). Deployed panel = source 0.3.1 byte-for-byte.
- **alphacp-sync v1.1** — `releases/` (backup/failed panel copies) snapshot me nahi (sirf naam STATE.md me);
  STATE.md me panel MANIFEST version + license/trial state (source/tier/expiry; fingerprint nahi). sync-sim **51/51**.
- **panel-update 0.2.1** — 0.2.0 + alphacp-sync ko v1.1 par upgrade (sha-verified; fail ho to update phir bhi safal).
  update-sim **43/43**.
- **Panel 0.3.2 + panel-update 0.2.0 (29 Sep)**
  - **Bug fix:** `alphacp:admin-password <user>` (bina `--password`, lockout rescue ka tareeka) ~2.9% baar
    validation error se fail hota tha — random password me kabhi digit/capital/small letter nahi hota tha.
    Naya `App\Support\PasswordGenerator` hamesha teeno class deta hai (ambiguous 0/O/1/l/I nahi).
    `PasswordGeneratorTest` 2000 samples check karta hai. Installer `rand_pw` (step2b-finish/setup) me bhi yahi fix.
  - `panel-update.sh` 0.2.0: version/URL/SHA ek jagah, **commit-pinned** artifact URL (pehle mutable branch),
    `.env ACP_VERSION` update, SQLite-in-panel safety net (DB swap me saath jaye), sirf aakhri 3 backups,
    end me `alphacp-sync`.
  - `tools/build-panel-2b-bundle.py` ab `MANIFEST.json` version se file naam banata hai.
  - Tests: `tools/sim/panel-tests.sh` (PHPUnit via php-wasm; 0.3.1 = 39 pass, 0.3.2 = 42 pass, 0 fail,
    6 wasm-skip), `tools/sim/update-sim.sh` **37/37**.
- **Merge (29 Sep):** dusre AI ka S2C kaam (branch `arena/01a0ea0d-alphacp`: license client + 15-day trial,
  panel-update 0.1.0, panel 0.3.1) PR #1 me merge; uske unit/feature tests yahan chalaye — sab pass.
- **alphacp-sync v1.0 (29 Sep)** — `installer/alphacp-sync.sh`: server → GitHub auto-sync, taaki GitHub
  hamesha server jaisa rahe aur naya AI bina poochhe shuru kar sake.
  - GitHub deploy key (ed25519, host key pinned) + port 22 band ho to `ssh.github.com:443` fallback.
  - Snapshot `main` branch ke `server-snapshot/` me jata hai: panel/agent/license code, nginx/php-fpm/systemd
    configs, `STATE.md` (versions, services, ports, migrations, routes, artisan commands, license files),
    `db-schema.sql` (sirf structure), `MANIFEST.txt`.
  - **Secrets kabhi push nahi hote:** `.env`/`etc/`/`var/`/keys/certs copy hi nahi hote, server ke asli
    secret values har file me dhoondhe jaate hain (mile to file skip), private-key/token patterns skip,
    aur push se pehle ek final check hota hai. Secret mile to push ruk jata hai.
  - Timer har ghante chalta hai. Badlav na ho to commit nahi hota. Agar kisi aur ne push kiya ho to rebase karke push karta hai.
  - Test: `tools/sim/sync-sim.sh`, **45/45 PASS** (leak attempts, no-change, update, concurrent push,
    key-add wait flow, 443 fallback, timer-mode fast-fail).
- `START-HERE.md`: naye AI ke liye entry point. README/AGENTS/AI_CONTEXT me iska pointer hai.

### Fixed / Changed
- **panel-doctor.sh v1.7 (29 Sep)** — ab GitHub se (commit-pinned link, `COMMANDS.md` dekho), paste.rs nahi.
  - v1.6 ka poora fix-chain (systemd `ProtectSystem=full` → `ReadWritePaths` drop-in) same.
  - **Fix:** `ASK` (artisan helper) ab hamesha `${PANEL_ROOT}` se chalta hai — v1.6 me doctor
    `/root` ya `/home/ubuntu` se chalane par `config:clear/config:cache/route:cache/migrate`
    chup-chaap "Could not open input file: artisan" se fail hote the.
  - **Security (Step 5s):** admin password `AlphaCP@2026` public GitHub repo me likha tha; panel
    chalte hi koi bhi login kar sakta tha. Doctor ab check karta hai (DB hash se) — agar wahi hai to
    random password set karta hai (`--force-change`), `var/panel-admin.txt`, `.env`
    `ACP_ADMIN_PASSWORD`, `/root/.alphacp-admin-credentials` sync karta hai aur VERDICT me dikhata hai.
    User ne khud password badla ho to kuch nahi chhedta (re-run safe).
  - **Test:** `tools/sim/doctor-sim.sh` — asli panel bundle 0.3.0 + mount-namespace me asli
    read-only `/usr` (ProtectSystem=full jaisa). 3 scenario, **21/21 PASS**: 500 → drop-in → 200,
    password rotate + naya login OK + purana fail, re-run par password same, user ka password untouched.
- **S2C deployed on dev-srv1 (29 Sep)** — existing-server updater v0.1.0 completed successfully; panel
  `License & Trial` page now shows the local 15-day trial, fingerprint, expiry and activation form.
  Server header is now `dev-srv1`; websites/email remain unaffected.
- **Existing-server updater rollback fix (29 Sep)** — v0.1.0 updater ne release staging directory se
  `config:cache`/`route:cache` banaya tha, jiski absolute paths `/usr/local/alphacp/releases/...` par
  point ho rahi thi. PHP-FPM ka `open_basedir` sirf final `/usr/local/alphacp/panel` allow karta hai,
  isliye health check 500 hua. Updater ab staging me sirf preflight karta hai aur atomic swap ke baad
  final panel path se caches rebuild karta hai; fail hone par wahi automatic rollback rahega.
- **S2C license client started (29 Sep)** — active panel bundle now has an offline-first 15-day local trial,
  machine fingerprint, atomic `0600` local license store, Ed25519 canonical-payload verification,
  optional `/api/v1/activate` client, admin `/license` page, `license.view`/`license.manage` enforcement,
  and audit events. License failure only degrades the panel; customer websites/email/DNS/backups are untouched.
- **Shared panel header fix** — server name is now supplied through the layout composer, so User Manager,
  Audit and Security pages no longer show `server: unknown` just because their controllers do not pass the
  dashboard-only `server` variable.
- **Step 2B installer v0.3.8 (29 Sep)** — fresh installs now fetch the signed/checksummed
  Laravel 13 panel code-only artifact `artifacts/panel-code-0.3.1.tar.gz` from the repository,
  instead of stale paste chunks. The GitHub URL and SHA-256 can be overridden with
  `ACP_PANEL_BUNDLE_URL` / `ACP_PANEL_BUNDLE_SHA256` for a release mirror. The v0.3.7
  permanent php-fpm sandbox fix remains included.
- **Step 2B installer v0.3.7 (29 Sep)** — fresh installs now create the permanent
  `phpX.Y-fpm.service.d/alphacp-panel.conf` systemd drop-in before restarting PHP-FPM.
  It grants only `/usr/local/alphacp` and `/run/php` write access, preventing Ubuntu/Ondrej
  `ProtectSystem=full` from causing the panel's first web request to return HTTP 500.
  Source of truth: `installer/step2b-finish.sh`; generated single-shot installer:
  `installer/step2b-setup.sh`. Both pass `bash -n`.
- **Step 2B-1 INSTALLED ON dev-srv1 (29 Sep 00:02)** 🎉 — panel live: `https://13.207.123.177:8090`
  (Laravel 13.33.0, nginx+php-fpm, user `alphacp`), Apache sites untouched (HTTP 200 verified).
  Credentials: `admin` + password in `/root/.alphacp-admin-credentials` (first login par change forced).
  Installer jo chala: https://paste.rs/0r1Mi (v0.3.3).
- **panel-doctor.sh v1.6 (29 Sep)** — https://paste.rs/G72oK: **ASLI root cause mil gaya —
  systemd `ProtectSystem=full` php-fpm ke liye /usr (aur isliye /usr/local/alphacp) read-only
  kar deta hai → web par har request 500**, jabki CLI sab likh leta hai. v1.6 me: Step 2b
  sandbox audit + Step 2c nsenter write-test (proof) + Step 5a ReadWritePaths drop-in auto-fix.
  Container me server-jaisi condition bana kar PROVEN: 500 → doctor → 200 → PANEL READY.
- **panel-doctor.sh v1.5 (29 Sep)** — https://paste.rs/pnV7U: poori fix-chain (runtime saaf + perms →
  php-fpm audit: <8.3 pools disable / missing pool create → hit → CLI+HTTP asli error → auto-heal:
  DB 3-jagah sync, sessions migrate, LOG_CHANNEL=stderr fallback → cache rebuild → VERDICT).
  Start-me **v1.5 banner** + verdict version-tag (purani command se farq pata chale).
  500-state (stale 7.4 pool + galat DB password) par locally verify: heal → 200 → login ✅
- **panel-doctor.sh v1.4 (29 Sep)** — https://paste.rs/LxbJT: **root cause of the 500 = stale `php7.4` alphacp
  pool grabbing `/run/php/alphacp-fpm.sock`** (panel ko PHP 8.3+ chahiye). Doctor ab `/etc/php/*/fpm/pool.d/alphacp.conf`
  dhoondh kar <8.3 wale pools disable karta hai, unke fpm stop karta hai, 8.3+ fpm restart karke socket dobara
  banata hai, aur socket owner print karta hai. Locally fake 7.4 pool bana kar verify kiya → 200 ✅
- **panel-doctor.sh v1.3 (29 Sep)** — https://paste.rs/vbVD9: heal + php-fpm restart + 10× hit +
  CLI & HTTP-level asli error (debug temporarily ON → restore) + **auto-heal** (DB password teeno jagah sync /
  sessions table migrate). 3 scenario me verified: healthy → READY, root-owned logs → 200, DB mismatch → 200.
- **panel-doctor.sh v1.1 (29 Sep)** — https://paste.rs/wsPmr: permission heal + php-fpm restart + panel
  hit + (agar 500) Laravel ko `alphacp` user/php-fpm ini ke saath boot karke **asli exception** print +
  `laravel-*.log` & nginx tail + saaf VERDICT. Do scenarios me verify: root-owned logs (heal → 200) aur
  bad DB password (`Access denied for user 'alphacp'@'localhost'` sahi detect hua).
- **HTTP 500 fix (29 Sep, v0.3.6)** — https://paste.rs/EPW3b: saari artisan commands ab panel user (`alphacp`) ke
  roop me (`runuser`) chalti hain, isliye `storage/logs/laravel.log` root-owned nahi banta; aakhir me
  storage/bootstrap-cache ka chown + chmod (0770/0660). Locally 500-state reproduce karke verify kiya.
- **Single-shot installer (29 Sep, v0.3.5)** — https://paste.rs/vVdFC: `cd /` (getcwd spam band), /etc/hosts fix,
  root-check pehle, deterministic admin password, APP_KEY preserve, 60s verify + auto service restart,
  fail par poora diagnostics dump, aur aakhir me `VERDICT: PANEL READY ✅/❌` line.
- **Re-run safety (29 Sep, v0.3.4)** — installer https://paste.rs/VD0Px: APP_KEY aur admin password
  `/usr/local/alphacp/var/` me preserve hote hain (pehle re-run par `.env` wipe hone se password
  summary me chhup jata tha, aur 2FA secrets APP_KEY rotate hone se toot jate). `getcwd` noise fix.
- **Step 2B deploy path (28 Sep, working)** — panel ab `installer/step2b-setup.sh` (v0.3.3, https://paste.rs/0r1Mi)
  se install hota hai; `docs/runbooks/step-2b-panel-deploy.md` runbook me pura detail.
  - Panel = **Laravel 13.33.0** (v0.3.0 panel bundle, sha256 `c6712916…`): login, dashboard (live paneld
    data), **User Manager, RBAC middleware, 2FA (TOTP), audit log, security headers**, 9 PHPUnit test files.
  - Setup script: code chunks (3 × paste.rs) → checksum → extract (`--strip-components=1`) → composer →
    DB/grants → `panel.env` + `.env` → migrate/seed → `alphacp:admin-password` → php-fpm pool (user
    `alphacp`) → self-signed TLS + nginx vhost **:8090** → verify HTTP 200.
  - Re-run safe: admin password rotate **nahi** hota (chahiye to `ADMIN_PASSWORD=…` do).
  - Old `installer/panel-install.sh` (Laravel 11) **obsolete** — composer 2.10.3 security advisories
    ne Laravel 11 ke saare versions block kar diye ("could not be resolved to an installable set").
  - nginx 1.24 ke liye `http2 on;` auto-strip; nginx default site (port 80, Apache ka) auto-disable.
  - **PHP auto-detect (v0.3.3)**: `ACP_PHP_PRIMARY` par bharosa nahi — har installed PHP se
    `artisan --version` probe hota hai, extensions apt se bhar jaate hain, aur fail par asli artisan
    error print hota hai (pehle sirf "log dekho" tha). Verified: fake broken `php8.3` ke saath bhi
    script sahi PHP chunti hai aur install pass hota hai.

### Added
- **Step 2B-1 (Panel UI: login + dashboard on port 8090)** — `installer/panel-install.sh` v0.3.0:
  - `panel/` — Laravel 11 app (ADR-0001) served by nginx + php-fpm on **8090** (self-signed TLS
    for now), running as `www-data` — never root (ADR-0002). Apache keeps 80/443 for customer sites.
  - **Login** — session auth + CSRF, brute-force throttle (5 fails/15 min → 15 min block, all
    attempts recorded in `login_attempts`), audit rows for success/failure/logout.
  - **Dashboard** — live memory/disk/load, service table, task-queue card, module tiles for all
    9 cPanel sections, recent audit activity. Data comes from `paneld` via the task queue
    (`system.info`, `service.status` with `requested_src=panel`).
  - **Credentials rule** — the panel reads `/usr/local/alphacp/etc/database.env` at boot
    (`app/Support/AcpEnv.php`); it never keeps a second copy of the DB password.
  - `db/migrations/0002_panel_core.sql` — users, roles, permissions, role_permissions,
    user_permissions, login_attempts + seeded roles (superadmin/admin/reseller/support/user).
  - `php artisan alphacp:create-admin` — bootstrap admin creation (random password printed once).
  - Security headers middleware (CSP, X-Frame-Options, nosniff, Referrer-Policy).
  - `tools/build-panel-installer.py` — packs panel + migrations into a checksum-protected installer.
  - Installer: https://paste.rs/p4iDj (v0.3.0, script sha256 `47bbd854f34b2b6c…`, 69 KB) — full 7-phase run verified; payload builds are reproducible (gzip mtime=0).
  - Installer bugfixes found by testing: runtime dirs created before composer, full payload sync
    (dotfiles included), `.env` guard + 0640, and **`set -e` stays active inside `main()`** in both
    installers (silent-failure bug).

- **Step 1 (Base server stack)** — one-click installer v0.1.1 (`installer/install.sh`):
  Apache(mpm_event)+PHP-FPM 7.4–8.4, Nginx (installed, stopped), MariaDB (loopback-hardened),
  Redis (local-only), BIND9, mail/FTP packages (stopped by design), fail2ban, UFW full port map,
  disk quotas + 2 GB swap, Composer, Node 20, and the `alphacp` CLI v0.
  Re-run-safe (phases + state file), dry-run mode, verify report.
  - Fixes after install #1: nginx-vs-apache port-80 race (pre-clean + final health check),
    correct ext4 quota fstab options, MySQL loopback-only verify logic, ajax-free diagnostics.
- **Step 2A (paneld agent + task queue)** — `installer/step2-install.sh` v0.2.0:
  - `agent/` — root task agent `paneld` (PHP 8.3, zero dependencies, systemd `paneld.service`):
    allowlist registry (`config/tasks.php`), JSON-schema payload validation (hand-rolled, fails
    closed), array-exec only command runner with binary allowlist + timeouts, `PathGuard`
    (canonicalised roots, `..`/symlink escapes blocked), immutable audit rows, per-task logs,
    crash-safe stale-claim recovery, `SELECT ... FOR UPDATE SKIP LOCKED` claiming.
  - tasks: `agent.ping`, `system.info`, `service.status` (readonly).
  - `db/migrations/0001_core.sql` — servers, settings, system_events, tasks, task_logs,
    audit_logs, updates_history, schema_migrations.
  - `cli/alphacp` v0.2.0 — `status`, `doctor [--fix]`, `task types|run|list|show`,
    `agent status|selftest|restart|recover`, `logs`.
  - `agent/tests/run-tests.php` — 16 unit tests (JsonSchema, PathGuard, command allowlist,
    registry integrity), no DB required.
  - `tools/build-step2-installer.py` — packs agent+cli+db into a checksum-protected
    one-file installer; repo stays the source of truth.
- **Step 0 (Blueprint)** — complete design foundation:
  - `AI_CONTEXT.md` (session context for any AI/dev) + `AGENTS.md` (AI rules)
  - `docs/00-requirements-freeze.md` — v1 scope lock, personas, compat promises, limit keys
  - `docs/01-architecture.md` — system architecture, agent design, data flows
  - `docs/02-database-schema.sql` — 55-table design spec (step-tagged)
  - `docs/03-security-matrix.md` — RBAC matrix, task safety classes, audit rules
  - `docs/04-coding-standards.md` — PHP/TS rules, module discipline, AI collaboration rules
  - `docs/05-license-system.md` — commercial license system + license server design
  - `docs/06-installer-updater.md` — one-click install/update/rollback design
  - `docs/07-decision-log.md` — 10 ADRs (stack, agent, license, updater, module pattern)
  - `docs/08-module-blueprint.md` — mandatory module shape + extension checklists
  - `ROADMAP.md`, repo skeletons (`panel/`, `agent/`, `installer/`, `license-server/`)

## [0.0.0] — 2026-09-28
### Added
- Project inception: 16-step roadmap, master plan, dev server provisioned
  (AWS Lightsail `dev-srv1`, Ubuntu 24.04, 4 GB, Mumbai).
