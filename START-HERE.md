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
| **`server-snapshot/files/usr/local/alphacp/panel/`** | ⭐ **Panel ka ASLI SOURCE (0.75.0, 420 files)** — jo abhi server par chal raha hai. **Panel me kuch bhi badalna ho to yahin badlo.** |
| ⚠️ `refs/panel-2b-bundle/` | **PURANA (0.3.2, 95 files) — 6 Oct 2026 tak.** Step 2B ka snapshot. **Isse artifact BANA KAR DEPLOY MAT KARO** — server 0.75.0 par hai aur 0.3.2 bhejne se 325 files ka kaam mit jayega. Neeche §2b dekho. |
| `artifacts/panel-code-<ver>.tar.gz` | Source ka reproducible build (`python3 tools/build-panel-2b-bundle.py`). Server par yahi deploy hota hai. Latest: **0.3.2**. |
| `artifacts/panel-bundle-0.3.0.tar.gz` | Purana bundle **vendor/ ke saath** — sandbox tests isi ka vendor use karte hain (composer.lock same). |
| `server-snapshot/files/usr/local/alphacp/…` | **Server par jo ABHI deployed hai** (alphacp-sync se). Source se mismatch ho to server = sach; farq samjho phir source theek karo. |
| `server-snapshot/files/etc/...`, `server-snapshot/db-schema.sql` | nginx vhost, php-fpm pool, systemd drop-ins; DB structure (data nahi) |
| `installer/` | Server scripts: `panel-update.sh` (panel update + auto-rollback), `alphacp-sync.sh`, `panel-doctor.sh`, installers (`step2b-finish.sh` = source, `step2b-setup.sh` = generated) |
| `tools/sim/` | Tests jo har command dene se pehle chalte hain (neeche §4b) |
| `panel/`, `agent/`, `cli/`, `license-server/` | Purana scaffold / agla kaam (license-server API abhi pending) |

`server-snapshot/` me **secrets nahi hain**, jaise `.env`, DB password, APP_KEY, license keys, admin password.
Unke sirf naam aur keys `STATE.md` me likhe hain. Values server par hi rehti hain.

## 2b. ⚠️ Source of truth ka farq (6 Oct 2026 ko verify kiya — zaroor padho)

| Kahan | Panel version | Files | Kya karein |
|---|---|---|---|
| `server-snapshot/files/usr/local/alphacp/panel/` | **0.75.0** (`ACP_VERSION` 0.83.0) | 420 | ⭐ **Yahi source hai.** Yahan badlo. |
| `refs/panel-2b-bundle/` | 0.3.2 | 95 | ❌ Deploy artifact mat banao. Sirf history. |
| `panel/` | — | — | ❌ Sabse purana scaffold. Chhoo mat. |

**Kyun:** Step 2B ke baad ka poora kaam (Steps 3–10: accounts, packages, domains, email, MySQL,
DNS, backups, SSL, files…) seedha server par hua aur `alphacp-sync` use `server-snapshot/` me
laata raha. `refs/panel-2b-bundle/` wahin 29 Sep par atka reh gaya.

**Iska matlab:**
1. `python3 tools/build-panel-2b-bundle.py` **abhi mat chalao** — wo `refs/` se 0.3.2 ka artifact
   banayega, aur `installer/panel-update.sh` se deploy karne par server 0.75.0 → 0.3.2 par
   **wapas chala jayega** (updater ka auto-rollback sirf fail par chalta hai, is par nahi).
2. Deploy karne se pehle pehle `refs/panel-2b-bundle/` ko server ke snapshot se **refresh** karo:
   ```bash
   rsync -a --delete --exclude=vendor --exclude=storage --exclude=.env \
     server-snapshot/files/usr/local/alphacp/panel/ refs/panel-2b-bundle/
   ```
   Phir `tools/sim/panel-tests-deployed.sh` chalao (0 fail chahiye), tab hi artifact banao.
3. Snapshot me panel ki **4 files missing** thi (sync v1.2 ka bug — CHANGELOG 6 Oct dekho).
   `server-snapshot/.../views/{backup,ssl,backup-destinations,transfer-tool}/index.blade.php`
   repo me dobara bana di gayi hain; `sudo alphacp-sync` (v1.3) chalane ke baad server ki asli
   files apne aap aa jayengi aur inhe overwrite kar dengi.

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
| ⭐ `bash tools/sim/panel-tests-deployed.sh` | **Poora PHPUnit suite us code par jo server par deployed hai** (`server-snapshot/…/panel`, 0.75.0, 74 test files) | **434 pass, 0 fail, 6 wasm-skip** (6 Oct) |
| `bash tools/sim/panel-tests.sh` | Wahi suite, par `artifacts/panel-code-*.tar.gz` par (abhi 0.3.2 = purana) | 42 pass, 0 fail, 6 wasm-skip |
| `sudo bash tools/sim/update-sim.sh` | `panel-update.sh` 0.3.0: update, sha mismatch, rollback, backup prune, sync-tool upgrade, **private repo (get)** | **54/54** |
| `sudo bash tools/sim/doctor-sim.sh` | panel-doctor v1.7 (ProtectSystem 500 fix, leaked password rotate) | **21/21** |
| `sudo bash tools/sim/sync-sim.sh` | alphacp-sync **v1.5** (secret leak attempts, **snapshot completeness**, config-only pattern-scan (source par sirf literal value-scan), runtime-junk prune, releases/ exclude, license state, rebase, deploy-key flow, 443 fallback, `get`) | **72/72** |

php-wasm ki limits (code ki galti NAHI): PHP 8.4 wasm PHPUnit me crash karta hai → 8.5 use hota hai; Mockery
console-output mock crash karta hai → runner temp copy me `$mockConsoleOutput=false` lagata hai, isliye
`AdminPasswordCommandTest` ke 5 PendingCommand tests aur `ConfigBootTest` (child `php` process) "wasm-skip"
hote hain. Ek hi phpunit run me poora suite crash karta hai → runner har file alag chalata hai.
Real server (PHP 8.4 FPM) par poora suite: `cd /usr/local/alphacp/panel && sudo -u alphacp php artisan test` (dev deps chahiye).

## 4c. Panel update kaise bhejein (recipe)
0. ⚠️ **PEHLA STEP (6 Oct se zaroori):** `refs/panel-2b-bundle/` server se **refresh** karo, warna
   purana 0.3.2 deploy ho jayega:
   ```bash
   rsync -a --delete --exclude=vendor --exclude=storage --exclude=.env \
     server-snapshot/files/usr/local/alphacp/panel/ refs/panel-2b-bundle/
   bash tools/sim/panel-tests-deployed.sh        # 0 fail hona chahiye
   ```
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
**Last verified: 6 Oct 2026, `server-snapshot/STATE.md` (sync 08:47Z) + sandbox me poora test suite chala kar.**

Server par abhi:
- panel code **0.75.0** (`MANIFEST.json`), `.env` me `ACP_VERSION` / `ACP_AGENT_VERSION` = **0.83.0**, step **5**
- Laravel 13.33.0 · PHP 8.4.26 (7.4/8.1/8.2/8.3/8.4 installed) · MariaDB 10.11.14
- nginx 1.24.0 (panel, **:8090**) + Apache 2.4.58 (80/443) · named · exim4 · dovecot · spamd · redis · fail2ban · paneld
- `panel http : 200` · 56 migrations Ran · ~150 web routes
- License: `local_trial`, tier trial, max 20 accounts, **expiry 2026-10-13** (signed: no)

Code health (sandbox me verify kiya, php-wasm PHP 8.5):
- 657 PHP files par syntax check → **0 error**
- Deployed panel ka poora PHPUnit suite → **434 pass, 0 fail, 6 wasm-skip**

Steps:
- Step 0 → 2B ✅ · Step 2C 🟡 (client + offline trial deployed; apna `license-server/` API abhi pending — folder me sirf README hai)
- Steps 3–10 ka code server par **maujood hai** (accounts, packages, domains, email, MySQL, DNS, backups,
  SSL, files, transfers) — snapshot ki 414 files aur 150 routes iske proof hain.
  Par `docs/09-cpanel-parity-checklist.md` **update nahi hua** (abhi bhi 14 ✅ / 21 🟡 / 174 pending dikha
  raha hai aur "Updated: Step 0, 1, 2A" likha hai). Aage badhne se pehle use server ki asli haalat se
  milao — bina verify kiye rows mat badalna.
- ⚠️ Repo ka `refs/panel-2b-bundle/` 0.3.2 par atka hai — §2b dekho. Deploy se pehle refresh zaroori.
- **Next command:** alphacp-sync **v1.5** (`COMMANDS.md`) — source par pattern-scan band, taaki repo = server ho jaye.

Latest status ke liye hamesha `server-snapshot/STATE.md` + `CHANGELOG.md` dekho.
