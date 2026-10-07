# CHANGELOG

All notable changes to AlphaCP are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: SemVer.

## [Unreleased]
### Fixed
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
