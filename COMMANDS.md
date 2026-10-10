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

### D19 (B1) — License API sell-ready (fingerprint binding + rate-limit)

SSH se `sudo -i` karke root prompt par:

```bash
cd /root && rm -rf AlphaCP-d19 && git clone --depth 1 --branch arena/009c72b2-alphacp https://github.com/abhay751218-hue/AlphaCP.git AlphaCP-d19 && cd AlphaCP-d19 && echo "bd8d3f91ba83d563dec1e6f96e015235d7f6a9ab91a79daec3ffc69164cc321b  installer/d19-license-api.sh" | sha256sum -c - && bash installer/d19-license-api.sh
```

Kya milega:
- `POST /api/v1/activate` + `/api/v1/verify` — remote customer panels ke liye (rate-limit 10/min, 30/min)
- **Ek key = ek server** (fingerprint binding; dusre server par wahi key reject + audit log)
- Expired key activation reject; har remote activation audit me
- Key issue/revoke pehle jaisa: 2087 → License Server page
- Rollback stamp: `bak-d19lic-…`

## ✔️ Ho chuki hai (dobara mat chalao)

### ✔️ D18 (DEPLOYED 09 Oct 2026 — backups bak-d18nav-20261009175830) — cPanel-style navbar: bell + user dropdown + search placeholder

SSH se `sudo -i` karke root prompt par:

```bash
cd /root && rm -rf AlphaCP-d18 && git clone --depth 1 --branch arena/009c72b2-alphacp https://github.com/abhay751218-hue/AlphaCP.git AlphaCP-d18 && cd AlphaCP-d18 && echo "3a4136911e897084f2c981835371af0db0b7be4384e57fe16cc045929abec1f0  installer/d18-navbar-parity.sh" | sha256sum -c - && bash installer/d18-navbar-parity.sh
```

Kya milega:
- Topbar me 🔔 bell + user par click → dropdown: Password & Security / 2FA / Sessions / Log out (cPanel navbar jaisa)
- Search: "Find functions quickly by typing here (/)" — dashboard tiles live filter hote hain
- Sidebar-top ki aakhri "cPanel/WHM" naming line fix (ab Server Manager / Account Panel)
- Rollback stamp: `bak-d18nav-…`


### ✔️ D17 (DEPLOYED 09 Oct 2026 — backups bak-d17look-20261009174756) — Look parity: WHM-style tiles + File Manager toolbar + Apps grid

SSH se `sudo -i` karke root prompt par:

```bash
cd /root && rm -rf AlphaCP-d17 && git clone --depth 1 --branch arena/009c72b2-alphacp https://github.com/abhay751218-hue/AlphaCP.git AlphaCP-d17 && cd AlphaCP-d17 && echo "b784303fbea509463dda29c45779ca06c7032e15a0cd4f449491fadc68e36d5a  installer/d17-look-parity.sh" | sha256sum -c - && bash installer/d17-look-parity.sh
```

Kya milega:
- 2087 Dashboard: Quick links ke neeche **icon TILES grid** (WHM home jaisa)
- 2083 File Manager: upar **toolbar** — Upload / New Folder / New File / Rename buttons
- 2083 Site Software: **app tiles grid** (Softaculous jaisa)
- Rollback stamp: `bak-d17look-…`


### ✔️ D16 (DEPLOYED 09 Oct 2026 — backups bak-d16brand-20261009174013) — AlphaCP-only branding + progress-card removal

SSH se `sudo -i` karke root prompt par:

```bash
cd /root && rm -rf AlphaCP-d16 && git clone --depth 1 --branch arena/009c72b2-alphacp https://github.com/abhay751218-hue/AlphaCP.git AlphaCP-d16 && cd AlphaCP-d16 && echo "183cb620657cb2d7b1df9f102a26abe536ac2fdca0261150d985c08278d564ac  installer/d16-branding-sweep.sh" | sha256sum -c - && bash installer/d16-branding-sweep.sh
```

Kya milega (15 views):
- Login pages: "cPanel/WHM Login" → "Account Panel / Server Manager Login"
- Dashboard: "feature progress — 103 tools" card GONE; titles/Theme AlphaCP
- Footer checklist line gone; sidebar search, Ports page, 9 subtitles AlphaCP wording
- Rollback stamp: `bak-d16brand-…`

(Note: agar sha256 FAIL bole to pehle `git log -1 --format=%H` se commit 13f4c30df9c6148fe46b10b4075afdd2a363daf6 confirm karo.)


### ✔️ D15 (DEPLOYED 09 Oct 2026 — backups bak-d15fm-20261009171411; FINAL wave) — File Manager plus: Upload + Compress/Extract + chmod (FINAL parity wave)

SSH se root banke ye chalao:

```bash
cd /root && rm -rf AlphaCP-d15 && git clone --depth 1 --branch arena/009c72b2-alphacp https://github.com/abhay751218-hue/AlphaCP.git AlphaCP-d15 && cd AlphaCP-d15 && git checkout bb18732c941d78c19cf5c721baf44528ba8b26cb -- installer/d15-filemanager-plus.sh && echo "4fda14632883e96f44b701a244f81b9d525c27cce8943fae3f725e1a5abfdf65  installer/d15-filemanager-plus.sh" | sha256sum -c - && sudo bash installer/d15-filemanager-plus.sh
```

Kya milega:
- File Manager me **Upload card** (64 MB tak, ownership account user ki)
- Har file/folder row par **chmod dropdown**, **Compress (.tar.gz)**, **Extract** buttons
- nginx `client_max_body_size 64m` + php-fpm upload limits (backup + nginx -t gate ke saath)
- Rollback: script ke end me backup stamp print hota hai (`bak-d15fm-…`)

Check: 2083 panel hard-refresh → Files → File Manager.


> Note: repo ab PUBLIC hai; shallow clone me purane commit ka `git checkout` fail hota hai — sha256sum check hi kaafi hai.

### ✔️ D14 (DEPLOYED 09 Oct 2026 — backups bak-d14parity-20261009165725) — Parity Gaps: suspend + AAAA/MX-prio + revoke + 2 ROUTE FIX (v1.0)

Deferred items ("ek bhi nahi chutna chahiye") + ek **important bug fix**:
- **Email Accounts**: har mailbox par **Suspend/Unsuspend** (login band, data/mail safe — cPanel style)
- **Zone Editor**: **AAAA** (IPv6) record type + **MX priority** (`10 mail.example.com`)
- **MySQL Users**: **Revoke User From Database** card (grant ka ulta)
- **FIX**: `email.update` + `zone-editor.update` routes D7 rebase me gir gaye the —
  Email Accounts ka "Update mailbox" aur Zone Editor ka record-edit **500 de raha tha**, ab wapas theek

```bash
sudo alphacp-sync get e218ab49e1caabde921bd2d072b2e33d31b661de installer/d14-parity-gaps.sh /tmp/d14-parity-gaps-v1.0.sh 5c41b2b13cba309ad36ff9925c55c20a93c531ab649d7ac3f20dfe742fb95da2 && sudo bash /tmp/d14-parity-gaps-v1.0.sh
```

Expected output (short):
- `-- Step 1b: PHP guard + paneld check --` → `[OK] php CLI pdo_mysql OK`
- `-- Step 2: install (12 files, backup ke saath) --` → 12× `[OK] installed` (backups `.bak-d14parity-<stamp>`)
- `-- Step 3b: paneld restart --` → `[OK] paneld restarted — db.user.revoke + AAAA/MX-prio + mail suspend ab live`
- `-- Step 4: health check --` → 3×200 → `==> D14 PARITY GAPS COMPLETE ✅`

Test (browser, hard-refresh):
1. Email Accounts → mailbox par **Suspend** → badge red "Suspended" → **Unsuspend** wapas green; Manage → Update mailbox ab 500 nahi dega
2. Zone Editor → type dropdown me **AAAA**; MX add karo value `10 mail.<domain>` se
3. MySQL Users → **Revoke User From Database** → user ka access us DB se hat jayega (Task Queue me db.user.revoke)


### D13 — Node.js App Manager v1.0 ✅ (09 Oct 2026 — DEPLOYED, user-confirmed)

Commit `d0271e0a43c42dcc94697b12c252b8e7c6e1c88f` · sha256 `40a650b2203c60ec79f7c5d7f5ca3cc050ea7013d5fc63747c17b49c4810e37d`
8 files, backups `*.bak-d13node-20261009164655`. node.list/setup/control live (PM2-style systemd units), Node v20.20.2, health 3×200.

### D12 — phpMyAdmin + One-Click SSO v1.1 ✅ (09 Oct 2026 — DEPLOYED)

Commit `e38cd66f095112a6d33f2f217441de016a7bb412` · sha256 `72d1929efc188158922ed964666e9ebabd8ca1d3e71acf66974ccd9811c10708`
9 files + apt phpMyAdmin + nginx :2098, backups `*.bak-d12pma-20261009162458`. v1.0 fail (apt ne PHP 8.5 par switch kiya) → v1.1 PHP guard ne fix kiya; paneld unit ab php8.4 par pinned.
SSO click-test naye server par hoga — demo account me Linux user nahi tha (fix: upar wali useradd command).

### D11 — WHM Restart Services v1.0 ✅ (09 Oct 2026 — DEPLOYED, user-confirmed)

Commit `8242a1fc38fe01e407a450754391a87dd1ce950f` · sha256 `2e0051fe2ee1c0bd3cdc1f5fd0261983c55f1e02688786fe4f001c5607f756ae`
5 files, backups `*.bak-d11restart-20261009160034`. service.restart live (11 allowlisted, paneld excluded), paneld restart OK, health 3×200.

### ✅ DONE 09 Oct — d10-final-tiles v1.0 — Depth Wave D10 (FINAL): aakhri 3 greyed tiles → 103/103 = 100% (09 Oct 2026)
Dashboard ke AAKHRI 3 grey tiles ab LIVE: **Node.js Selector** (server ka Node runtime live detect),
**PHP Composer** (account ki asli composer.json padh kar dependencies ki table), **Updates** (panel/agent/
PHP/Laravel/Node versions + ab tak lage sab update waves ki history — /var/log se). Counter **100 → 103,
koi grey tile nahi bachega!** 8 files (3 controllers + 3 views + routes + catalog). Backup + auto-rollback.
```bash
sudo alphacp-sync get 65e45216cc08bf92f219ae0a6875a340501e1152 installer/d10-final-tiles.sh /tmp/d10-final-tiles-v1.0.sh a249b44c5734ea733a20fe874cfa1cac7ddad80b17ebc4e58505c0a4c93738a9 && sudo bash /tmp/d10-final-tiles-v1.0.sh
```
- sha256: `a249b44c5734ea733a20fe874cfa1cac7ddad80b17ebc4e58505c0a4c93738a9`
- Expected: banner `v1.0` → pre-check 3×200 → 8× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D10 FINAL TILES COMPLETE — 103/103 TOOLS LIVE ✅`
- Phir **2083 AUR 2087 dono hard-refresh** → Software section me Node.js Selector + PHP Composer,
  WHM Server Configuration me Updates — **koi bhi tile grey nahi hona chahiye, counter 103/103**
- Test: Updates kholo (sab waves ki history dikhegi), Composer kholo (composer.json wale account par packages)
- Rollback: `*.bak-d10final-<stamp>` files `/usr/local/alphacp/panel` me

### ✅ DONE 09 Oct — d9-terminal-webmail v1.0 — Depth Wave D9: Terminal v2 + Webmail one-click SSO (09 Oct 2026)
**Terminal** ab asli console jaisa: quick-command buttons (uptime, df -h, free -m…), is session ki
history (Run again button ke saath), dark output box, allowed-commands list. **Webmail** ab cPanel
jaisa: har mailbox ki table me apna **📬 Open Webmail** button — jo mailbox chuno USI se Roundcube
one-click login (pehle sirf pehla mailbox khulta tha). 4 files (2 controllers + 2 views), routes/DB
same. Backup + auto-rollback.
```bash
sudo alphacp-sync get 3e8b067000db0390a11f1de00587e68a5e8ce70e installer/d9-terminal-webmail.sh /tmp/d9-terminal-webmail-v1.0.sh a80e187ef99dfc8e06b8abbd96f6cfbf7ce09ca53b1852104dd3c95c3005275a && sudo bash /tmp/d9-terminal-webmail-v1.0.sh
```
- sha256: `a80e187ef99dfc8e06b8abbd96f6cfbf7ce09ca53b1852104dd3c95c3005275a`
- Expected: banner `v1.0` → pre-check 3×200 → 4× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D9 TERMINAL + WEBMAIL COMPLETE ✅`
- Test: **2087** → Advanced → Terminal (quick buttons dabao, history dekho) · **2083** → Email → Webmail
  (mailbox table me Open Webmail dabao — Roundcube bina password khulega)
- Rollback: `*.bak-d9termweb-<stamp>` files `/usr/local/alphacp/panel` me

### ✅ DONE 09 Oct — d8-mailqueue-secpol v1.0 — Depth Wave D8: Mail Queue Manager + Security Policies (09 Oct 2026)
WHM ke 2 naye tools LIVE: **Mail Queue Manager** (exim queue — har message deliver/freeze/thaw/remove,
poori queue flush) aur **Security Policies** (2FA adoption har user ka, shield services ka asli state,
enforced policies table). Dashboard counter 98→100. 6 files (2 controllers + 2 views + routes + catalog).
Backup + auto-rollback.
```bash
sudo alphacp-sync get 4b55c0a72612b0a6b94c0bd0bd83745cdf2445ba installer/d8-mailqueue-secpol.sh /tmp/d8-mailqueue-secpol-v1.0.sh 428942357e57858e7c1ca2f8c47dfb27fbf855319cbffa5a2cfb04c43bad6052 && sudo bash /tmp/d8-mailqueue-secpol-v1.0.sh
```
- sha256: `428942357e57858e7c1ca2f8c47dfb27fbf855319cbffa5a2cfb04c43bad6052`
- Expected: banner `v1.0` → pre-check 3×200 → 6× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D8 MAIL QUEUE + SECURITY POLICIES COMPLETE ✅`
- Phir **2087 (WHM)** hard-refresh (Ctrl+Shift+R) → naya **Email (Server)** section me Mail Queue Manager,
  **Security Center** me Security Policies ab GREY nahi
- Test: Security Policies kholo (2FA table + shield services), Mail Queue me "Deliver All Now"
- Rollback: `*.bak-d8mailsec-<stamp>` files `/usr/local/alphacp/panel` me

### ✅ DONE 09 Oct — d7-metrics-tools v1.0 — Depth Wave D7: Errors + Raw Access + Awstats + Network Tools AB KAAM KARTE HAIN (09 Oct 2026)
Metrics ke 4 naye tools LIVE: **Errors** (error log last 40 lines), **Raw Access** (access log
last 50 + traffic summary), **Awstats** (visitors/bandwidth/requests stats + top pages bars),
**Network Tools** (DNS lookup — A/AAAA/MX/NS/TXT/CNAME). Dashboard counter 94→98.
10 files (4 controllers + 4 views + routes + catalog). Backup + auto-rollback.
```bash
sudo alphacp-sync get 25c29ca036df7daa039fa6e65fffca42db4e9207 installer/d7-metrics-tools.sh /tmp/d7-metrics-tools-v1.0.sh a07b946924b2bd706106e06cfcf24d1091bc3fc812b032aec9a4d3f8b2adae54 && sudo bash /tmp/d7-metrics-tools-v1.0.sh
```
- sha256: `a07b946924b2bd706106e06cfcf24d1091bc3fc812b032aec9a4d3f8b2adae54`
- Expected: banner `v1.0` → pre-check 3×200 → 10× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D7 METRICS TOOLS COMPLETE ✅`
- Phir 2083 hard-refresh (Ctrl+Shift+R) → Metrics section → Errors / Raw Access / Awstats / Network Tools kholo
- Network Tools me koi bhi domain daal ke Lookup chalao (e.g. google.com, MX)
- Rollback: `*.bak-d7metrics-<stamp>` files `/usr/local/alphacp/panel` me

### ✅ DONE 09 Oct — d6c-software-whm-fix v1.0 — Depth Wave D6c: Software/Advanced + WHM ke 15 pages cPanel-style (09 Oct 2026)
Software/Advanced + WHM ke 15 tools ab company-jaisa look: **Optimize Website, Site Software,
Error Pages, MultiPHP Manager, Database Wizard, Remote MySQL, MIME Types, Apache Handlers,
Domain Forwarding, Dynamic DNS, User Manager, Reseller Center, Security Tools (WAF+ClamAV),
Ports Control, License & Trial**. Har page par stats cards + SVG icons + badges + live search.
15 files (SIRF views — koi controller/routes/JS/CSS/DB change NAHI). Backup + auto-rollback.
```bash
sudo alphacp-sync get f148f46c655c843a0cd1a2a87daad0cd35053723 installer/d6c-software-whm-fix.sh /tmp/d6c-software-whm-fix-v1.0.sh a946ed38b8f2c182f4460e201c989fbed020caf7efcbd239b7ab46f5e38a7bc9 && sudo bash /tmp/d6c-software-whm-fix-v1.0.sh
```
- sha256: `a946ed38b8f2c182f4460e201c989fbed020caf7efcbd239b7ab46f5e38a7bc9`
- Expected: banner `v1.0` → pre-check 3×200 → 15× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D6c SOFTWARE+WHM UI FIX COMPLETE ✅`
- Phir 2083 AUR 2087 dono hard-refresh (Ctrl+Shift+R) — User Manager/Resellers/Ports/License WHM side par hain
- Rollback: `*.bak-d6csoft-<stamp>` files `/usr/local/alphacp/panel` me

### ✅ DONE 09 Oct — d6b-files-security-fix v1.0 — Depth Wave D6b: Files+Security ke 14 pages cPanel-style (09 Oct 2026)
Files + Security section ke 14 tools ab company-jaisa look: **Images, Directory Privacy,
Disk Usage, FTP Accounts, Web Disk, Backup Wizard, Git Version Control, File Restoration,
Trash, Indexes, SSH Access, IP Blocker, API Tokens, Audit Log**.
Har page par stats cards + SVG icons + colored badges + live search + related-tool links.
14 files (SIRF views — koi controller/routes/JS/CSS/DB change NAHI; saare form fields/
permissions same). Backup + auto-rollback.
```bash
sudo alphacp-sync get 50295571b01b7fab973dec3b461c49e24c69fd7d installer/d6b-files-security-fix.sh /tmp/d6b-files-security-fix-v1.0.sh 5aae5f020d27f37b98e3429837aefb2a4985bc60a3b82227bff9dbfce9d7daa4 && sudo bash /tmp/d6b-files-security-fix-v1.0.sh
```
- sha256: `5aae5f020d27f37b98e3429837aefb2a4985bc60a3b82227bff9dbfce9d7daa4`
- Expected: banner `v1.0` → pre-check 3×200 → 14× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D6b FILES+SECURITY UI FIX COMPLETE ✅`
- Phir 2083 → Files/Security tools khol kar hard-refresh (Ctrl+Shift+R)
- Rollback: `*.bak-d6bfiles-<stamp>` files `/usr/local/alphacp/panel` me


### ✅ DONE 09 Oct — d6a-email-ui-fix v1.0 — Depth Wave D6a: Email group ke 14 pages cPanel-style (09 Oct 2026)
> ⚠️ **NOTE:** 09 Oct ko chat me galat commit-id chali gayi thi ("GitHub se nahi mila" error). **Sirf neeche wali command chalao — ye verified hai.** Scrollback wali purani command mat chalao.
Email section ke 14 tools ab company-jaisa look: **Default Address, Autoresponders, Email
Routing, Email Filters, Global Filters, Mailing Lists, Spam Filters, BoxTrapper, Calendar,
Encryption, Email Disk Usage, Track Delivery, Address Importer, Email Deliverability**.
Har page par stats cards + SVG icons + colored badges + live search + related-tool links.
14 files (SIRF views — koi controller/routes/JS/CSS/DB change NAHI; saare form fields/
permissions same). Backup + auto-rollback.
```bash
sudo alphacp-sync get c48498fa658a1d887a8fbb89144b87022199de8c installer/d6a-email-ui-fix.sh /tmp/d6a-email-ui-fix-v1.0.sh b52519ce6cc52855dca1d0c9a20cf8b16788524fea13b0de0b5497a8b5d8c995 && sudo bash /tmp/d6a-email-ui-fix-v1.0.sh
```
- sha256: `b52519ce6cc52855dca1d0c9a20cf8b16788524fea13b0de0b5497a8b5d8c995`
- Expected: banner `v1.0` → pre-check 3×200 → 14× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D6a EMAIL UI FIX COMPLETE ✅`
- Phir 2083 → Email section ke tools khol kar hard-refresh (Ctrl+Shift+R)
- Rollback: `*.bak-d6aemail-<stamp>` files `/usr/local/alphacp/panel` me


### d5-tools-fix v1.0 — Depth Wave D5 (FINAL): Cron presets + SSL + File Manager + DARK MODE (09 Oct 2026)
Cron Jobs me cPanel "Common Settings" presets (Once Per Minute se Once Per Year tak — select
karo, 5 fields khud bhar jati hain). SSL/TLS: secured/attention stats + expiry warning badges.
File Manager: breadcrumbs + icons + filter + editor. Aur sabse mazedaar: **DARK MODE** — topbar
me chand/sooraj button, dono panels me, choice saved rehti hai. 6 files (4 views + panel.js
v1.5 + panel.css v3.3) — koi controller/routes/DB change NAHI. Backup + auto-rollback.
```bash
sudo alphacp-sync get a0d496ad1561ed36347440241df0db263456d4d4 installer/d5-tools-fix.sh /tmp/d5-tools-fix-v1.0.sh cab610594f874219850ed7ac9c115c91363fb60256884c224dbf87c836e0c710 && sudo bash /tmp/d5-tools-fix-v1.0.sh
```
- sha256: `cab610594f874219850ed7ac9c115c91363fb60256884c224dbf87c836e0c710`
- Expected: banner `v1.0` → pre-check 3×200 → 6× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D5 TOOLS DEPTH FIX COMPLETE ✅`
- Phir 2083 → Cron Jobs / SSL/TLS / File Manager hard-refresh + topbar 🌙 button dabao
- Rollback: `*.bak-d5tools-<stamp>` files `/usr/local/alphacp/panel` me

### d4-whm-fix v1.0 — Depth Wave D4: WHM Create Account + List Accounts + Packages (09 Oct 2026)
WHM side ab real WHM jaisa: Create a New Account me Domain pehle (username auto-suggest),
password Generate + strength meter, package select me limits summary. List Accounts: stats
strip, search, quick Suspend/Unsuspend. Packages: stats, search, feature badges. 4 files
(3 views + panel.js v1.4) — koi controller/routes/DB change NAHI (extra-safe). Backup + auto-rollback.
```bash
sudo alphacp-sync get 7a2e025d4cdca3618b5f8991f7aef23de3ca62d9 installer/d4-whm-fix.sh /tmp/d4-whm-fix-v1.0.sh 3c85a02777d5318bf2e25c5bfd3544dce2589cfbd06af44b4713eaf03d0afa75 && sudo bash /tmp/d4-whm-fix-v1.0.sh
```
- sha256: `3c85a02777d5318bf2e25c5bfd3544dce2589cfbd06af44b4713eaf03d0afa75`
- Expected: banner `v1.0` → pre-check 3×200 → 4× `installed` → caches cleared + php-fpm reload → health 3×200 → `==> D4 WHM DEPTH FIX COMPLETE ✅`
- Phir WHM :2087 → Create a New Account / List Accounts / Packages hard-refresh
- Rollback: `*.bak-d4whm-<stamp>` files `/usr/local/alphacp/panel` me

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
