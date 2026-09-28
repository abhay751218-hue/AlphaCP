# Runbook — Step 2B: panel deploy on dev-srv1 (working path)

**Status:** ✅ **INSTALLED ON dev-srv1 (29 Sep 2026 00:02)** — `https://13.207.123.177:8090` live, admin `admin`, password `/root/.alphacp-admin-credentials` me; installer v0.3.3 = https://paste.rs/0r1Mi (yahi chalaya gaya) · **Panel:** v0.3.0 (Laravel 13.33.0)
**Script:** `installer/step2b-setup.sh` (v0.3.8 — active panel artifact + permanent php-fpm sandbox fix) · **Source:** `installer/step2b-finish.sh` + generated `installer/step2b-setup.sh`
**Install kiya gaya version (dev-srv1):** v0.3.3 = https://paste.rs/0r1Mi
**Panel URL:** `https://13.207.123.177:8090` · panel user: `alphacp` (never root)

---

## 1. Ek line me

```bash
curl -sSL https://paste.rs/vVdFC -o /tmp/setup.sh && sudo nohup bash /tmp/setup.sh > /tmp/setup.log 2>&1 & sleep 100; tail -40 /tmp/setup.log
```

Script khud: panel code download (checksum verified) → composer install → database →
migrations/seeders → admin password → php-fpm pool → nginx vhost :8090 → verify HTTP 200.
Aakhir me **URL + username + password** print hota hai (aur `/root/.alphacp-admin-credentials` me bhi).

## 2. Yeh script kya-kya karti hai

| Stage | Kaam |
|---|---|
| **A** | panel code (3 paste.rs chunks → sha256 `61c46d6d…` verify) → `/usr/local/alphacp/panel` (purana hata kar, `--strip-components=1`) → runtime dirs → `composer install --no-dev` |
| **B1** | OS user `alphacp` (nologin) + DB `alphacp`/`alphacp_test` + grants |
| **B2** | `etc/panel.env` (0640 root:alphacp) + `panel/.env` (0640 alphacp) + APP_KEY |
| **B3** | `artisan migrate --force` → `db:seed --force` → `alphacp:admin-password` (random, first login par change) |
| **B4** | php-fpm pool `alphacp` (socket `/run/php/alphacp-fpm.sock`) |
| **B5** | self-signed TLS + nginx vhost **:8090** + **nginx default site (port 80) hata diya** (port 80 = Apache) |
| **B6** | verify: `https://127.0.0.1:8090/` HTTP 200 + login page text check + Apache check |

**Safe to re-run:** code/composer/config dobara bante hain, par **admin password aur APP_KEY rotate nahi hote** —
dono `${ACP_HOME}/var/panel-admin.txt` + `panel-appkey.txt` (0600) me sambhal kar rakhe jate hain, kyunki
panel/.env har run me wipe hota hai (fresh extract). Password chupana hi pad jaye to
`sudo cat /usr/local/alphacp/var/panel-admin.txt`. Jaan-boojh kar badalna: `sudo ADMIN_PASSWORD='NayaPass' bash /tmp/setup.sh`
Password jaan-boojh kar badalna ho: `sudo ADMIN_PASSWORD='NayaPass' bash /tmp/setup.sh`

## 3. Raste me jo asli problems thi (yaad rakho)

1. **composer 2.10.3 (Aug 2026) Laravel 11 ko block karta hai** — `policy.advisories` ki wajah se
   `laravel/framework ^11.x` install hi nahi hota ("could not be resolved to an installable set").
   → Isliye panel **Laravel 13** par hai. Purana Laravel-11 installer (`panel-install.sh`) ab **use nahi karna**.
2. **x0.at server se reachable nahi** (IPv4: 403, IPv6: fail) — panel bundle wahan se nahi aata.
   → Code paste.rs ke 3 chunks se aata hai (paste.rs server se chalta hai — verified).
3. **tar nesting** — bundle ke andar `panel/` folder hai; extract `--strip-components=1` ke saath,
   warna `panel/panel/` ban jata hai aur composer purana `composer.json` padhta hai.
4. **nginx 1.24 me `http2 on;` directive nahi hota** (1.25.1+ me aaya) — script version check karke
   us line ko hata deti hai.
5. **PHP chunav (28 Sep)**: Step-1 ka `ACP_PHP_PRIMARY=8.3` record sahi PHP nahi hota — `php8.3` me
   extensions missing the, isliye `artisan key:generate` fail hua. Script ab **guess nahi karti**: har
   candidate PHP se `artisan --version` try karti hai, jo chale wahi use karti hai (aur uske
   extensions khud install karti hai). Log me line dekho: `[OK] panel PHP: /usr/bin/php8.4 (8.4.x)`.
6. **nginx default site :80 par baithta hai** (Apache wahan hai) → nginx start fail. Script default
   site ko `/root/nginx-default-site.disabled.<ts>` me move kar deti hai.

## 4. Login ke baad ka flow (panel ka apna design)

`admin` + printed password → **Change Password** page (forced) → dashboard →
Security me **2FA setup** → User Manager se reseller/team users banao.

Panel me jo modules abhi live hain: Dashboard (live server data paneld se), System (services/tasks),
Users (User Manager), Audit log, Security (password / 2FA / sessions).

## 4b. Install ke baad ki asli output (dev-srv1, 29 Sep)

```
==> Stage A — panel code (Laravel 13) + composer install
[OK] panel code verified (sha256 61c46d6dd8e1ec1c…)
[OK] composer install done — Laravel Framework 13.33.0
==> Stage B — Step 2B finishing (database + admin + nginx :8090)
[OK] panel PHP: /usr/bin/php8.4 (8.4.x)
[OK] nginx active — panel port 8090 par
[OK] customer websites still served by Apache (HTTP 200)
==> Step 2B complete — panel ready 🎉
  Panel URL : https://13.207.123.177:8090/
```

(`job-working-directory: getcwd ...` wali lines cosmetic hain — script purani `${PANEL_ROOT}` delete karti hai
jab shell ka cwd usi ke andar ho. v0.3.4 me `cd /` add kar diya, panel par koi asar nahi.)

## 4c. v0.3.5 (aakhri) — single-shot installer

- `cd /` sabse pehle (jab user ki shell panel dir me hoti hai jo script delete karti hai → "getcwd" spam band)
- `/etc/hosts` me hostname entry ("sudo: unable to resolve host …" noise band)
- root check **stage A se pehle** (bina sudo chalane par adhoora delete nahi hota — bug fixed)
- admin password hamesha **known** (`ADMIN_PASSWORD=...` diya hua) aur `var/panel-admin.txt` me save
- APP_KEY preserve (2FA secrets nahi tootte)
- verify 60s tak + 500/502 par php-fpm/nginx ek baar fresh restart
- fail par: nginx error log + php-fpm log + laravel.log + storage perms — sab ek saath print
- aakhir me: **`==> VERDICT: PANEL READY ✅`** ya **`VERDICT: PANEL NE 200 NAHI DIYA ❌`**

## 4d. HTTP 500 ka asli reason (v0.3.6 fix)

Panel 500 de raha tha kyunki **root se chalayi gayi `artisan` commands `storage/logs/laravel.log`
ko root ka file bana deti hain**, aur php-fpm (user `alphacp`) usme append nahi kar pata →
har request 500. Fix: saari artisan commands ab `runuser -u alphacp --` se chalti hain, aur
aakhir me `storage/` + `bootstrap/cache` ka chown **+ chmod (0770/0660)** hota hai.
Locally reproduce karke verify kiya: 500 → script → 200 ✅.

## 4j. v0.3.8 — fresh installs ka active bundle + permanent sandbox fix

Doctor v1.6 ne existing server ka 500 fix kar diya tha. Ab installer me bhi wahi protection
permanently add hai, taaki naye Ubuntu/Ondrej server par pehli request se pehle hi problem na aaye.
Installer ab repository ke active Laravel 13 code artifact ko SHA-256 verify karke download karta hai,
isliye S2C license/trial code bhi fresh install me aata hai.
`step2b-finish.sh` PHP version choose karne ke baad, pool file likhne ke turant baad:

```ini
[Service]
ReadWritePaths=-/usr/local/alphacp
ReadWritePaths=-/run/php
```

Systemd available ho to installer drop-in ko
`/etc/systemd/system/phpX.Y-fpm.service.d/alphacp-panel.conf` me rakhta hai, `daemon-reload`
karta hai, aur uske baad hi PHP-FPM restart karta hai. Non-systemd container/test mode me
installer safe tarike se drop-in skip karta hai. Script version **0.3.7** hai; generated script
`python3 tools/build-panel-2b-bundle.py` ke baad `python3 tools/merge-finish-stage.py` se dobara ban sakti hai.

Validation performed:

- `python3 tools/build-panel-2b-bundle.py` successful — artifact SHA-256 `32fe68cce8868d05a23b962821acf20d19e4f56b4d8711140b40aaa063b6494c`
- `python3 tools/merge-finish-stage.py` successful
- generated script me active artifact URL, SHA-256 aur `ReadWritePaths` present
- `bash -n installer/step2b-finish.sh` ✅
- `bash -n installer/step2b-setup.sh` ✅

## 4i. ASLI JAD — systemd sandbox + doctor v1.6 (`G72oK`)  [29 Sep, PROVEN]
Ubuntu/Ondrej ka php8.4-fpm unit `ProtectSystem=full` lagata hai → **/usr read-only** →
hamara panel `/usr/local/alphacp/panel` me hai → php-fpm worker kuch bhi likh nahi paata
(log file, session, compiled view) → **har web request 500**. CLI (runuser) par ye bandish
nahi lagti → isliye har probe "sab OK" dikhata tha. Yahi wajah thi ki perms/DB/installer ke
saare fixes kaam nahi kar rahe the.

Fix (permanent, reboot-safe):
```
/etc/systemd/system/php8.4-fpm.service.d/alphacp-panel.conf
[Service]
ReadWritePaths=-/usr/local/alphacp
ReadWritePaths=-/run/php
```
`systemctl daemon-reload && systemctl restart php8.4-fpm`

Locally PROVEN (29 Sep, container me ProtectSystem=full lagakar dev-srv1 jaisi condition banayi):
- views wipe + sandbox → **HTTP 500** (laravel log file banti hi nahi — server jaisa exact signature)
- doctor v1.6 → Step 2b sandbox detect + Step 2c nsenter write-test (`Read-only file system`) →
  Step 5a drop-in → **HTTP 200 → PANEL READY ✅**
- regression: galat DB password → 5b 3-jagah sync → 200; login POST 302 → `/security/password` ✅

**Follow-up (zaroori):** installer `step2b-setup.sh` v0.3.7 me yahi drop-in add karo, warna
kisi bhi naye Ubuntu+Ondrej server par fresh install bhi 500 dega.

## 4h. panel-doctor v1.5 (`pnV7U`) — ek command me poori fix-chain

> Script ke shuru me **banner** print hota hai: `AlphaCP PANEL DOCTOR - v1.5 (NAYA version)` —
> yahi sabse pakka check hai ki sahi command chali. Verdict ke neeche bhi `— panel-doctor v1.5` aata hai.

Step 1 runtime saaf (logs/views/sessions/config-cache) + perms → Step 2 php-fpm audit (`<8.3` pools disable,
missing pool khud bana deta hai, serving pool ki `open_basedir` padhta hai) → Step 3 hit → Step 4 CLI + HTTP
probe (asli exception) → Step 5 auto-heal chain (DB password 3 jagah sync / sessions migrate / **LOG_CHANNEL=stderr
fallback**) → Step 6 cache rebuild → VERDICT. Har heal ke baad dobara test hota hai.

Locally verified (29 Sep): stale `php7.4` pool + galat DB password wali 500-state → doctor → 200 → login →
Change Password page ✅

## 4g. ASLI 500 ki jad (dev-srv1, 29 Sep 01:00) — stale php7.4 pool

Server par PHP 7.4–8.4 sab installed hain (Step 1). Panel ka vhost socket `/run/php/alphacp-fpm.sock`
use karta hai — aur **php7.4-fpm ka ek purana alphacp pool usi socket par baith gaya tha**, isliye 8090 ki
har request PHP 7.4 par chalti thi. Laravel 13 ko **PHP 8.3+** chahiye → app boot hote hi 500, aur
Monolog log bhi likh nahi paata (`RotatingFileHandler->write()` nginx error log me).

**Fix (doctor v1.4 `LxbJT`):** saare `/etc/php/*/fpm/pool.d/alphacp.conf` dhoondho; jo PHP **< 8.3** wale hain
unhe `.disabled-<ts>` kar do + woh fpm stop/disable karo; phir 8.3+ wale fpm ko restart karke socket dobara banwao;
socket ka owner (`php-fpm: pool alphacp`) print karo. Locally verify: fake 7.4 pool banaya → doctor ne hataya → 200 ✅

## 4e. Browser me HTTP 500 (permission repair — bina reinstall)

Root se chalayi gayi artisan commands `storage/logs` + `bootstrap/cache` ko root-owned kar deti hain;
php-fpm (`alphacp`) likh nahi paata → **har page 500**. Turant ilaaj (reinstall ki zarurat nahi):

```bash
sudo bash installer/panel-perm-fix.sh
```

Ya ek line me:
```bash
sudo chown -R alphacp:alphacp /usr/local/alphacp/panel/storage /usr/local/alphacp/panel/bootstrap/cache && sudo find /usr/local/alphacp/panel/storage /usr/local/alphacp/panel/bootstrap/cache -type d -exec chmod 0770 {} \; && sudo find /usr/local/alphacp/panel/storage /usr/local/alphacp/panel/bootstrap/cache -type f -exec chmod 0660 {} \; && sudo rm -f /usr/local/alphacp/panel/storage/logs/*.log && sudo systemctl restart php8.4-fpm; curl -k -s -o /dev/null -w "==> PANEL: HTTP %{http_code}\n" https://127.0.0.1:8090/
```

Locally verify kiya: root-owned log file wali halat me 500 → yeh fix → 200.

## 4f. panel-doctor.sh — ek command me heal + asli error (v1.1)

```bash
curl -sSL https://paste.rs/G72oK -o /tmp/doctor.sh && sudo bash /tmp/doctor.sh
```

**v1.3 (verified):** heal + php-fpm restart + 10x hit + (agar 500) CLI & HTTP-level **asli error** (debug
temporarily ON, phir .env restore) + **auto-heal**: `Access denied for user` mile to `database.env` ka password
**teeno jagah** sync hota hai (panel.env + MariaDB user @localhost/@127.0.0.1) + config cache clear;
`sessions table missing` mile to `artisan migrate --force`. Teeno scenario locally verify:
(a) healthy → READY ✅ (b) root-owned logs → 200 ✅ (c) DB password mismatch → 200 ✅.

Kya karta hai: (1) storage + bootstrap/cache ka permission heal (chown alphacp → 0770/0660), purani logs hatao;
(2) php-fpm restart; (3) panel ko 10 baar hit (500 par auto-restart); (4) agar 200 nahi aaya to **Laravel ko
`alphacp` user ke roop me, php-fpm ke apne `open_basedir`/ini ke saath CLI se boot** karke **asli exception**
print karta hai + `laravel-*.log` (dated) aur nginx error log ki tail; (5) `VERDICT: PANEL READY ✅/❌`.

Locally verify kiya (do scenarios):
- root-owned log/cache state → doctor ne heal kiya → **HTTP 200** ✅
- jaan-boojh kar DB password bigaada → doctor ne pakda: `SQLSTATE[HY000] [1045] Access denied for user 'alphacp'@'localhost'` ✅

**Sabak (dono installers me fix):** Laravel `LOG_CHANNEL=daily` par **dated file** `laravel-YYYY-MM-DD.log` likhta hai,
`laravel.log` nahi — isliye purane diagnostics khaali dikh rahe the.

## 5. Agar kuch fail ho

```bash
tail -40 /tmp/setup.log
sudo tail -20 /var/log/alphacp-step2b-finish.log
cd /usr/local/alphacp/panel && sudo tail -20 storage/logs/laravel.log
sudo nginx -t ; sudo ss -ltnp | grep 8090
```

## 6. AI handoff note

- Panel code repo me: `refs/panel-2b-bundle/` (vendor ke bina) — asli bundle `artifacts/panel-bundle-0.3.0.tar.gz`
  (sha256 `c67129165c6a8784cb12ec98b50f47a92ad7bfc89f27b55d6b8e5f47cb98c3ce`), code-only `artifacts/panel-code-0.3.0.tar.gz`
  (sha256 `61c46d6dd8e1ec1c74bc65cc6e49f575c5d21f999f6ca5c53b701b655c2f5a6c`).
- Purane/obsolete: `installer/panel-install.sh` (Laravel 11 — composer block), `5wvbs` step2b installer
  (x0.at bundle unreachable). Dono ko dobara mat chalao.
- Naya panel code badalne par: bundle rebuild + chunks re-upload + `tools/merge-finish-stage.py` chala kar
  `installer/step2b-setup.sh` regenerate karo.
