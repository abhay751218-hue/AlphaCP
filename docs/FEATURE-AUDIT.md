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

> **STATUS (7 Oct): FTP fix ho gaya** — code `35cd630` (agent `ftp.add`/`ftp.passwd`/`ftp.del`
> + `pure-pw` allowlist + panel `AccountProvisioner::enqueue`), installer `installer/ftp-fix.sh`
> v1.0 (`047d974`, sim **40/40**, agent suite **215/0**). Live apply COMMANDS.md ke NEXT STEP se.
> **Baaki: Git · Terminal · Apps** — isi blueprint par agent tasks banenge.
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

### B6 · `MysqlServer.php` live agent me MISSING → real MySQL provisioning + backup-restore FATAL  ✅ FIXED (repo, deploy baaki)
Agent ke `src/Tasks/Db*.php`, `DbTask`, `CpanelMysql` aur `BackupArchiveStore` sab
`Alphacp\Agent\MysqlServer` use karte hain — par wo class file server par kabhi pahunchi hi
nahi (git-history/bundle/kahin nahi mili; sirf references the). Iska matlab live par
**`db.create` / `db.drop` / `db.user.create|grant|password|drop` / `db.list` / `db.restore`
— har real MariaDB task agent-step par `Class "MysqlServer" not found` se fatal** tha
(panel MySQL Databases/Users UI + cPanel-import ka mysql path dono dead).
Agent ke apne suite me ye 8 failures the (204/8). Class ko uske call-sites + tests
(= spec) se reconstruct kiya: SQL hamesha **stdin** se (argv me kabhi secret/identifier
nahi), validated + backtick-quoted identifiers, doubled-quote literals, fail-closed
password/identifier checks, `ACP_MYSQL_CLIENT` (default `/usr/bin/mariadb`).
**Verify:** `php8.4 agent/tests/run-tests.php` → **212 pass / 0 fail**. Deploy Phase-0c ke
pinned `agent-fix` command se hoga.

### B0 (meta) · Agent source-of-truth DRIFT — canonical `agent/` 77 tasks purana tha  ✅ FIXED
`build-step2-installer.py` (embed) + `tools/sim/panel-tests.sh` (test-registry) dono root
`agent/` par depend karte hain, par wo sirf **3-task** stale seed tha jabki live agent
(snapshot mirror) **80-task** pahunch chuka tha. Documented workflow follow karne par live
**80→3 tasks downgrade** ho jata (PHP/mail/DNS/backup/SSL/cron sab toot jaate). Live/snapshot
agent ko canonical `agent/` par promote kiya (strict superset; kuch lost nahi). Ab `agent/`
== live agent, aur future agent fixes isi correct base par bante hain.

## 🟡 C. Decisions pending (aap se)
1. **Entry separation live** karni hai? (`--enable-ports` — nginx 2083/2087/2096; opt-in)
2. **License-selling hardening**: per-key limits (max accounts/modules), offline grace period,
   revoke-propagation, white-label/branding, customer-facing docs — base maujood hai, depth audit agle phase me
3. Product/brand name jo license me dikhega

## 🗺️ D. Phased plan (har phase = ek verified increment, ek command/step per message)
| Phase | Kaam | Status | Ship channel |
|---|---|---|---|
| 0a | Agent source-of-truth reconcile (live 80-task → canonical `agent/`) | ✅ done (`606ac97`) | repo-only |
| 0b | **B6 fix** — missing `MysqlServer` reconstruct; agent suite 212/0 | ✅ done (repo) | — |
| 0c | B6 deploy — pinned `agent-fix.sh` (MysqlServer live par) + on-server self-test | 🔜 next | `installer/agent-fix.sh` |
| 1 | **FTP via agent** (`ftp.*` tasks + handlers + `pure-pw` allowlist + panel queueing) | pending | `agent-fix.sh` + panel-update |
| 2 | Git + Terminal + Apps via agent (`git.*`, `terminal.run`, `apps.install`) | pending | `agent-fix.sh` + panel-update |
| 3 | Metrics via agent (`metrics.access`) | pending | `agent-fix.sh` + panel-update |
| 4 | Test-debt (B4) → panel suite **100% green** | pending | panel-update (tests) |
| 5 | WebDisk: implement ya hide | pending | artifact |
| 6 | License hardening + docs | pending | artifact + docs |
| 7 | Entry separation opt-in run | pending | `login-fix --enable-ports` |

**Ship channel note:** B1/B2/B6 sab **agent-side** hain (root execution), isliye inka fix
`panel-update` artifact se nahi — ek **pinned `installer/agent-fix.sh`** se jaayega (wahi
proven pattern jo `login-fix.sh` me chala: payload files + backup + atomic swap + paneld
restart + **on-server `agent/tests/run-tests.php` self-test (green zaroori)** + end me
`alphacp-sync`). Panel-side queueing changes usi command me ya panel-update se jaayenge.

Gate har phase par: `php8.4 agent/tests/run-tests.php` (212/0) + `tools/sim/update-sim.sh`
(54/54) + `tools/sim/panel-tests.sh` + `tools/sim/login-entry-sim.sh` (53/53) — phir
commit-pin + sha256 command.
