# 👋 START HERE — naye AI / developer ke liye (user se kuch mat poochho, yahan sab hai)

> **AlphaCP** = cPanel/WHM jaisa custom web hosting control panel (India + global hosting business ke liye).
> Iska apna license system hai (15 din ka trial), aur ye billing software se WHM API 1 compatible banega.
> Server: AWS Lightsail Mumbai, Ubuntu 24.04, 4 GB / 2 vCPU / 80 GB, static IP `13.207.123.177`, panel `https://<ip>:8090`.

## 1. Isi order me padho
| # | File | Kyun |
|---|---|---|
| 1 | **`server-snapshot/STATE.md`** | ⭐ **Server par ABHI kya chal raha hai.** Ye file server se har ghante automatic aati hai: versions, services, ports, migrations, routes, license/trial files, DB schema. **Sabse bharosemand source yahi hai.** |
| 2 | `server-snapshot/LAST-SYNC.md` | Aakhri sync kab hua. Purana lage to user se sirf `sudo alphacp-sync` chalwao. |
| 3 | `COMMANDS.md` | Server par chalne wali live commands (commit-pinned). Purani commands ki "mat chalao" list bhi yahin hai. |
| 4 | `CHANGELOG.md` | Kya kab bana/fix hua |
| 5 | `ROADMAP.md`, `project-status.md` | Steps S0–S15 aur unka status |
| 6 | `AI_CONTEXT.md`, `AGENTS.md`, `docs/04-coding-standards.md`, `docs/08-module-blueprint.md` | Rules aur architecture |
| 7 | `docs/09-cpanel-parity-checklist.md` | 208 items ka parity contract. **Koi row delete mat karna.** |
| 8 | `AI-HANDOFF.md` | Purani history: 500 bug ki asli wajah, dead ends |

## 2. Code kahan hai
| Path | Kya |
|---|---|
| **`refs/panel-2b-bundle/`** | ⭐ **Panel ka SOURCE (Laravel 13.33.0)** — login, RBAC, 2FA, **license + 15-day trial** (`app/Support/License/`, design: `docs/modules/license.md`), `PasswordGenerator`. Panel badalna ho to yahin badlo. |
| `artifacts/panel-code-<ver>.tar.gz` | Source ka reproducible build (`python3 tools/build-panel-2b-bundle.py`). Latest source: **0.70.0** (agent 0.62.0, real MySQL/MariaDB databases + users); server par abhi **0.69.0** (agent 0.61.0; HTTP 200). |
| `artifacts/panel-bundle-0.3.0.tar.gz` | Purana bundle **vendor/ ke saath** — sandbox tests isi ka vendor use karte hain (composer.lock same). |
| `server-snapshot/files/usr/local/alphacp/…` | **Server par jo ABHI deployed hai** (alphacp-sync se). Source se mismatch ho to server = sach; farq samjho phir source theek karo. |
| `server-snapshot/files/etc/...`, `server-snapshot/db-schema.sql` | nginx vhost, php-fpm pool, systemd drop-ins; DB structure (data nahi) |
| `installer/` | Server scripts: `panel-update.sh` (panel update + auto-rollback), `alphacp-sync.sh`, `panel-doctor.sh`, installers (`step2b-finish.sh` = source, `step2b-setup.sh` = generated) |
| `tools/sim/` | Tests jo har command dene se pehle chalte hain (neeche §4b) |
| `panel/`, `agent/`, `cli/`, `license-server/` | Purana scaffold / agla kaam (license-server API abhi pending) |

`server-snapshot/` me **secrets nahi hain**, jaise `.env`, DB password, APP_KEY, license keys, admin password.
Unke sirf naam aur keys `STATE.md` me likhe hain. Values server par hi rehti hain.

## 3. User ke saath kaam karne ke rules (BINDING)
1. Jawab **Hindi/Hinglish** me do.
2. Ek message me **sirf EK command/step** do. Output maango, phir aage badho.
3. **Untested command kabhi mat do.** Pehle reproduce karo, phir fix, phir `tools/sim/` me verify karo, uske baad hi command do.
4. Har script apna **version banner** print kare. User scrollback se purani command chala deta hai.
5. Command hamesha **commit-pinned + sha256-verified** ho. Repo PRIVATE ho sakta hai, isliye ye format (deploy key se):
   `sudo alphacp-sync get <COMMIT> installer/<script>.sh /tmp/<script>-<ver>.sh <SHA256> && sudo bash /tmp/<script>-<ver>.sh`
   (Repo public ho tabhi `curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/<COMMIT>/...` chalta hai.)
   Command dene se pehle `gh api .../contents/<path>?ref=<COMMIT>` se check karo ki GitHub copy wahi file hai jo test hui thi.
   Pinned commits hamesha reachable rahein: PR merge "Create a merge commit" se (squash bhi chalega — `get` PR refs bhi laata hai).
6. Server par kuch bhi badalne wali har script ke **end me `alphacp-sync` chalao**, taaki GitHub apne aap update ho jaye:
   `command -v alphacp-sync >/dev/null && alphacp-sync || true`
7. `COMMANDS.md` + `CHANGELOG.md` update karo. Parity checklist me jo row ho gayi ho use ✅/🟡 karo.

## 4. GitHub ↔ server sync kaise chalta hai
- `installer/alphacp-sync.sh` server par ek baar setup hota hai. Deploy key banti hai aur har ghante ek timer chalta hai.
- Sync secrets hata kar snapshot banata hai aur **`main` branch ke `server-snapshot/`** folder me push karta hai. Badlav na ho to commit nahi karta.
- Manual sync: `sudo alphacp-sync`. Status dekhna ho to: `sudo alphacp-sync --status`
- Server sirf `server-snapshot/` ko chhoota hai. Baaki repo AI/dev ka hai, isliye conflict nahi hota.

## 4b. Tests (sab sandbox me chalte hain — system PHP/MySQL ki zaroorat nahi)
| Command | Kya test karta hai | Last result |
|---|---|---|
| `bash tools/sim/panel-tests.sh` | Panel PHPUnit suite (php-wasm PHP 8.5, SQLite) — panel 0.70.0 artifact | **434/0, 6 skip** |
| `bash tools/sim/provision-sim.sh` | Agent account + domain + php/cron/ssl/ini/errorpages/indexes/mime/handlers/files/privacy/usage/ssh/mail/forward/autorespond/catchall/filter/deliverability/spam/list/routing/track/gfilter/encrypt/boxtrapper/calendar/mail.usage/webmail/db.set/db.phpmyadmin/db.remote/dns.zone/dns.dynamic/dns.track/dns.hostname/dns.templates/mail.globalrouting/dns.nsreport/dns.park/dns.cleanup/dns.ttl/dns.forward/dns.sync/dns.nameserver/backup.create/backup.archive/backup.wizard/backup.restore/backup.config/backup.restoration/backup.users/backup.filedir/backup.transfer/backup.cpanel/backup.review/backup.extract tasks | **98/98** (symlink-escape + safe-restore + cpmove-import tests) |
| `bash tools/sim/backup-tar-sim.sh` | Native GNU tar create/list/extract/subtree-extract, symlink preservation, hostile path-escape visibility, SHA-256 smoke test (v0.2.0) | **PASS** |
| `bash tools/sim/mysql-sim.sh` | Asli `db.*` handlers (php-wasm) se generated MariaDB SQL ka lint: statement verbs, balanced quoting, backticked account-prefixed identifiers, argv me kabhi identifier/password nahi, hostile input se **koi SQL nahi** (v0.70.0) | **PASS** |
| `bash tools/sim/cpanel-import-sim.sh` | Real GNU tar (cpmove / legacy / nested layout) + asli `CpanelArchive` class (php-wasm): genuine archive accept, path-escape / hardlink / symlink-traversal reject (v0.69.0) | **PASS** |
| `bash tools/sim/cpanel-import-e2e.sh` | Asli `BackupArchiveStore` + real `CommandRunner` + real tar, throwaway accounts root: home swap + `.acp-prerestore-*` copy + symlink preserved + staging cleanup, aur 7 hostile archives (escape / write-through / hardlink / doosra account / galat sha256 / missing / not-a-tar) fail closed (v0.69.0) | **PASS** |
| `sudo bash tools/sim/update-sim.sh` | `panel-update.sh` 0.70.0: panel 0.70.0 + agent 0.62.0, pinned SHA, open_basedir, mismatch abort, rollback, pruning, sync-tool, **private repo (get)**, **cron + import drop dir (U8)**, **S8 MySQL tasks (U9)** | **UPDOK/UPDTOT** |
| `sudo bash tools/sim/doctor-sim.sh` | panel-doctor v1.7 (ProtectSystem 500 fix, leaked password rotate) | **21/21** |
| `sudo bash tools/sim/sync-sim.sh` | alphacp-sync v1.2 (secret leak attempts, releases/ exclude, license state, rebase, deploy-key flow, 443 fallback, `get`) | **60/60** |

php-wasm ki limits (code ki galti NAHI): PHP 8.4 wasm PHPUnit me crash karta hai → 8.5 use hota hai; Mockery
console-output mock crash karta hai → runner temp copy me `$mockConsoleOutput=false` lagata hai, isliye
`AdminPasswordCommandTest` ke 5 PendingCommand tests aur `ConfigBootTest` (child `php` process) "wasm-skip"
hote hain. Ek hi phpunit run me poora suite crash karta hai → runner har file alag chalata hai.
Real server (PHP 8.4 FPM) par poora suite: `cd /usr/local/alphacp/panel && sudo -u alphacp php artisan test` (dev deps chahiye).

## 4c. Panel update kaise bhejein (recipe)
1. `refs/panel-2b-bundle/` me change + test likho; `MANIFEST.json` aur `config/acp.php` me version bump.
2. `python3 tools/build-panel-2b-bundle.py` → `artifacts/panel-code-<ver>.tar.gz` (sha256 print hota hai).
3. `bash tools/sim/panel-tests.sh` → 0 fail. Commit + push (commit **A**).
4. `installer/panel-update.sh` me `UPDATER_VERSION`, `PANEL_VERSION`, `BUNDLE_URL` (commit **A** ka raw link), `BUNDLE_SHA256` badlo.
5. `sudo bash tools/sim/update-sim.sh` → sab PASS. Commit + push (commit **B**). `gh api` se GitHub copy verify karo.
6. `COMMANDS.md` me commit **B** ka link. Updater end me `alphacp-sync` khud chalata hai → GitHub bhi update.
   (Sync tool badla ho to updater ke `SYNC_TOOL_VERSION/URL/SHA256` bhi badlo — updater use upgrade kar deta hai.)

⚠️ Dusre AI kabhi-kabhi alag `arena/*` branch par push karte hain. Shuru me `git ls-remote origin` dekho, aur
unka kaam PR se `main` me merge karo (29 Sep: `arena/01a0ea0d-alphacp` ka S2C kaam PR #1 me merge hua).

## 5. Abhi kahan hain (roadmap position)
- Step 0–9 ke UI/config slices repo me hain; kuch host backends abhi JSON-only hain. `ROADMAP.md` me S7 mail, S8 database, aur S9 BIND gaps dekho.
- `server-snapshot/STATE.md` ki latest committed state (sync 2026-10-04 02:44 UTC): server par panel **0.68.0**, agent **0.60.0**, Laravel 13.33.0, PHP 8.4.26, MariaDB 10.11; HTTP 200. **0.69.0 (S10 real cPanel account import + transfer job history) deploy pending hai** — COMMANDS.md ka NEXT STEP.
- Deployed S10 slice real home-directory `.tar.gz` create + checksum-verified customer download + safe restore + **cron-scheduled backups (0.68.0)** provide karta hai; 0.69.0 usi ke upar **cpmove/legacy/nested cPanel archive import** (Transfer Tool + Transfer or Restore) aur **real transfer/restore job history** jodta hai. Live customer archive/download/restore abhi end-to-end verify nahi hua.
- S10 abhi **partial** hai: archive ka MySQL/mail/DNS hissa import nahi hota (job result me sections dikhte hain), purane server se direct pull nahi hota, aur remote backup destinations baaki hain — restore/scheduler/import/history me ho gaye. Checklist me 🟡 rows ko ✅ na samjho.
- Deploy command aur tested commit/SHA ke liye `COMMANDS.md` dekho. Server par kuch chalane se pehle usme likhi **ek hi next command** follow karo.

Latest server status ke liye hamesha `server-snapshot/STATE.md` dekho; repo implementation ke liye `CHANGELOG.md` + `docs/09-cpanel-parity-checklist.md`.
