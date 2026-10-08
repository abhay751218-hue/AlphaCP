# CHANGELOG

All notable changes to AlphaCP are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: SemVer.

## [Unreleased]
### Added
- `installer/ui3-fix.sh` **v1.0** (+ `.in`, `tools/build-ui3-fix.py`,
  `tools/sim/ui3-fix-sim.sh` **26/26**) — P-UI-3: WHM sidebar Favorites (star
  toggle + Favorites group, localStorage `acp_whm_favs`; cPanel ka server-side
  store parity gap documented — DB schema change intentionally nahi).
### Added
- `installer/ui2-fix.sh` **v1.0** (+ `.in`, `tools/build-ui2-fix.py`,
  `tools/sim/ui2-fix-sim.sh` **32/32**) — P-UI-2: WHM mode me cPanel WHM jaisa left
  sidebar (search + 8 collapsible category groups, live routes + soon chips);
  ModuleCatalog ka single 'server' blob → proper WHM groups; layout whm-shell wrap;
  panel.css .whm-side styles. Pin: `b25ad61d88c3410b9fca0a9e9891d448f037e95d` /
  `900cc726295b80b2e9ad1741a339039820dc1990dbc1f92eb7e5b4fbc7cb8b1c`.
  **8 Oct 20:36 IST LIVE APPLIED, user-confirmed** (backup `ui2fix-20261008150600`,
  /login HTTP 200, sync complete). Known cosmetic: apply-header subtitle ui1 wala
  dikhta hai (sirf display text; koi functional impact nahi) — agle ui2 build me theek hoga.
### Fixed
- **Live-root suite ke 3 asli failures (`380ce91`)** — (1+2) `MailServer::installed()`
  / `BindServer::installed()` asli FS par `is_executable()` dekhte the; live par
  exim4/dovecot/bind9 hamare installers se lage hain isliye "absent" branch kabhi
  sach nahi hota tha → ab env-override ke bagair injected executor probe (Fake me
  `binsAbsent` flag = hermetic). (3) `repairMaildirs` uid<=0 par skip karta tha →
  root-owned world-writable Maildir parents kabhi 0700 nahi hote → chown sirf valid
  uid par, mode tighten hamesha. Suite root+non-root 222/0.
- **MailServer root-fix (`2969fd8`)** — `ensureFilterEtcSearchable` root agent par
  `~/etc remains inaccessible` de kar saare mail filters skip karta tha (live suite
  219/3 isi se block hui). Ab self-healing: chgrp/chmod ke baad `clearstatcache` +
  fresh `lstat` verify, fail par fallbacks — chown owner=mailbox uid (+owner search
  bit), last resort world search bit (+x only; read/list nahi). Suite ab ROOT aur
  non-root dono par **222/0** (sandbox sudo repro se verified).
### Added
- `installer/mail-fix.sh` **v1.1** (`f2ce8cd163e9725e0d7dcac0247f9596a58c0b8f` /
  `c3bd9568377e7dc9cdc77c6909ea7eeff5f441d6e45c8fbd54780818de82e442`, sim **36/36**) —
  v1.0 live par 219/3 par block hua; 3 live-parity fixes (installed probes + root-owned
  Maildir repair) + BindServer.php payload (4 files). v1.0 (`3195ff6…`/`487f0f16…`)
  8 Oct 19:57 IST RAN, gate blocked → SUPERSEDED.
  **v1.1 8 Oct 20:15 IST LIVE APPLIED, user-confirmed: suite `passed: 222 failed: 0`
  (live ab authoritative), sync complete, backup `mailfix-20261008144240`.**
- `installer/mail-fix.sh` **v1.0** (+ `.in`, `tools/build-mail-fix.py`,
  `tools/sim/mail-fix-sim.sh` **23/23** — root-run suite proof ke saath) —
  MailServer fix + 2 test files current era EK installer me (suite gate dono ke
  saath hi green hota hai). Pin: commit `3195ff6fdd69de57537c43b1b96554e3ba239aa9`,
  sha256 `487f0f163bfbac46e8e4dffc0dfd689e8b3c2a2359aeef8e138d42ed3e99e703`.
- `installer/tests-sync.sh` **v1.0** (+ `.in`, `tools/build-tests-sync.py`,
  `tools/sim/tests-sync-sim.sh` **17/17**) — live agent suite current era par:
  2 test files byte-for-byte (`6c00f97`, 222 tests) + suite gate (passed>=222 failed=0).
  Pin: commit `3d649d8f8f225db2bd7ac46b45b302e0ad8bc3b7`, sha256 `f9b298f580d87a8ad5f662059cda1e2ecfe7baedb929d84e07911a3c56706478`.
  **8 Oct 19:36 IST RAN, gate blocked (219/3) → SUPERSEDED by mail-fix v1.0 (dobara mat chalao).**
- `installer/suite-enable.sh` **v1.1** (`bc7b95f08316bbcae96754a755ca5b522c35a15b` / `02517c05bb694c5cb7b6a508cd7c3d794e243f1eac9059a9319c8dcc11487d75`) — v1.0 ka pipefail
  silent-death fix: suite output log file me, fatal par tail-25 + alag suite-tail log +
  clear die; summary grep `|| true`. Sim 10/10.
- `installer/ui1-fix.sh` **v1.0** (+ `.in`, `tools/build-ui1-fix.py`, `tools/sim/ui1-fix-sim.sh`
  **29/29**) — P-UI-1: cPanel Paper-Lantern style light theme (paper-white cards, navy
  `#1c2733` top bar, orange `#FF6C2C` accent — AlphaCP branding), customer dashboard par
  tool-grid + General Information/Statistics sidebar + top search (vanilla JS tile filter).
  4 panel files byte-for-byte; koi agent/DB change nahi.
  Pin: commit `4cd18899a942ef7b92e1d9256938ad530bc101fb`, sha256 `0085da7ea7e0675c48b32c316ec222ea90573160c09c0a28a01ea552da5a9b0e`.
- `installer/suite-enable.sh` **v1.0** (+ `tools/sim/suite-enable-sim.sh`, **8/8**) —
  user-approved `php8.4-sqlite3` live install + poora agent suite LIVE gate
  (passed>=222 failed=0); idempotent, --rollback/--diagnose.
  Pin: commit `718da55298c32d61e67a3ea6ecfc123d6e5feec1`, sha256 `b16199cf683c3def57f376fce1a295bb9d4069c825dcb650c5a751eb214bf61a`.
- **B4 test-debt fixed (repo, `58fb3cb`):** 6 panel-test failures → tests trademark-free UI
  strings se align (`Server Manager Dashboard`, `Account Panel`, `customer account panel`,
  `Transfer or Restore a Hosting Account`, `Import a hosting account archive/from an archive`);
  WHM dashboard me cPanel-style **Quick links** card; MysqlUsersTest create-order fix.
  B5: env-skip matrix documented (wasm sandbox vs live pdo_sqlite/posix).
- `installer/b3-fix.sh` **v1.0** (+ `.in`, `tools/build-b3-fix.py`, `tools/sim/b3-fix-sim.sh`) —
  B3 deploy: agent 7 + panel 3 files byte-for-byte; gates b2-fix jaise + apache WebDAV
  modules (dav/dav_fs/auth_digest, warn-only) + panel asserts (Paneld::run, password field,
  `webdisk/{login}` route). **Sim 41/41** (PRE=`2e9bc49` reproduce → apply → rollback →
  idempotent; payload era `ee6b659`).
  Pin: commit `830f68c7fe1e1bb4cc30507fdc459cc9c98b5106`, sha256 `7794106c241ecb90d6ad0b10ca3eecb618fe32d5912e51c6b099da0dd0208aed`.
- **B3: WebDisk = asli WebDAV provisioning** · agent `src/WebDisk.php` engine: Digest
  credential file (`login:realm:md5-A1`, plaintext kahin nahi — DB me bhi nahi), managed
  Apache snippet (`Alias /webdisk <home>` + `DAV on` + Digest; write methods sirf rw logins,
  ro par denied; khali rw-list = all denied), vhost `IncludeOptional` + reload; aakhri
  account par conf+digest purge. Tasks `webdisk.list/create/delete` (registry 96 → **99**,
  create = upsert/password-reset). Panel: controller agent-truth (`Paneld::run`), DB row
  sirf ownership cache (koi schema change nahi), view me password + connect-info.
  Commit `ee6b659`; suite 221 → **222/0**.
- `installer/b2-fix.sh` **v1.0** (+ `.in`, `tools/build-b2-fix.py`, `tools/sim/b2-fix-sim.sh`) —
  B2 deploy: agent 4 + panel 2 files byte-for-byte; gates wahi pattern (suite `passed>=221
  failed=0` — live par pdo_sqlite absent = documented skip-note, registry 96 me `metrics.access`,
  Support me `function parse` absent, controller me `Paneld::run`). **Sim 40/40** (PRE=`935a3e3`-era
  reproduce → apply → rollback → idempotent; payload era `715a9e2`).
  Pin: commit `2e9bc49363893665c4d1b45be380904306317e1a`, sha256 `11a24ab51244cc43ce8f342dbd9625a6f580edca0fca219086b4d191171df594`.
- **B2: Metrics ab root-agent se** · agent par naya task `metrics.access` (registry 95 → **96**;
  readonly, t60, paths /var/log + /home): `agent/src/Metrics.php` streamed combined-log parser
  (bytes/visitors/requests/errors/top-10; >8MB par last-8MB tail; 2M-line cap) +
  `Tasks/MetricsAccess.php` (account guard, PathGuard-validated optional `log_path` warna distro
  candidates; missing log = zero-stats, error nahi). Panel `MetricsController` →
  `Paneld::run('metrics.access',[account],20)` (taskTypes-gated); `Support/Metrics` slim →
  sirf `human()`. Suite 213 → **221/0** (8 naye parser/task tests). Commits `6d72207`+`715a9e2`.
- `installer/sec-fix.sh` **v1.0** (+ `.in`, `tools/build-sec-fix.py`, `tools/sim/sec-fix-sim.sh`) —
  B1-ext deploy: agent 9 + panel 2 files byte-for-byte; gates wahi (suite `passed>=220
  failed=0`, Support files me code-level Process absence). **Sim 40/40** (live-state
  `b97d76f` reproduce → apply → rollback → idempotent).
  Pin: commit `935a3e392436ed2c04b393219cd50208666f23eb`, sha256 `f9f85ccd456dcfc96f542e432dd15e293bb4b898594d7ecfaadb2a82b564bee6`.
- `installer/b1-fix.sh` **v1.0** (+ `.in`, `tools/build-b1-fix.py`, `tools/sim/b1-fix-sim.sh`) —
  B1 part 2 deploy: agent 10 + panel 4 files byte-for-byte; gates ftp-fix jaise (suite
  `passed>=218 failed=0`, panel me code-level Process absence assert). **Sim 40/40**
  (live-state `047d974` reproduce → apply → rollback → idempotent).
  Pin: commit `ec50182305cdd324a93db159e738ec3881e74ebc`, sha256 `046bfbbce36f92c1d5af59431e95b187b14097bf749f2446c6d725f0fd21bfca`.
- **B1-baaki: Git Version Control + Terminal + Site Software ab root-agent se** · agent par 6 naye
  tasks (`git.list/clone/pull/status`, `terminal.run`, `apps.install`; registry 83 → **89**) +
  `agent/src/Git.php` + `Tasks/GitTask` base; allowlist me git/curl/chown + terminal whitelist bins.
  Guards: repo `<home>/git/<dir>` tak limited (PathGuard), `git clone --` se option-injection band,
  pull `--ff-only`, terminal whitelist agent par DOBARA validate (chaining chars banned, `cat`
  PathGuard ke andar), WordPress tarball account-home ke andar (koi shared-/tmp race nahi) +
  `<account>_wp` DB/user/grant MysqlServer se. Panel: `GitController` account-scoped (Paneld::run
  se sync status/list, enqueue se clone/pull), `TerminalController` Paneld::run, `AppsController`
  enqueue; `AppInstaller` sirf catalog. Agent suite **218/0** (3 naye tests).
- `installer/ftp-fix.sh` **v1.0** (+ `installer/ftp-fix.sh.in`, `tools/build-ftp-fix.py`,
  `tools/sim/ftp-fix-sim.sh`) — B1 ka pehla deploy: FTP ko web-FPM ke `Process` se nikaal kar
  **root agent** par le jaata hai. Agent ki 7 + panel ki 2 files byte-for-byte embed;
  backup → `php -l` (fail par error print) → static smoke → poora agent suite gate
  (`passed>=215 failed=0`) → paneld restart → panel files → `Process::` absence assert →
  `artisan optimize:clear` + php-fpm restart (opcache) → HTTP smoke → `alphacp-sync`;
  kahin bhi fail = **auto-rollback**. Flags `--diagnose` / `--rollback` / `--help`.
  **Sim 40/40** (PRE-FTP state `35cd630^` se reproduce: registry 80 types + `Process::` wala panel
  → apply → 83 types/215 green/panel Process 0 → rollback → re-apply idempotent).
  Pin: commit `047d974540aceff0fa686a8b3e3fc65a104f0f21`, sha256 `8f2cdafec0f2fea7a9065109364ca60438ee77bfa72a428189ffcff4cb780809`.
- Portability: installer scripts apna php binary ab `PHP_BIN` me rakhte hain aur inherited `PHP`
  env ko `unset` karte hain — php-wasm `PHP` ko *version* maanta hai, warna CI/sim me har child
  php call `Unsupported PHP version /path/to/php` par fail hota tha.
- `installer/agent-fix.sh` **v1.0** (+ `tools/build-agent-fix.py`, `tools/sim/agent-fix-sim.sh`) —
  B6 fix ko live agent par deploy karne wala self-contained, commit-pinned, sha256-verified installer.
  Sirf `src/MysqlServer.php` rakhta hai; backup → `php -l` → static smoke (bina pdo) → full agent
  suite gate (pdo_sqlite ho to `failed:0`, warna rollback) → paneld restart → `alphacp-sync`.
  `--diagnose` / `--rollback` flags. **Sim 17/17** (reproduce 204/8 → apply → 212/0 → rollback →
  idempotent). Pin: commit `321c81929df94e6d2b05a29b912eaa31fba4209d`, sha256
  `d03f3cd69620d21e1f0c4aef9f84b85fa1e7ec115899526f69c805bcb567e9a1`.
- `docs/FEATURE-AUDIT.md` — poore panel ka A→Z audit (7 Oct): 204 route→controller pairs (0 dead),
  80 agent task handlers (0 unknown), TODO grep 0, suite + live logs/config se evidence.
  **Production-broken cluster mila:** (B1) `proc_open` fpm me disabled par FTP/Git/Terminal/Apps
  web-FPM se `Process` chalate hain → 500; (B2) Metrics `open_basedir` se blocked;
  (B3) WebDisk sirf DB rows; (B4) 6 test-debt failures; (B5) 6 wasm-skip record karne hain.
  7-phase fix plan bhi usi doc me (har phase = verified increment + pinned command).
### Fixed
- **B1 (part 1) — FTP Accounts live par HTTP 500** · panel `App\Support\Ftp` web-FPM se
  `Process::run(['pure-pw', …])` chalata tha; pool me `proc_open` disabled hone se har FTP
  create/password/delete 500 deta tha (aur `installer/ftp-accounts.sh` view me reference hone ke
  bawajood maujood hi nahi tha). Ab cPanel-tareeka: agent par `ftp.add` / `ftp.passwd` / `ftp.del`
  tasks (`agent/src/Ftp.php` + `Tasks/FtpTask` base + 3 handlers), `pure-pw` CommandRunner
  allowlist me, registry 80 → **83 types**. Panel `Support/Ftp` sirf capability (agent registry se,
  `open_basedir`-safe) + login/home helpers deta hai; `FtpController` `AccountProvisioner::enqueue`
  karta hai. Guards: password stdin par (argv me kabhi nahi), chroot home PathGuard se account-home
  ke andar, virtual login `<account>_<suffix>` prefix-locked, uid/gid `getent` se >=1000,
  min length 8. Agent suite **215/0** (3 naye FTP tests), panel boot smoke 8/8.
- **B6 — real MySQL provisioning + backup-restore FATAL (7 Oct, repo me fix; deploy baaki)** ·
  agent `src/MysqlServer.php` **missing** tha live par. `DbTask`, saare `Db*` handlers
  (`db.create/drop/user.create|grant|password|drop/list`), `db.restore`, `CpanelMysql` aur
  `BackupArchiveStore` isi class ko `use` karte hain — par file server par kabhi pahunchi nahi
  (git-history/bundle/kahin nahi; sirf references). Natija: panel ke MySQL Databases/Users UI +
  cPanel-import ka mysql path agent-step par `Class "MysqlServer" not found` se **fatal**.
  Agent ke apne suite me 8 failures (204/8). Class ko call-sites + tests (= spec) se reconstruct
  kiya: SQL sirf **stdin**/stdinFile se (argv me kabhi secret/identifier nahi), validated +
  backtick-quoted identifiers, doubled-quote literals, fail-closed password (8–64, no
  quote/backslash/control-char) + identifier checks, `ACP_MYSQL_CLIENT` (default `/usr/bin/mariadb`).
  **Verify:** `php8.4 agent/tests/run-tests.php` → **212 pass / 0 fail**.
- **B0 — agent source-of-truth DRIFT (`606ac97`)** · canonical `agent/` sirf 3-task stale seed tha
  jabki live agent 80-task; `build-step2-installer.py` + `tools/sim/panel-tests.sh` isi stale root
  par depend karte the → step2 dobara chalane par live **80→3 tasks downgrade** (catastrophe).
  Live/snapshot agent ko canonical `agent/` par promote kiya (strict superset, kuch lost nahi).
- **LOGIN LOCKOUT — entry separation (7 Oct)** · `installer/login-fix.sh` **v1.0**
  "Login page khulta hai, credentials daalne par login nahi hota, error aata hai" — user ki yahi
  shikayat sandbox me **live server ke exact panel code (0.75.0) + asli vendor** par reproduce ki
  (php-wasm + SQLite, `server-snapshot/` se), phir fix kiya.
  - **B1 — `EntryLoginController` v1 ka lockout** (entry separation tootne ki wajah): gate `ports.json` par bharosa karta
    tha (owner ki `/ports` screen ki *ichha*), nginx kya **asli me** listen kar raha hai us par nahi.
    Server par nginx sirf `8090` par sunta hai (vhost ka `ACP_PORTS_START/END` block **khaali** hai
    aur koi "apply-step" repo me maujood hi nahi), par `ports.json` me 2083 hote hi `user`/`mail`
    role ka har login 8090 par reject: *"Account Panel login 2083 par hota hai — https://\<host\>:2083
    kholein."* — aur 2083 kholne par connection refused. **Permanent lockout.**
    *Proof:* isi wajah se panel ka apna `tests/Feature/AuthTest.php` fail hota tha
    (`Valid credentials reach the dashboard`, `Two factor gate blocks the dashboard`), saath me
    `MysqlUsersTest::Customer and mail cannot open the users page` aur
    `TransferRestoreTest::Root can queue a real cpanel import`. Fix ke baad chaaron PASS.
  - **B2 — reverse proxy/CDN:** `trustProxies(at:'*')` ki wajah se `X-Forwarded-Port: 443` par
    `getPort()=443` milta tha; v1 ka rule `!$onCustomer` tha, isliye customer **phir bhi deny** ho
    jata tha. v2 fail-OPEN hai: port/role/truth-file kuch bhi confirm na ho → allow.
  - **B3 — 2FA redirect loop:** session me `two_factor_passed` kho jaye (purana session, 2FA baad me
    off, driver change) aur account par 2FA enabled na ho, to `/dashboard` ⇄ `/two-factor` ka
    **infinite loop** chalta tha → browser me "Too many redirects". `EnsureTwoFactorIsVerified` ab
    flag khud heal karta hai + `TwoFactorController::challenge()` me loop-breaker.
  - **B4 — `GET /login` = 404:** login page sirf `/` par tha, isliye bookmark/WHMCS/cPanel-aadat
    wale `/login` link 404 dete the. Ab `GET /login` alias (`login.page`) bhi wahi page deta hai.
  - **B5 (ASLI WAJAH) — `ResellerScopeProvider` ka FATAL RECURSION:** provider ke dono global scopes
    (`Account`, `User`) apne andar seedha `Auth::user()` call karte the. Laravel ka
    `SessionGuard::user()` apna `$this->user` **`retrieveById()` return hone ke BAAD** set karta hai,
    aur `EloquentUserProvider::retrieveById()` `newQuery()` se query banata hai — yani **global scopes
    ke saath**. Nateeja: har authenticated request par
    `Auth::user() → SessionGuard::user() → retrieveById($id) → User query → reseller_scope →
    Auth::user() → …` **infinite loop** → PHP fatal (`Allowed memory size exhausted`, sandbox me
    606,955 stack frames) → **HTTP 500**. Login POST to 302 de deta tha, phir `/dashboard` par 500 —
    user ko yahi dikhta tha: *"login page khulta hai, credentials daalne par login nahi hota, error
    aata hai."* Panel ke apne tests isko **pakad nahi paate the** kyunki `actingAs()` guard par user
    seedha set kar deta hai (`retrieveById` chalta hi nahi); isliye suite "green" tha par panel toota hua.
    Fix: `actor()` recursion-breaker flag — auth khud user load kar raha ho tab scope chup rehta hai
    (`finally` me reset, isliye exception par bhi stuck nahi hota). Scoping parity bilkul waisi hi hai
    (reseller ko sirf apne accounts/users; root bypass; CLI actor null = unscoped).
  - Saath me: baar-baar fail hone par laga `locked_until` / `failed_logins` / `login_attempts` /
    rate-limit reset (warna fix ke baad bhi "Account is locked for a short time" aata rehta).
- **`EntryLoginController` v2 (naya design):** gate ab sirf `/usr/local/alphacp/etc/entry-ports.json`
  ("truth file") ko maanta hai, jo **root** ka naya `acp-entry-ports` tool nginx ke asli listening
  ports se banata hai (teen chhanni: `nginx -T` me `listen <p> ssl` **aur** `ss -Hltn` me bound
  **aur** port AlphaCP ki allowlist me). File na ho → single-entry mode → gate OFF. Denial sirf tab
  jab dusri entry sach me live ho. Decision pure-static `decide()` me hai (DB/file/request nahi),
  isliye PHPUnit aur on-server `--selftest` dono me chalta hai. Galat password/unknown user par gate
  chup rehta hai (port se role-enumeration nahi). Koi bhi `Throwable` → `report()` + allow.
### Added
- `installer/login-fix.sh` v1.0 (+ `installer/login-fix.sh.in` template, `installer/payload/*`,
  `tools/build-login-fix.py` builder). Steps: diagnose → live login probe → unlock → **Step 4/4b/5/6**
  (EntryLoginController v2, ResellerScopeProvider v2, 2FA self-heal + loop-breaker, `GET /login`
  alias) → truth file + cron → perms/`optimize:clear`/fpm restart → selftest → verdict → tests
  install → `alphacp-sync`. Modes: `--diagnose` (read-only report), default (fix chain upar),
  `--enable-ports`
  (nginx par 2083/2087/2096 **asli me** listen karwao + UFW + truth file refresh; `nginx -t` fail
  par auto-rollback), `--rollback`. Har PHP swap se **pehle** `php -l`; kuch delete nahi hota
  (backup `<ACP_HOME>/releases/loginfix-<ts>/`). End me `alphacp-sync`.
- `acp-entry-ports` (`/usr/local/alphacp/bin/` + `/etc/cron.d/alphacp-entry-ports`, har 5 min +
  `@reboot`) — truth file generator. Kabhi `exit 1` nahi karta.
- `tools/sim/login-entry-sim.sh` — 53 assertions (build drift, truth-file ke 6 scenario, diagnose
  read-only, full run, idempotency, `--enable-ports` + nginx-fail rollback, `--rollback`, PHPUnit,
  **P8 bug-proof**: v1 controller + `ports.json(2083)` par `AuthTest` FAIL hona chahiye, aur
  **P9 bug-proof**: v1 `ResellerScopeProvider` par `SessionAuthTest` ka PHP fatal hona chahiye).
  Sim ke panel DB me 2 users seed hote hain (jaan-boojh kar `locked_until` lagake) taaki Step 3 ka
  unlock aur SELFTEST ka B5 recursion check asli DB par chale.
- `installer/payload/tests/SessionAuthTest.php` — **B5 ka regression test** (12 tests / 31
  assertions). `freshRequest()` guard ko `forgetGuards()` karke user ko session se resolve karwata
  hai (asli browser jaisa), jo `actingAs()` wale shortcut ko bypass karta hai. Saath me parity checks:
  reseller ko sirf apne users, root ko sab, guest/CLI unscoped, reseller barabar/upar wala role create
  nahi kar sakta.
- `login-fix.sh` ke SELFTEST me **B5 runtime check** juda: active user ko session me daal kar
  `forgetGuards()` → `Auth::user()`; recursion ho to PHP jaldi fatal de isliye `runphp` ab
  `-d memory_limit=${ACP_FIX_PHP_MEM:-256M}` ke saath chalta hai (sirf us CLI call par, php-fpm
  settings ko haath nahi lagta).
- **PANEL_USER detection fix (live run 7 Oct se pakda gaya):** pehle runtime user `artisan` file ke
  owner se detect hota tha. Live server par deploy root se hua hai isliye `root` mila, jabki php-fpm
  pool `alphacp` se chalta hai. Step 8 us galat user ko `storage/`+`bootstrap/cache/` de deta
  (0770/0660) → fpm compiled views/file-cache/log **padh bhi nahi pata** → har page 500. Ab detection:
  `ACP_PANEL_USER` override → chalte `php-fpm: pool <name>` workers → pool conf (sirf wo jo ACP home
  ka zikr kare, `www.conf` se bachne ke liye) → `storage/logs` owner → `artisan` owner → `alphacp` →
  `root`. Diagnose ab `runtime user : <user> [<source>]` + artisan owner alag-alag dikhata hai.
- **Koi interactive prompt nahi:** pehle Step 2 terminal par username/password poochta tha
  (`read -s`). 7 Oct ko mobile SSH par run wahin atak kar adhoora reh gaya (password prompt par
  script khatam) — fix apply hi nahi hui. Ab live login probe **sirf env-vars** se hoti hai
  (`ACP_FIX_USER` + `ACP_FIX_PASS`); warna skip. Verification phir bhi poori hai: SELFTEST ka
  B5 runtime check + Step 8 write-test + Step 10 HTTP + aapka browser login.
- **Step 8 write-test guard:** chown ke baad fpm user se `storage/framework/cache/data`,
  `storage/framework/views`, `storage/logs` me `touch` karwaya jata hai; fail ho to saaf error
  (+ `panel-perm-fix.sh` ka ishara). Ye guard isi bug-class ko dobara server par jaane se rokta hai.
- `installer/payload/tests/EntryLoginTest.php` — panel me install hone wala regression test
  (10 tests / 42 assertions): `decide()` truth table, truth-file parsing, gate-off, sirf-8090-live
  (B1 regression), asli separation, generic password error, array-input crash, 2FA loop, `GET /login`.
### Verified (live panel code 0.75.0 + asli vendor, php-wasm 8.5 + SQLite)
| Suite | Fix se PEHLE (v1 code) | Fix ke BAAD (v2) |
|---|---|---|
| poora PHPUnit suite | 459 pass, **8 fail**, 6 wasm-skip | **470 pass, 6 fail**, 6 wasm-skip |
| `AuthTest` | 2 FAIL (B1: "Account Panel login 2083 par hota hai") | **8/8 OK** |
| `EntryLoginTest` (naya) | — | **10/10 OK** |
| `SessionAuthTest` (naya) | PHP **fatal** (606,955 stack frames) | **12/12 OK** |
| `tools/sim/login-entry-sim.sh` | — | **53/53 PASS** |
| `AuthorizationTest` (reseller/root parity) | OK | **OK** (parity bilkul waisi hi) |

Fix ne 2 login failures hataye aur **ek bhi naya failure nahi** laaya.

### Live deploy — 7 Oct 13:04Z, ip-172-26-4-65 (pin `269eb3c`, sha256 `6ca53d4d…`)
- Step 9 SELFTEST **16 pass, 0 fail** — jisme `PASS B5: session se user resolve hua
  (recursion nahi)` **live DB par** (pehle yahi request PHP fatal karti thi).
- Step 8: `ownership alphacp:alphacp` + `fpm user (alphacp) storage me likh sakta hai`
  + `restart php8.4-fpm` + panel HTTP 200.
- Step 7: truth file `{"manager":[8090],"customer":[]}` (single-entry, gate fail-open)
  + cron `/etc/cron.d/alphacp-entry-ports` (`*/5` + `@reboot`).
- Step 3: `login_attempts cleared: 72`, koi locked user nahi.
- Server ke apne `alphacp-sync` ne post-fix snapshot push kiya (**`e50f842`, 11 files**) —
  usse byte-level tasdeeq hui ki live par v2 payloads hi chal rahe hain
  (`ResellerScopeProvider` me `resolvingActor`, `EntryLoginController` me `decide()`,
  `routes/web.php` me `login.page`, naya `bin/acp-entry-ports` + cron).
- nginx error log ke recursion fatals **12:53:32 par ruk gaye** (fix 13:04Z par lagi);
  uske baad koi naya fatal nahi.

### Known issues (login se related NAHI — pehle se the, is change me chhede nahi)
- 6 tests fail hote hain, sab **purane assertions** ki wajah se (production bug nahi):
  - `DashboardShellTest` ×2 — `assertSee('WHM Dashboard')`, par UI ab `'Server Manager Dashboard'`
    dikhata hai. Dashboard asli me HTTP 200 deta hai (verify kiya, 16,690 bytes).
  - `DomainsTest::Whm user does not use customer domain form` — `assertSee('customer cPanel')`.
  - `TransferToolTest` + `TransferRestoreTest` ×1 each — Transfer Tool page ke content par `assertSee`.
  - `MysqlUsersTest::Customer and mail cannot open the users page` — test ka apna order galat hai:
    `mail` role se logged-in hokar `root` user banata hai, jo `ResellerScopeProvider` ke
    `User::creating` guard (rule 3) par 403 khata hai. Guard bilkul sahi kaam kar raha hai;
    test me `$whm` ko `asPanelUser($mail)` se PEHLE banana chahiye.
  - Ye test-debt alag change me theek hoga — parity checklist ki koi row nahi hatayi gayi.
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
