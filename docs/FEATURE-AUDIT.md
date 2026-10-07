# FEATURE AUDIT — A→Z (7 Oct 2026)

> Base: live panel **0.75.0** + post-fix snapshot `e50f842` (server-snapshot), agent ke **80 task
> handlers**, full PHPUnit suite (php-wasm 8.5 + SQLite), live `laravel.log` / `STATE.md` /
> fpm pool config (`/etc/php/8.4/fpm/pool.d/alphacp.conf`).
>
> Method: (1) route→controller cross-check — **204 pairs, 0 missing method**;
> (2) panel ke queued task-types vs `agent/config/tasks.php` — **0 unknown handler**;
> (3) TODO/FIXME/"coming soon" grep — **0**; (4) poora suite; (5) live logs + PHP config.
> Matlab wiring apni jagah sahi hai — nichewale bugs *runtime/config* level ke hain.

## ✅ A. Sahi kaam kar raha hai (wired + tested)

| Area | Sab-module | Evidence |
|---|---|---|
| Auth/Login/2FA/entry-gate | login, 2FA loop-fix, `GET /login`, truth-based entry gate | `login-fix v1.0` live SELFTEST 16/0; AuthTest 8/8; SessionAuthTest 12/12 |
| WHM: accounts/packages/resellers/users/roles/perms | create/suspend/terminate/upgrade, quota, reseller scoping | suite green (AuthorizationTest, AccountsTest, ResellersTest…) |
| Domains & DNS | addon/park/sub/forward, zone editor, templates, TTL, cluster, sync, cleanup, nsreport, dynamic DNS, hostname-A | suite green; agent handlers `dns.*`, `domain.*` |
| Email (poora stack) | mailboxes, forwarders, autoresponders, filters, global filters, spam, boxtrapper, calendar, mailing lists, routing, deliverability, encryption, webmail, track-delivery | suite green; agent `mail.*` (exim/dovecot) |
| MySQL | DBs, users+grants, wizard, phpMyAdmin, remote mysql | suite green; agent `db.*` |
| Cron / Files / Trash / Disk | cron.set, files.list/set, trash, disk usage | suite green |
| Backups | config, destinations, wizard, restoration (full/file-dir), cpanel-import pull | queueing green (TransferRestore ka *test* niche B4) |
| SSL | issue/remove + AutoSSL toggle UI | agent `ssl.issue/ssl.remove` |
| PHP | MultiPHP selector + per-account php.ini | agent `php.setVersion` / `php.setIni` (Tasks/PhpSet*.php) |
| SSH / security tools | ssh keys, ip-blocker UI, sec-extras | agent `ssh.set` |
| License (sell-ready base) | client activate + **License Server**: issue / revoke / **public verify** (signed keys), ModuleCatalog gating | routes 561-594 + 683-694; LicenseSigner/LicenseClient |
| Monitoring/System | service.status, system.info, audit, api-tokens | suite green |

## 🔴 B. Production me TOOTA hua (evidence ke saath) — fix queue

### B1 · `proc_open` disabled, par 4 features web-FPM se `Process` chalate hain → **HTTP 500**
`alphacp.conf:26` → `disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec`.
Live `laravel.log` 05:41:18 → *"The Process class relies on proc_open…"* (userId=1).
- **FTP Accounts** — `Support/Ftp.php` → `pure-pw useradd/…` (server par pure-ftpd **active**, port 21)
- **Git Version Control** — `GitController` → `git clone/pull/status`
- **Terminal** — `TerminalController` → user command
- **Apps (WordPress installer)** — `Support/AppInstaller` → `curl`/`tar`

Sahi tareeka (cPanel jaisa): ye sab **agent-side** chale (root, host par) — agent me
`CommandRunner` + `PathGuard` pehle se hain. Naye task types: `ftp.*`, `git.*`,
`terminal.run`, `apps.install`; panel `Process` ki jagah queue kare.

### B2 · Metrics — `open_basedir` se blocked
`MetricsController` → `/var/log/apache2/{user}-access.log` **web FPM se** padhta hai;
open_basedir allow-list (`panel/:etc/:agent/config/:tmp/:backups/:incoming`) me `/var/log`
nahi → page production me khali/error. Fix: agent task `metrics.access` (parse agent-side)
ya agent-pushed metrics table.

### B3 · WebDisk — sirf DB rows, asli WebDAV provisioning nahi mila
`WebDiskController::store()` `WebDiskAccount` row likhta hai; koi agent task/config-step nahi.
Decision chahiye: agent se dav-config implement karo, ya module ko UI se hide karo.

### B4 · 6 failing tests = test-debt (production bug nahi, par license-grade quality gate ke liye fix)
- `DashboardShellTest` ×2 — `assertSee('WHM Dashboard')`, UI ab `Server Manager Dashboard`
- `DomainsTest` ×1 — `assertSee('customer cPanel')`
- `MysqlUsersTest` ×1 — test `mail` se logged-in hokar `root` user banata hai → provider ka
  `User::creating` guard 403 deta hai (**guard sahi hai**, test ka order galat)
- `TransferToolTest` / `TransferRestoreTest` ×1 each — page-content `assertSee` purana

### B5 · 6 wasm-skip tests — sandbox limit; server par ek baar `artisan test` chala kar record karo

## 🟡 C. Decisions pending (aap se)
1. **Entry separation live** karni hai? (`--enable-ports` — nginx 2083/2087/2096; opt-in)
2. **License-selling hardening**: per-key limits (max accounts/modules), offline grace period,
   revoke-propagation, white-label/branding, customer-facing docs — base maujood hai, depth audit agle phase me
3. Product/brand name jo license me dikhega

## 🗺️ D. Phased plan (har phase = ek verified increment, ek command/step per message)
| Phase | Kaam | Ship channel |
|---|---|---|
| 1 | **FTP via agent** (`ftp.*` tasks + handlers + panel queueing) — repro → fix → sim/tests | panel-update artifact |
| 2 | Git + Terminal + Apps via agent (`git.*`, `terminal.run`, `apps.install`) | panel-update artifact |
| 3 | Metrics via agent (`metrics.access`) | panel-update artifact |
| 4 | Test-debt → suite **100% green** | artifact (tests) |
| 5 | WebDisk: implement ya hide | artifact |
| 6 | License hardening + docs | artifact + docs |
| 7 | Entry separation opt-in run | `login-fix --enable-ports` |

Gate har phase par: `tools/sim/update-sim.sh` (54/54) + `tools/sim/panel-tests.sh` +
`tools/sim/login-entry-sim.sh` (53/53) — phir commit-pin + sha256 command.
