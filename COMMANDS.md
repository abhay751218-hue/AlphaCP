# COMMANDS.md — server par chalane wali LIVE commands (sirf yahi chalao)

> Har command ek **commit-pinned GitHub link** se script download karti hai — link kabhi badalta nahi,
> aur jo file test hui thi wahi server par aati hai (byte-for-byte). paste.rs ab use nahi hota.
> Har script shuru me apna **version banner** print karti hai — banner me wahi version dikhna chahiye
> jo yahan likha hai. Purani (superseded) commands scrollback se **dobara mat chalao**.

## 🔐 Command format (repo PRIVATE ho ya public — dono me chalta hai)
Server ki deploy key se file aati hai + sha256 check (alphacp-sync v1.2+ chahiye):
```bash
sudo alphacp-sync get <COMMIT-40-char> installer/<script>.sh /tmp/<script>-<ver>.sh <SHA256> && sudo bash /tmp/<script>-<ver>.sh
```
(Public repo ke zamane ka `curl https://raw.githubusercontent.com/...` format private repo par **404** dega.)

## ✅ Abhi chalani hai (NEXT STEP)

### d3-mysql-fix v1.0 — Depth Wave D3: MySQL Databases + Users + phpMyAdmin cPanel-depth (09 Oct 2026)
MySQL Users: APNA password choose karo (ya Generate — SQL-safe alnum 10–64), create aur
change-password dono me; password sirf ek baar dikhega + copy chips. Databases page:
"Privileged users" column, stats vs MAXSQL, search, connection-settings card. Add User To
Database (grant) card. phpMyAdmin: status + client-connect card. 6 files (2 controllers +
3 views + panel.js v1.3) — routes/DB change NAHI. Backup + auto-rollback.
```bash
sudo alphacp-sync get c2cb9da1db9595fdc5258a153358254a49445faa installer/d3-mysql-fix.sh /tmp/d3-mysql-fix-v1.0.sh 61a239ab7c42824e4585abe6c1783337ca256708d5cbed775304b6c83a5011f8 && sudo bash /tmp/d3-mysql-fix-v1.0.sh
```
- sha256: `61a239ab7c42824e4585abe6c1783337ca256708d5cbed775304b6c83a5011f8`
- Expected: banner `v1.0` → pre-check 3×200 → 6× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D3 MYSQL DEPTH FIX COMPLETE ✅`
- Phir 2083 → MySQL Databases / MySQL Users hard-refresh
- Rollback: `*.bak-d3mysql-<stamp>` files `/usr/local/alphacp/panel` me

### d2-domains-fix v1.0 — Depth Wave D2: Domains + Zone Editor cPanel-depth (09 Oct 2026)
Domains page: stats vs package limits, search, type badges + Visit link, Create a New
Domain (type radio + live hints + redirect fields auto show/hide). Zone Editor: DNS
record EDIT (naya PUT route), type-aware value placeholders, stats + search. 5 files
(ZoneEditorController + routes + 2 views + panel.js v1.2). Backup + auto-rollback +
route:clear. PEHLE theme-fix v1.2 AUR d1-email-fix install hone chahiye (routes file dono waves ki hai).
```bash
sudo alphacp-sync get 193ac131dd4f871326349350ee025751244359c9 installer/d2-domains-fix.sh /tmp/d2-domains-fix-v1.0.sh aca044f82587516d9ac6592bc76c6264753d3a94a07db2c009cf94707605e7f1 && sudo bash /tmp/d2-domains-fix-v1.0.sh
```
- sha256: `aca044f82587516d9ac6592bc76c6264753d3a94a07db2c009cf94707605e7f1`
- Expected: banner `v1.0` → pre-check 3×200 → 5× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D2 DOMAINS+ZONE DEPTH FIX COMPLETE ✅`
- Phir 2083 → Domains / Zone Editor hard-refresh: type hints, redirect toggle, record Edit expander
- Rollback: `*.bak-d2domains-<stamp>` files `/usr/local/alphacp/panel` me

### d1-email-fix v1.0 — Depth Wave D1: Email Accounts + Forwarders cPanel-depth (09 Oct 2026)
Email Accounts ab option-by-option cPanel jaisa: quota/password EDIT (naya PUT route),
per-box disk usage bars, Connect Devices (IMAP 993/POP3 995/SMTP 465 + copy chips),
password generator + strength meter, search/filter, default-account card; Forwarders
bhi cPanel-style. 6 files (controller + routes + 2 views + panel.js v1.1 + panel.css v3.2).
Backup + auto-rollback + route:clear + php-fpm reload. PEHLE theme-fix v1.2 installed hona chahiye.
```bash
sudo alphacp-sync get feda332db80a84770aa436f04da5c22d4078d681 installer/d1-email-fix.sh /tmp/d1-email-fix-v1.0.sh f051f57f4aac47dbca8aebe7acefe81e4804c8bb58b646f81968c10cb0835652 && sudo bash /tmp/d1-email-fix-v1.0.sh
```
- sha256: `f051f57f4aac47dbca8aebe7acefe81e4804c8bb58b646f81968c10cb0835652`
- Expected: banner `v1.0` → pre-check 3×200 → 6× `installed` → `view cache cleared` + `route cache cleared` + `php8.4-fpm reloaded` → health 3×200 → `==> D1 EMAIL DEPTH FIX COMPLETE ✅`
- Phir 2083 → Email Accounts hard-refresh: naya UI + Manage/Connect Devices expanders chalenge
- Rollback: `*.bak-d1email-<stamp>` files `/usr/local/alphacp/panel` me

### theme-fix v1.2 — ASLI ROOT CAUSE: CSP inline-JS block + product icons (09 Oct 2026)
MILA: security header CSP `script-src 'self'` SAARA inline JavaScript block karta tha —
isliye hamburger/menu/search KABHI nahi chalte the (v1.0/v1.1 me bhi). Fix: saara JS ab
external `assets/panel.js` me (CSP-safe, security strict hi rehti hai). Saath me: har tool
ka apna icon (FTP/Git/Backup/SSL/DB/Cron/Terminal... — pehle sab folder the).
7 files (css + js + layout + 4 views). Backup + auto-rollback. v1.0/v1.1 ke baad bhi safe.
```bash
sudo alphacp-sync get 79a272eee208cd00bf4ac7079ab9979df240a752 installer/theme-fix.sh /tmp/theme-fix-v1.2.sh ed79d8f1d27b1fc05bf9af04c0d2a2795f3fbd23862b6c61505840f97ce0a40b && sudo bash /tmp/theme-fix-v1.2.sh
```
- sha256: `ed79d8f1d27b1fc05bf9af04c0d2a2795f3fbd23862b6c61505840f97ce0a40b`
- Expected: banner `v1.2` → pre-check 3×200 → 6× `installed` + 1× `installed (NEW): panel.js` → `view cache cleared` → health 3×200 → `==> THEME FIX COMPLETE ✅`
- Phir browser hard-refresh / incognito: 2083 = light cPanel (alag icons), 2087 = dark WHM; ☰ menu ab chalega

### ~~theme-fix v1.1~~ — SUPERSEDED (v1.2 chalao — CSP root-cause isi me fix hai)
v1.0 ke upar: cPanel(2083)=LIGHT client panel (demo jaisa), WHM(2087)=DARK charcoal — ab alag
dikhte hain; mobile 3-line (hamburger) menu capture-phase JS se ab pakka chalta hai.
4 files badalti hain (css + layout + 2 views) — DB/composer kuch NahI. Backup + auto-rollback.
```bash
sudo alphacp-sync get cdcdf901fcf7f6d0437a33a3b442ad0d529bc71f installer/theme-fix.sh /tmp/theme-fix-v1.1.sh 5362f3e8edb0a9abf610b16631658d1b2537ebda3a6e57030a1a25082a25309e && sudo bash /tmp/theme-fix-v1.1.sh
```
- sha256: `5362f3e8edb0a9abf610b16631658d1b2537ebda3a6e57030a1a25082a25309e`
- Expected: banner `v1.1` → pre-check 3×200 → 4× `installed` → `view cache cleared` → health 3×200 → `==> THEME FIX COMPLETE ✅`
- Phir browser **hard refresh** (cache clear): 2083 = light cPanel, 2087 = dark WHM
- v1.0 chal chuka hai to bhi ye chalana safe hai (fresh backups banengi)

### ~~theme-fix v1.0~~ — SUPERSEDED (v1.1 chalao — isme hamburger fix + alag looks bhi hain)
Design errors fix: client dashboard ka toota layout (extra div), WHM sidebar ka white-box bug,
aur 5 conflicting CSS layers ki jagah EK clean cPanel-grade theme (dark sidenav + orange #FF6C2C).
Sirf 3 files badalti hain — DB/composer/migration kuch NahI. Backup + auto-rollback built-in.
```bash
sudo alphacp-sync get 8e815e4ee54a25685904be16691d9e8063edec99 installer/theme-fix.sh /tmp/theme-fix-v1.0.sh 5afd4dd2bc02880dd1568faff52a2b74630223e5387a2c33302988c575903739 && sudo bash /tmp/theme-fix-v1.0.sh
```
- sha256: `5afd4dd2bc02880dd1568faff52a2b74630223e5387a2c33302988c575903739`
- Expected: banner `v1.0` → pre-check 3×200 → 3× `installed` → `view cache cleared` → health 3×200 → `==> THEME FIX COMPLETE ✅`
- Phir browser me **hard refresh** (Ctrl+Shift+R): `https://<ip>:2083` + `https://<ip>:2087`
- Agar health fail ho jaye: script KHUD rollback kar deta hai (panel pehle jaisa)
- Manual rollback kabhi bhi: `*.bak-themefix-<stamp>` files panel me hain

### Health/status check (kabhi bhi)
Server already aage hai: **panel 0.75.0 (Step 5)** + **alphacp-sync v1.5** + HTTP 200.
Naya theme/demo kaam (PR #9 — WHM/reseller panels, demo images) **repo-side** hai; server pe
chalane ke liye abhi kuch nahi. Status dekhna ho to:
```bash
sudo alphacp-sync --status                                        # sync setup/timer/last-sync
curl -k -s -o /dev/null -w "panel HTTP %{http_code}\n" https://127.0.0.1:8090/   # panel health (HTTPS! http:// dene par 400 aata hai)
curl -k -s -o /dev/null -w "cPanel HTTP %{http_code}\n" https://127.0.0.1:2083/   # client panel port
curl -k -s -o /dev/null -w "WHM    HTTP %{http_code}\n" https://127.0.0.1:2087/   # WHM port
sudo alphacp-sync                                                 # (optional) turant snapshot push
```

### ⛔ SUPERSEDED (09 Oct) — neeche wali 2 purani rows MAT chalao (downgrade ho jayega)

### ~~alphacp-sync v1.2~~ — MAT chalao: server par already **v1.5** hai (STATE.md dekho). Repo PRIVATE karne se PEHLE chalao.
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/4b4573f96f55927ee1fbf526037785dcdb82aea1/installer/alphacp-sync.sh -o /tmp/acp-sync-v1.2.sh && sudo bash /tmp/acp-sync-v1.2.sh
```
- sha256: `c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852`
- Expected: banner `v1.2` → (key pehle se hai, dobara add nahi karni) → `==> SYNC OK ✅` (ya "koi badlav nahi").
- Test: `sudo bash tools/sim/sync-sim.sh` → 60/60 (Run 8 = get: sha verify, galat sha, traversal, PR-ref commit, no key).
  GitHub par SHA-fetch + `refs/pull/*` fetch asli repo par verify kiya (29 Sep).

### ~~panel-update 0.3.0 (bundle 0.3.2)~~ — MAT chalao: server par **0.75.0** hai, ye DOWNGRADE karega
- commit `0c90863a10e6c70984e627af1b819e66ae60b600`, sha256 `204b78af0b59b75614a61455df1ca96b5eb3c05f744b744647da1a33c4da8480`
- artifact + sync tool pehle `alphacp-sync get` se, fallback public URL. update-sim **54/54** (U5 private+get, U6 private+purana sync → saaf error).
- ⛔ Ab kabhi mat chalao — server 0.75.0 par hai (0.3.2 bundle = downgrade). Agli release ke saath NAYI commit-pinned row aayegi.

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
| alphacp-sync v1.0 setup (`aa2091d…/installer/alphacp-sync.sh`) | 29 Sep | ✅ `main` par pehla snapshot `d5ae8d2` (314 files). Timer har ghante chalta hai. Manual: `sudo alphacp-sync`, status: `sudo alphacp-sync --status` |
| updater 0.1.0 (dusre AI ka, panel 0.3.1) | 29 Sep | ✅ server par 0.3.1 = source byte-for-byte (snapshot se verify) |
| panel-update 0.2.1 (`d741f79…`) → panel 0.3.2 + sync v1.1 | 29 Sep 00:17Z | ✅ UPDATE COMPLETE, HTTP 200, trial same (expiry 13 Oct), snapshot `ebdbd75` |

## 🩺 Sirf zaroorat par

### panel-doctor v1.7 — panel ka HTTP 500 fix + public password rotate (sirf tab, jab panel 500 de raha ho)
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/da3539029d1010f33fd550e7b4d016785c932103/installer/panel-doctor.sh -o /tmp/acp-doctor-v1.7.sh && sudo bash /tmp/acp-doctor-v1.7.sh
```
- sha256: `e2915e0204df79ec41540d0ee68ef8a1c3cb3f261a39dc38651471a5115f5e88`
- Expected: banner `v1.7` → `SANDBOX CONFIRMED` → `sandbox fix CHAL GAYA` → Step 5s naya password
  → `==> PANEL READY ✅` (password VERDICT me dikhega).
- Test: `sudo bash tools/sim/doctor-sim.sh` → 21/21 PASS.
- Dobara chalana safe hai (password dobara nahi badalta, drop-in dobara nahi banta).

## 🔁 Update deploy ka tareeka (aage har update ke liye)
Har naye feature/fix ke saath yahan ek nayi row aayegi:
`curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/<COMMIT>/<script> -o /tmp/<name>-<version>.sh && sudo bash /tmp/<name>-<version>.sh`

## ❌ Superseded — mat chalao
| Purani command | Kyun |
|---|---|
| `arena/01a0ea0d-alphacp/installer/panel-update.sh` (updater 0.1.0, panel 0.3.1) | chal chuka (S2C deployed); ab 0.2.1 → 0.3.2 |
| `1d61cb8…/installer/panel-update.sh` (updater 0.2.0) | kabhi diya nahi gaya; 0.2.1 use karo |
| paste.rs/G72oK (doctor v1.6) | v1.7 me cwd bug fix + security step |
| paste.rs/pnV7U, LxbJT, vbVD9, wsPmr (doctor v1.5–v1.1) | superseded |
| paste.rs/0r1Mi, VD0Px, vVdFC, EPW3b (installer v0.3.3–v0.3.6) | panel install ho chuka hai; v0.3.7 aayega |
