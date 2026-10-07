# AlphaCP — AI HANDOFF DOCUMENT (poora project context)

> **Ye file kisi bhi AI (Claude / ChatGPT / Gemini / Cursor) ko do — woh 5 minute me poori situation samajh jayega.**
> Iske saath `alphacp-code-bundle.zip` (poora code, docs, installers) bhi do. Dono milkar complete picture dete hain.
> ⚠️ **Is file me server ka password hai — PUBLIC GitHub repo me mat daalo. Private repo ya direct paste karo.**

---

## ⚡ LATEST STATE (7 Oct 2026) — NAYE AI KE LIYE READ FIRST

> Neeche wala pura doc **historical** hai (purane 500-blocker/paste.rs zamane ka). **Asli current
> state ye section + linked docs** me hai. Server ab theek hai aur panel live hai.

**Product goal (binding):** AlphaCP = **100% cPanel/WHM-jaisa** panel jo customers ko **license
ke roop me becha jayega** → har cheez **portable, idempotent installer** se (koi bhi fresh
Ubuntu VPS, one command), aur repo **self-documenting** taaki **koi bhi AI naye chat me**
continue kar sake bina is chat ke context ke.

**Current (sab tool-verified, 7 Oct):**
- `alphacp-sync` v1.5 live → `server-snapshot/` = **server ka byte-barabar copy**. Panel **0.75.0**, HTTP 200, Laravel 13.33, 70 DB tables.
- Deployed-code test suite: **453 pass, 0 fail** → `bash tools/sim/panel-tests-deployed.sh` (php-wasm; SRC env override se feature-overlay test hota hai).
- Roadmap **S1–S10 + S13/S15 core LIVE**; status `ROADMAP.md` me (stale nahi).
- **G1 FTP Accounts LIVE** (Pure-FTPd) — parity-gap ka pehla feature, server par installed.
- Baaki gaps + build order: **`docs/11-cpanel-100-parity-map.md`** (G2 Metrics, G3 App Installer, G4 WAF/Virus, G5 Terminal/Git, G6–G8).
- A–Z live/complete plan: `docs/10-live-and-complete-plan.md`. ⚠️ **License trial 13 Oct** ko khatam — sabse pehle ye.

**Versioning (do alag numbers — koi error nahi, par scheme samjho):**
- `server-snapshot/.../panel/MANIFEST.json` → `version` = **deployed code build** (abhi `0.75.0`); sync isi ko "panel code" report karta hai.
- `.env` → `ACP_VERSION` = **product version jo UI/footer me dikhta hai** (`config('acp.version')`, abhi `0.83.0`). `ACP_AGENT_VERSION` = agent (`0.83.0`).
- `config/acp.php` ke `0.72.0`/`0.62.0` sirf **fallback** hain (.env override karta hai) — inhe ignore karo.
- "0.74.0" jo kabhi dikha wo purana build tha; ab code-build `0.75.0` hai.
- **Going forward (sellable product):** har feature-release par `ACP_VERSION` bump hoga (0.84.0 → 0.85.0 …)
  aur portable installer `.env` me use set karega; code-build (MANIFEST) sync khud update karta hai.
  Customer ko ek consistent version dikhega (cPanel jaisa `118.0.x` style).

**Feature add karne ka STANDARD FLOW (har AI yahi use kare):**
1. Code likho `features/<name>/` me: `app/Http/Controllers/`, `app/Models/`, `app/Support/`,
   `database/migrations/`, `resources/views/<name>/`, `routes-<name>.php`, `tests/Feature/<Name>Test.php`.
   (Panel conventions: `accountFor/requireAccount` private helpers, `perm:files.*`-style middleware,
   system-ops `App\Support\*` me + `Process` facade taaki test fake kar sake.)
2. Overlay: `cp -a server-snapshot/files/usr/local/alphacp/panel /tmp/ov` → feature files copy →
   `cat features/<name>/routes-<name>.php >> /tmp/ov/routes/web.php` → test file `/tmp/ov/tests/Feature/`.
3. Test: `SRC=/tmp/ov bash tools/sim/panel-tests-deployed.sh tests/Feature/<Name>Test.php` → **0 fail** chahiye.
4. Portable installer `installer/<name>.sh` (idempotent, fresh-VPS par daemon khud install kare;
   **version banner** + end me `alphacp-sync`). Generator: `features/` se exact embed karo (heredoc, quoted delimiter).
5. Commit+push branch par; `git show <commit>:installer/<name>.sh | sha256sum` → **pinned deploy command**
   `COMMANDS.md` me (`alphacp-sync get <COMMIT> installer/<name>.sh /tmp/... <SHA> && sudo bash ...`).
6. User ko **EK** command do; output aane par hi agla step. Server ko sandbox se direct touch mat karo.

**Binding rules:** Hinglish jawab; ek message me **EK** command; **untested command KABHI nahi**
(reproduce→fix→verify→phir command); `docs/09` ki row **delete nahi**; **secrets push nahi**;
`server-snapshot/STATE.md` manual edit nahi (sync overwrite karta hai).

---

## 0. TL;DR (naye AI ke liye — 15 lines me sab kuch)

1. Project: **AlphaCP** — ek **custom web hosting control panel** jo **100% cPanel/WHM jaisa** hoga (har feature + har limit), plus apna **license system** (commercially bechne ke liye) + **one-click install/upgrade** (cPanel jaisa).
2. Business: User India me **web hosting company** shuru kar raha hai. Customers: India + Global. Billing software unka **khud ka custom** hai → panel ko WHMCS-style **WHM API 1** compatible banana hai (kisi bhi billing software se chal jaye).
3. **Route LOCKED**: 100% custom panel, koi shortcut nahi (forks/CloudPanel/OpenPanel ruled out). Behavior/API cPanel-jaisa, brand copy nahi.
4. Stack: Ubuntu 24.04 + nginx + **php-fpm 8.4** + MariaDB + Apache (backend sites) + Laravel **13.33.0** panel.
5. **Parity contract**: `docs/09-cpanel-parity-checklist.md` — cPanel/WHM ke **208 tools** ki checklist. Yahi decide karega ki project kab 100% complete hai. **Isse koi row kabhi delete nahi hoti.**
6. Progress: **S0 ✅ done**, **S1 ✅ (installer live)**, **S2A ✅ (paneld agent verified)**, **S2B-1 ✅ installed on server** (login + dashboard code live hai).
7. **CURRENT BLOCKER (aaj hi solve kiya, PROVEN)**: panel `https://13.207.123.177:8090` par har web request **HTTP 500** de raha tha. Asli wajah: Ubuntu/Ondrej ka `php8.4-fpm` systemd unit **`ProtectSystem=full`** lagata hai → php-fpm worker ke liye **`/usr` read-only** ho jata hai → hamara panel `/usr/local/alphacp` me hai → Laravel log/session/compiled-view likh hi nahi sakta → 500. **CLI par ye bandish nahi lagti** — isliye har probe "sab OK" dikhata tha.
8. **Fix (ready + locally proven)**: systemd drop-in `ReadWritePaths=/usr/local/alphacp` + daemon-reload + restart. Doctor script v1.6 me ye automatic hai: **`curl -sSL https://paste.rs/G72oK -o /tmp/v16.sh && sudo bash /tmp/v16.sh`**
9. Local proof (server jaisi condition bana kar): 500 → doctor v1.6 → **200 → PANEL READY ✅** → login flow bhi pass (POST /login → 302 → `/security/password` "Change Password · AlphaCP").
10. **Server par ab tak ye command chalayi nahi gayi** (01:41 par purani v1.5 chali thi). Sabse pehla kaam: v1.6 chalana, phir browser login `admin` / (password: `sudo cat /usr/local/alphacp/var/panel-admin.txt`).
11. Uske baad: S2B acceptance (login → forced password change → 2FA → dashboard), phir S2C = **license client + trial**, phir S3 (account provisioning), S4…S15.
12. User ki language: **Hindi/Hinglish**. User ko ek baar me **sirf EK command/step** do (kabhi bundle mat karo), aur jo command do woh **pehle khud test kar chuke ho**.
13. User ko sabse zyada narazgi is baat par hai: same problem baar-baar aana aur untested commands. Isliye: **reproduce → fix → verify → phir hi ek command do.**
14. Har command me **version clearly print** karo (user purani commands scrollback se dobara chala deta hai).
15. Poora code + docs = `alphacp-code-bundle.zip`; workspace me bhi sab kuch hai (neeche file map dekho).

---

## 1. User ka project — kya ban raha hai, kyun

- User apni **hosting company** ke liye **apna control panel** bana raha hai (cPanel/WHM ka paid license bachane + apna product bechne ke liye).
- Requirements (user ke shabdon me, binding hain):
  - **100% cPanel parity** — har feature + har limit (storage, mailboxes, DBs, bandwidth, inodes, etc.). Kuch bhi drop nahi karna.
  - **Kisi bhi billing software se integration** — cPanel ki tarah (WHMCS / Blesta / Clientexec bina modification chal jaayein).
  - **Apna license system** — panel commercial product hai, isliye license server + trial + expiry chahiye.
  - **One-click VPS install** + **one-click upgrade** — cPanel ki tarah.
  - **Maintainable by any AI** — aage koi bhi AI ise continue kar sake (isliye `AI_CONTEXT.md`, `AGENTS.md`, module blueprint, docs discipline).
  - Customers: **India + Global**.
  - Port: panel **8090** par primary (`https://<server>:8090`); legacy cPanel ports 2082/2083/2086/2087/2095/2096 compat ke liye rakhe gaye hain (WHMCS/billing ke liye).
  - Deploy target: one-click install kisi bhi fresh Ubuntu VPS par (Oracle Always Free 4GB/12GB ARM spare node available hai, par ARM/aarch64 verification pending hai — dhyaan rahe: cPanel x86_64-only hota hai, humara panel ARM par bhi chalna chahiye).

### User ki working style (IMPORTANT — inka khayal rakhna)
1. **Hindi/Hinglish** me jawab do. Technical terms English me theek hain.
2. **Ek baar me EK hi command/step** do. Multiple commands ek message me = confusion (user ne clearly bola: "ek ek kar ke batlao").
3. **Untested command kabhi mat do.** Pehle khud reproduce/test karo, phir do. User ko baar-baar same problem dena = user naraz.
4. Command ke saath **version clearly likho** ("ye NAYA hai, purana nahi") — user scrollback se purani command chala deta hai.
5. User ne code phase 28 Sep ko unlock kiya. Mostly user "kya karu" poochta hai → ek step do, output maango, aage badho.
6. Kabhi kabhi user frustrated ho jata hai ("apse nahi hoga") — tab **kaam rok kar** jo proven hai wahi do, aur honest raho ki kya verify hua aur kya nahi.

---

## 2. Abhi tak kya hua (chronological, compact)

### Step 0 — Blueprint ✅ DONE
Ye docs ban gaye (workspace me hain, zip me bhi):
`README.md`, `AI_CONTEXT.md`, `AGENTS.md`, `ROADMAP.md`, `CHANGELOG.md`, aur `docs/00…09`:
- `00-requirements-freeze.md` (me 8090 ka decision), `01-architecture.md`, `02-database-schema.sql` (55 tables),
  `03-security-matrix.md`, `04-coding-standards.md`, `05-license-system.md`, `06-installer-updater.md`,
  `07-decision-log.md` (10 ADRs), `08-module-blueprint.md`, **`09-cpanel-parity-checklist.md` (208 items = binding contract)**.

### Step 1 — Server base stack ✅ DONE
- **dev-srv1** = AWS Lightsail Mumbai, Ubuntu 24.04.05, 4GB RAM / 2 vCPU / 80GB, static IP **13.207.123.177**.
- Installer: `installer/install.sh` v0.1.2 (live copy paste.rs/5zVYf = v0.1.1).
- Services live: nginx, apache2, MariaDB, php-fpm, fail2ban, Redis, quotas (ek WARN pending: quota fstab), UFW + Lightsail firewall me 22/80/443/8090/2082-2083/2086-2087 open.
- Note: Lightsail outbound port 25 blocked → dev me 587/relay (~48h me open hoga).

### Step 2A — paneld agent ✅ DONE + VERIFIED
- `agent/` (Python daemon + systemd unit + CLI `cli/alphacp` v0.2.0). 16/16 tests pass, `agent.ping` 2 ms.

### Step 2B-1 — Panel bundle v0.3.0 ✅ INSTALLED LIVE (29 Sep 00:02)
- `artifacts/panel-bundle-0.3.0.tar.gz` (12.9 MB) = **Laravel 13.33.0** panel: login, RBAC, 2FA, User Manager, audit log, system/security pages.
- Install kiya: `https://paste.rs/0r1Mi` (installer v0.3.3) → panel `https://13.207.123.177:8090` par live, user `admin`, password `/usr/local/alphacp/var/panel-admin.txt` (aur `/root/.alphacp-admin-credentials`), **purana password public ho gaya tha — doctor v1.7 use rotate karta hai**.
- Install ke baad se **har browser request 500** de rahi thi. Pichhle kai din isi ko fix karne me gaye (perms, artisan-user, DB sync, php7.4 stale pool) — **asli wajah aaj mili** (section 3).

### Ab ka status (29 Sep 01:45)
- Server par panel **abhi bhi 500** (kyunki asli fix wali command v1.6 abhi server par chali nahi).
- **Fix 100% ready + locally proven hai** — bas ek command.

---

## 3. ⭐ CURRENT BLOCKER — asli wajah (PROVEN) + fix

### Symptom (server par)
- `https://13.207.123.177:8090` → **har request HTTP 500**.
- Laravel ki log file **banti hi nahi** (`storage/logs/` khaali) — app log likh hi nahi paata.
- nginx/php-fpm me `ErrorException`; doctor ke HTTP-probe me dikha: **`tempnam(): file created in the system's temporary directory`**.
- CLI par kuch bhi test karo → **sab healthy**: PHP 8.4.26, DB ping OK, users table 1 row, storage writable=yes (root ke roop me).

### Asli wajah (root cause) — LOCAL ME PROVEN
Ubuntu/Debian (Ondrej PPA) ka `php8.4-fpm.service` systemd unit me **`ProtectSystem=full`** hota hai.
Iska matlab: **php-fpm ke saare workers ke liye `/usr` (aur `/boot`, `/etc`) READ-ONLY mount ho jata hai.**
Hamara panel **`/usr/local/alphacp`** me hai → php-fpm worker:
- Laravel log file nahi likh sakta,
- session file nahi likh sakta,
- compiled blade view nahi likh sakta,
- Laravel ka temp file bhi nahi bana sakta **(`tempnam()` warning — server par yahi dikha)**

→ **pehli hi request par 500**, aur log file bhi nahi banti (kyunki log likhna bhi wahi fail hota hai).

**CLI (`runuser -u alphacp php artisan …`) par ye systemd bandish lagti hi nahi** — isliye har diagnostic "sab OK" dikhata tha. Yahi wajah thi ki pichhle saare fixes (permissions, chown, DB password, php7.4 pool) asar nahi kar rahe the.

### Fix (verified locally — server par abhi lagana baaki)
Systemd drop-in banao:

```
/etc/systemd/system/php8.4-fpm.service.d/alphacp-panel.conf
[Service]
ReadWritePaths=-/usr/local/alphacp
ReadWritePaths=-/run/php
```

phir:
```
sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm
```
(`-` ka matlab: path na mile to error mat do. Ye permanent hai, reboot-safe hai.)

### Verification jo ho chuki (local container, dev-srv1 jaisi condition bana kar)
| Test | Result |
|---|---|
| ProtectSystem=full + Laravel caches wipe → hit | **HTTP 500** (exact server signature: log file banti hi nahi) |
| doctor v1.6 chalaya | Step 2b ne sandbox pakda, Step 2c ne `Read-only file system` prove kiya, Step 5a ne drop-in lagaya |
| fix ke baad hit | **HTTP 200** → `PANEL READY ✅` |
| login flow (POST /login) | 302 → `/security/password` → `<title>Change Password · AlphaCP</title>` ✅ |
| regression: galat DB password | doctor ne 3-jagah sync kiya → 200 ✅ |
| regression: stale php7.4 pool | doctor ne disable kiya → 200 ✅ |

### Server par abhi chalane wali command (v1.6 — ye abhi tak nahi chali)
```
curl -sSL https://paste.rs/G72oK -o /tmp/v16.sh && sudo bash /tmp/v16.sh
```
Expected output: `Step 2b … SANDBOX CONFIRMED` → `Step 5 … sandbox fix CHAL GAYA` → `==> PANEL READY ✅`

### Agar (unlikely) wahan bhi 500 rahe — next fallback
1. `sudo systemctl cat php8.4-fpm | grep -E "ProtectSystem|ReadWritePaths|PrivateTmp"` — dekh lo unit me kya hai.
2. Agar `PrivateTmp=true` hai aur problem temp file ki hai: Laravel ka temp `storage/framework/tmp` par shift karo:
   `storage/framework/tmp` banao (chown alphacp) aur `bootstrap/app.php` me `$app->useStoragePath(...)` ke saath
   `sys_temp_dir` set kar do (`ini_set('sys_temp_dir', base_path('storage/framework/tmp'))` early).
3. Ya panel ko `/usr/local` se hata kar `/var/www/alphacp` (ya `/opt/alphacp`) par shift karo (systemd sandbox `/usr` ko hi
   read-only karta hai; `/var`/`/opt` writable rehte hain). Ye installer me badalna aasan hai.

### FOLLOW-UP (bahut zaroori)
Installer `installer/step2b-setup.sh` (v0.3.6) me yahi drop-in **permanently** daalna hai (v0.3.7) —
warna kisi bhi naye Ubuntu+Ondrej server par fresh install bhi 500 dega.

---

## 4. Server + credentials + paths (dev-srv1)

| Cheez | Value |
|---|---|
| Panel URL | `https://13.207.123.177:8090/` |
| Panel user / pass | `admin` / ~~(public ho chuka tha)~~ → doctor v1.7 ne random password se badal diya; server par: `sudo cat /usr/local/alphacp/var/panel-admin.txt` |
| Password file (server) | `/usr/local/alphacp/var/panel-admin.txt` (`panel_pass=…`, 0600) |
| Root creds copy | `/root/.alphacp-admin-credentials` |
| APP_KEY file | `/usr/local/alphacp/var/panel-appkey.txt` (0600) |
| Panel code | `/usr/local/alphacp/panel` (Laravel 13.33.0), owner `alphacp:alphacp` |
| Agent | `/usr/local/alphacp/agent` (paneld), CLI `/usr/local/alphacp/bin/alphacp` |
| nginx vhost | `/etc/nginx/sites-available/alphacp-panel.conf` — sirf `location ~ ^/index\.php(/\|$)` execute karta hai |
| php-fpm pool | `/etc/php/8.4/fpm/pool.d/alphacp.conf` → socket `/run/php/alphacp-fpm.sock`, user `alphacp` |
| Server | AWS Lightsail Mumbai · Ubuntu 24.04.05 · 4GB/2vCPU/80GB · static IP 13.207.123.177 |
| PHP builds | 7.4 se 8.4 tak installed (**php7.4-fpm bhi hai** — dhyaan rahe, galat fpm restart na ho jaye) |
| Admin CLI | `php artisan alphacp:admin-password {username=admin} --password= --force-change --reset-2fa` |
| DB | MariaDB, db `alphacp`, user `alphacp`; password `/usr/local/alphacp/etc/database.env` me |

---

## 5. Workspace / code bundle me kya-kya hai (zip: `alphacp-code-bundle.zip`)

| Path | Kya hai |
|---|---|
| `README.md`, `AI_CONTEXT.md`, `AGENTS.md` | Project ka AI-facing context; **AGENTS.md me "RULE 0" = parity checklist binding hai** |
| `ROADMAP.md` | 16 steps (S0–S15) + status |
| `CHANGELOG.md` | Poora version history (installer v0.1.x–v0.3.6, doctor v1.1–v1.6) |
| `project-status.md` | Current state + file ek index |
| `docs/00…09` | Blueprint docs (09 = **208-item parity checklist**) |
| `docs/runbooks/` | step-1 / step-2a / step-2b deploy runbooks (troubleshooting ke saath) |
| `installer/step2b-setup.sh` | **Panel ka single-shot installer v0.3.6** (live copy paste.rs/EPW3b) |
| `installer/panel-doctor.sh` | **Doctor v1.6** (live copy paste.rs/G72oK) — fix-chain + asli error + verdict |
| `installer/step2b-finish.sh`, `installer/panel-perm-fix.sh` | Source/hardening helper scripts |
| `installer/install.sh`, `installer/step2-install.sh(.in)`, `installer/panel-install.sh(.in)` | Step-1 aur purane panel installers (history) |
| `artifacts/panel-bundle-0.3.0.tar.gz` | ✅ Panel bundle (Laravel 13.33.0) — ye server par installed hai (sha `c6712916…c3ce`) |
| `artifacts/panel-code-0.3.0.tar.gz` | Code-only bundle (sha `61c46d6dd8e1ec1c…5a6c`) |
| `refs/panel-2b-bundle/` | Extracted bundle (routes, migrations, seeders, 2FA/RBAC, 9 tests, composer.lock) |
| `agent/`, `cli/` | paneld agent (Python, 16/16 tests) + `alphacp` CLI v0.2.0 |
| `db/migrations/0001_core.sql` | Core DB schema |
| `tools/local-e2e-final.sh` | **Local test harness** — container me poora install + verify (worst-case) |
| `tools/patch-*.py`, `tools/merge-finish-stage.py` | Scripts jo installer/doctor ko patch karte hain (history) |
| `panel/` | Purana Laravel 11 UI (superseded — kabhi server par re-run nahi karna) |
| `panel-demo-preview.html` | Dashboard ka visual demo (browser me khulta hai) |
| `*.md` (baaki) | Planning docs: master plan, server guide, aws guide, requirement analysis, playbook |

---

## 6. Ab aage kya karna hai (order — isi order me karo)

1. **v1.6 doctor chalao** (server par): `curl -sSL https://paste.rs/G72oK -o /tmp/v16.sh && sudo bash /tmp/v16.sh`
   → expect: `PANEL READY ✅`
2. **Browser login**: `https://13.207.123.177:8090` → cert warning → Advanced → Proceed → `admin` / (password: `sudo cat /usr/local/alphacp/var/panel-admin.txt`)
   → forced password change → 2FA setup → dashboard = **Step 2B-1 acceptance complete**.
3. Installer **v0.3.7** banao: `step2b-setup.sh` me wahi systemd drop-in add karo (aur `install.sh` me bhi) + doctor v1.6 jaisa
   Step 2b/2c verification. Local harness `tools/local-e2e-final.sh` se test karo (harness me `ProtectSystem=full` mimic
   wali conf bhi add kar dena).
4. Parity checklist ke pending rows update karo (Step-2B-1 ke 10 rows: 58/59/86/91/92/94/171/184/196/203) + `project-status.md`,
   `CHANGELOG.md`, runbook.
5. **Step 2C** — license client + trial (docs/05-license-system.md ke hisaab se).
6. Phir ROADMAP ke S3 → S15 (provisioning engine → packages/limits → websites → files/FTP → email → DB → DNS → backup →
   monitoring → **billing API layer (WHM API 1 compatible)** → security → app installer → reseller/multi-server/updater).

---

## 7. Dead ends — jo cheezein DOBARA nahi karni (paisa/time bachao)

- ❌ **Laravel 11 nahi** — composer 2.10.3 ke saath hard block hai. Sirf **Laravel 13.33.0** (PHP 8.3+ chahiye).
- ❌ Purane installers/doctor **re-run mat karo**: `0r1Mi (v0.3.3)`, `VD0Px (v0.3.4)`, `vVdFC (v0.3.5)`, `EPW3b (v0.3.6 — chal chuka)`,
  doctor `wsPmr (v1.1)`, `vbVD9 (v1.3)`, `LxbJT (v1.4)`, `pnV7U (v1.5)` — **sab superseded**.
  Live sirf: installer `EPW3b` (agar re-run karna ho) aur **doctor `G72oK` (v1.6)**.
- ❌ Doctor me PHP picker **`ls -d /etc/php/*/fpm | head -1` mat use karo** — ye **sabse purana** PHP (7.4) uthata hai.
  Sahi: 8.5→8.4→8.3 me se pehla available, ya `artisan --version` se probe karo.
- ❌ Panel ke `public/` me temp `.php` probe files **404 deti hain** (vhost sirf `index.php` chalata hai) — CLI-as-alphacp se diagnose karo.
- ❌ Laravel boot bina `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap()` ke → `Target class [env] does not exist`.
- ❌ `LOG_CHANNEL=daily` matlab file `laravel-YYYY-MM-DD.log` (naam `laravel.log` nahi) — glob se newest lo.
- ❌ DB password sirf ek jagah sync karna kaafi nahi — **teeno**: `/usr/local/alphacp/etc/database.env` → `panel.env` + MariaDB user (@localhost **aur** @127.0.0.1) + config cache clear.
- ❌ Code tar `--strip-components=1` ke **bina** extract karna → nested `panel/panel` + stale composer.json (galti ho chuki).
- ❌ Installer ko non-root chalana → v0.3.5+ me EUID check hai (pehle partial delete ho jata tha).
- ❌ paste.rs limit: ~70 KB per POST (60 KB chunks theek), transient 503 → retry. bashupload/temp.sh/oshi.at/x0.at mar chuke.
- ❌ `set -e` + `[[ … ]] && cmd` = silent death (script me if-form use karo).
- ❌ Quota/`fstab` wale Step-1 fixes ke "must-keeps" mat todo (loopback-aware MySQL check, nginx band hone se pehle apache start nahi, etc.).

---

## 8. Important contracts (kabhi na todna)

- **Parity**: `docs/09-cpanel-parity-checklist.md` (208 items) — RULE 0: is checklist se koi row delete/modify nahi hoti.
  Meter: ✅5 / 🟡20 / ⏳173 / 🔵10 (row 172 verify karna hai).
- **Ports**: panel primary **8090**; compat ports 2082/2083 (UAPI-style), 2086/2087 (WHM API 1) — billing integration ke liye.
- **Billing API (Step 12)**: WHM API 1 `/json-api/<fn>` (2086/2087) `Authorization: whm USER:TOKEN`; core funcs: createacct,
  suspendacct, unsuspendacct, changepackage, passwd, editquota, showbw, removeacct, listaccts, accountsummary, listpkgs,
  addpkg, editpkg, killpkg, dumpzone, addzonerecord, editzonerecord, removezonerecord, adddns, killdns, suspend_outgoing_email.
  Saath me: UAPI-compat (2082/2083 `/execute/Module/function`) + native REST `/api/v1/*` + webhooks.
- **Package limit keys (cPanel-compatible)**: QUOTA, BWLIMIT, MAXPOP/FWD/RESP/PASS/LST/FTP/SQL/SUB/PARK/ADDON/CRON/INODE,
  MAILBOXQUOTA, DBQUOTA, MAXEMAILPERHOUR, MAXMSGSIZE, HASSHELL, DEDICATEDIP, CPULIMIT/RAMLIMIT/IOLIMIT, NPROCLIMIT/EPLIMIT.
- **Target stack**: Apache+Nginx, PHP-FPM 7.4–8.4 (MultiPHP), MariaDB, Exim+Dovecot+SpamAssassin+ClamAV+OpenDKIM,
  BIND9, Pure-FTPd+Jailkit, Redis, cgroups/quotas, fail2ban, ModSecurity.
- **License system**: panel apna license verify karega (trial + expiry + offline grace) — `docs/05-license-system.md`.

---

## 9. Kaise continue karo (naye AI ke liye practical steps)

1. Ye file + `alphacp-code-bundle.zip` lo. Zip kholo, `AI_CONTEXT.md`, `AGENTS.md`, `ROADMAP.md`, `project-status.md`,
   `CHANGELOG.md`, `docs/09-cpanel-parity-checklist.md` pehle padho.
2. Server par SSH karke current state verify karo: `curl -k -s -o /dev/null -w '%{http_code}\n' https://127.0.0.1:8090/`
3. Blocker fix karo (section 3) → phir Step 2B acceptance (section 6) → aage ROADMAP.
4. Naya code likhne se pehle `docs/04-coding-standards.md` + `docs/08-module-blueprint.md` follow karo, aur har change ke baad
   `CHANGELOG.md` + parity checklist + runbook update karo.
5. User ko Hindi me, ek-ek step, tested commands do. Har command me version print karo.

---

*Ye handoff 29 Sep 2026 ko banaya gaya. Jo bhi isme "PROVEN" likha hai woh actually reproduce + fix + verify kiya gaya hai;
jo "pending" hai woh saaf mention hai.*
