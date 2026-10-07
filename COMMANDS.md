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

### alphacp-sync v1.5 — **ho chuka** (22:11Z, SYNC OK ✅)
```bash
sudo alphacp-sync get aa22e5fac292ddfce6086dc44db3752fea334c72 installer/alphacp-sync.sh /tmp/acp-sync-v1.5.sh 1e197e0860ca3c59e400e1b5c8504d894ec889e316007143493d5e3855557db5 && sudo bash /tmp/acp-sync-v1.5.sh
```

### ⭐ Command #2 — FTP Accounts (Pure-FTPd) v1.0. **Abhi yahi chalao.**
Sandbox-tested (FtpTest 4/4 pass). Portable: naye VPS/dedicated par bhi pure-ftpd khud install karega.
```bash
sudo alphacp-sync get dc72b2e48ba8de2592e9dbadc3517a3a6c19ff4b installer/ftp-accounts.sh /tmp/ftp-accounts-v1.0.sh 21c7e6531e68d7b8fc14e18805142a6c78dcca1d79a18485ba36febeeba9e904 && sudo bash /tmp/ftp-accounts-v1.0.sh
```
Expected banner: `AlphaCP FTP Accounts installer v1.0` + end `==> FTP ACCOUNTS v1.0 INSTALLED`.
Phir panel me `https://<host>:8090/ftp` kholo — FTP Accounts page dikhega. **HO CHUKA ✅ (7 Oct, 70 tables).**

### ⭐ Command #3 — Metrics (Visitors/Errors/Bandwidth) v1.0. **Abhi yahi chalao.**
Sandbox-tested (MetricsTest 3/3 pass). Koi daemon/migration nahi — access-log se stats.
```bash
sudo alphacp-sync get 57234320bf6c0553570fb6e2f0146d2568471a3a installer/metrics.sh /tmp/metrics-v1.0.sh 4882374e29d2ba6287fc5549b917c51dd62f23faf90e011bdb9f1867078b6367 && sudo bash /tmp/metrics-v1.0.sh
```
Expected banner: `AlphaCP Metrics installer v1.0` + end `==> METRICS v1.0 INSTALLED`.
Phir `https://<host>:8090/metrics` kholo — Bandwidth/Visitors/Requests/Errors dashboard. **HO CHUKA ✅ (7 Oct).**

### ⭐⭐ Command #4 — License keep-alive v1.0. **PRIORITY — 13 Oct trial-lock se pehle chalao.**
Sandbox-tested (LicenseRenewTest 2/2). Trial ko +365 din re-issue karta hai (owner server).
Customer websites/email/DNS/backups par KOI asar nahi (golden rule).
```bash
sudo alphacp-sync get 3f9ada2861c61c3410a695ee37f0d4e22bb0a1eb installer/license-keepalive.sh /tmp/license-keepalive-v1.0.sh c6589f0889243747a6f9673392af0ded759e0fc416b25b8657bf283eb5cde8d3 && sudo bash /tmp/license-keepalive-v1.0.sh
```
Expected: `License state=trial expires=<+365d> days_left=365` + `==> LICENSE KEEP-ALIVE v1.0 DONE`.
Phir panel me `/license` kholo — trial active (+365 din) dikhega, 13 Oct wali lock khatam. **HO CHUKA ✅ (trial ab 2027-10-07 tak).**

### ⭐ Command #5 — IP Blocker (cPanel Security) v1.0. **Abhi yahi chalao.**
Sandbox-tested (IpBlockerTest 4/4). `user` role ko `security.view` bhi grant karta hai (re-seed).
```bash
sudo alphacp-sync get 24850f1a388dc0579e467eb091f753d53124d2ed installer/ip-blocker.sh /tmp/ip-blocker-v1.0.sh 041bd9c9fb36d6f699296bae09d73459c265db5456f5d0d5822b4251ac6ef34a && sudo bash /tmp/ip-blocker-v1.0.sh
```
Expected: `AlphaCP IP Blocker installer v1.0` → files + routes + migrate + seed → `==> IP BLOCKER v1.0 INSTALLED`.
Phir `https://<host>:8090/ip-blocker` kholo — IP block/unblock (ufw) page. **HO CHUKA ✅ (71 tables, 7aa2d72).**

### ⭐ Command #6 — Security Tools (ModSecurity WAF + Virus Scanner) v1.0. **Abhi yahi chalao.**
Sandbox-tested (SecurityToolsTest 3/3). Fresh VPS par clamav + modsecurity bhi install karta hai.
```bash
sudo alphacp-sync get 996b7cee96516f0728e91077c24a737e10aaf64a installer/waf.sh /tmp/waf-v1.0.sh 4a6660104723a65500fa162aac735f783ec7220a9d600c47330d2addedf2a8ea && sudo bash /tmp/waf-v1.0.sh
```
Expected: `AlphaCP Security Tools installer v1.0` → clamav/modsec + files + routes + seed → `==> SECURITY TOOLS v1.0 INSTALLED`.
Phir `https://<host>:8090/security-tools` — WAF on/off + Virus Scan. **HO CHUKA ✅ (ff65606).**

### ⭐ Command #7 — App Installer (WordPress one-click) v1.0. **Abhi yahi chalao.**
Sandbox-tested (AppsTest 2/2). WordPress install: DB + download + wp-config + chown.
```bash
sudo alphacp-sync get 955ba3753443928e443d2f3d3401a359a847720d installer/app-installer.sh /tmp/app-installer-v1.0.sh 9882cc4172df9b9fcc3b9348cd881f7d4ee2397bb04c0365a4737d8b42bdd066 && sudo bash /tmp/app-installer-v1.0.sh
```
Expected: `AlphaCP App Installer v1.0` → files + routes + clear → `==> APP INSTALLER v1.0 INSTALLED`.
Phir `https://<host>:8090/apps` — WordPress install button. **HO CHUKA ✅ (2577bc1).**

### ⭐ Command #8 — Monitoring / Resource Usage v1.0. **Abhi yahi chalao.**
Sandbox-tested (MonitoringTest 1/1). Disk/Memory/Load/CPU dashboard (WHM jaisa).
```bash
sudo alphacp-sync get 9aaa40e830f851df887e8e82232bf18b4ba908bb installer/monitoring.sh /tmp/monitoring-v1.0.sh ae111c38f5ade9c8221f0fd171155a044f5b6196172a404a2c10b0bc08bd5057 && sudo bash /tmp/monitoring-v1.0.sh
```
Expected: `AlphaCP Monitoring installer v1.0` → files + routes + clear → `==> MONITORING v1.0 INSTALLED`.
Phir `https://<host>:8090/monitoring` — Memory/Disk/Load/CPU dashboard. **HO CHUKA ✅ (19471ab).**

### ⭐ Command #9 — WHM API 1 (Billing integration) v1.0. **Abhi yahi chalao.**
Sandbox-tested (WhmApiTest 4/4). WHMCS/Blesta-ready: `/json-api/createacct|listaccts|suspendacct|…` (Bearer token).
```bash
sudo alphacp-sync get 3ff4d8723100f2f9e0457120dec5543c56754b50 installer/whm-api.sh /tmp/whm-api-v1.0.sh 755c5fb3310f88bc7b3a2fe3141b6aea515057712174a307273c93d20e1c49b8 && sudo bash /tmp/whm-api-v1.0.sh
```
Expected: `AlphaCP WHM API installer v1.0` → files + routes + migrate → `==> WHM API v1.0 INSTALLED`.
Token: `cd /usr/local/alphacp/panel && sudo php artisan alphacp:api:token admin --name=billing` (plain token EK baar dikhega → billing software me daalo).
Test: `curl -sk -H "Authorization: Bearer <token>" https://<host>:8090/json-api/listaccts`

### ⭐ Command #10 — Manage API Tokens UI (cPanel jaisa) v1.0. **Abhi yahi chalao.**
Sandbox-tested (ApiTokensTest 3/3). Panel UI se token generate/revoke — customer apni billing me daal sake.
```bash
sudo alphacp-sync get 930aa166a64312414129af2c417b789d39b3601c installer/api-tokens.sh /tmp/api-tokens-v1.0.sh b5b05f635cbfd7766dff8b5622c57162c31d8f9c8c97388b287f781529c65f9b && sudo bash /tmp/api-tokens-v1.0.sh
```
Expected: `==> API TOKENS UI v1.0 INSTALLED`. Phir `https://<host>:8090/api-tokens` — Generate/Revoke tokens (plain token ek baar dikhta hai). **HO CHUKA ✅ (72 tables, 3a1b01c).**

---

## Command #11 — Reseller Center (WHM-style, G8)
```bash
sudo alphacp-sync get ca6398ffbf661da5ca811e10eba8796ce00be65f installer/resellers.sh /tmp/resellers-v1.0.sh c2b48bd6c7aa5a8aa515b11c8dcec016fa82e8a8099f87cd1319d29596f30bdd && sudo bash /tmp/resellers-v1.0.sh
```
Expected: `==> RESELLER CENTER v1.0 INSTALLED`. Phir `https://<host>:8090/resellers` — resellers promote/demote + ACL privileges.
Sandbox test: ResellersTest **5/5 pass**.

---

## Command #12 — REBRAND (cPanel/WHM company-words hatao)
```bash
sudo alphacp-sync get dfbd379474547fb1ba96547a7ac5b4493233fb7f installer/rebrand.sh /tmp/rebrand-v1.0.sh cad5d5c039b5800e2acd2c288f58700e8f5557ca9add5fbcaae843d6aafcca77 && sudo bash /tmp/rebrand-v1.0.sh
```
Expected: `==> REBRAND v1.0 APPLIED`. Header → `AlphaCP Server Manager` / `Account Panel`; dashboard → `Server Manager Dashboard`;
footer → `AlphaCP control panel`. Sandbox-verified: views me `WHM=0, cPanel=0`. Idempotent (REBRAND_DONE).
**⚠️ USER ko manually KABHI nahi chalana — ye sirf legacy base ke liye tha aur ab install-all ka internal automatic step hai.
Naye features brand-clean hain (`tools/sim/check-brand.sh` guard), isliye dobara zaroorat nahi padegi.**

---

## Command #13 — G5: Git Version Control + Terminal
```bash
sudo alphacp-sync get ca6398ffbf661da5ca811e10eba8796ce00be65f installer/g5.sh /tmp/g5-v1.0.sh 38f26d8c1eb72da5122ffc98106fa6c0a70aa61b47752f2b6d64a8d1267f37cb && sudo bash /tmp/g5-v1.0.sh
```
Expected: `==> G5 (GIT + TERMINAL) v1.0 INSTALLED`. Pages: `/git` (clone/pull/status) + `/terminal` (whitelisted commands).
Sandbox test: G5Test **7/7 pass** (traversal + dangerous-command blocked). **Brand-clean (AlphaCP) — rebrand ki zaroorat NAHI.**

---

## Command #14 — License Server (sellable signed licenses, S15)
```bash
sudo alphacp-sync get 0b6acfa24c54e7a149d7b4e436935d9467429439 installer/license-server.sh /tmp/license-server-v1.0.sh 8964067df7aad6ef31531a18ee82a34d0b482891d682a887da0b08795b2b631d && sudo bash /tmp/license-server-v1.0.sh
```
Expected: `==> LICENSE SERVER v1.0 INSTALLED`. Page: `/license-server` — keys issue/verify/revoke.
`ACP_LICENSE_SECRET` `.env` me set karo (signing secret). Sandbox test: LicenseServerTest **5/5 pass** (tamper+revoke blocked).
**Brand-clean — rebrand ki zaroorat NAHI.**

---

## Command #15 — Hotlink + Leech Protection (Security complete)
```bash
sudo alphacp-sync get 2ad97e22f4559f590d57e86bb310215016be6dc3 installer/secextra.sh /tmp/secextra-v1.0.sh 36fbd9f89f19b94f541d4bc257d83b0eec4b53bac669b0be96392fbccd548c6b && sudo bash /tmp/secextra-v1.0.sh
```
Expected: `==> HOTLINK + LEECH PROTECTION v1.0 INSTALLED`. Pages: `/hotlink-protection` + `/leech-protection`.
Sandbox test: SecExtraTest **4/4 pass**. **Brand-clean — rebrand ki zaroorat NAHI.**

---

## Command #16 — Dashboard sync (tiles ko live dikhao — user ki "problem" ka fix)
```bash
sudo alphacp-sync get a0f22a05c9b0184a37745a378b904f386b2bf1a6 installer/dashboard-sync.sh /tmp/dashboard-sync-v1.0.sh f8c3f19f92de77e3bcd3750a0d755baacefacbbab73377bc263e1d673cb1b6b7 && sudo bash /tmp/dashboard-sync-v1.0.sh
```
Expected: `==> DASHBOARD SYNC v1.0 APPLIED`. Dashboard refresh karo — FTP/Git/Terminal/IP Blocker/ModSecurity/Hotlink/
Apps/API Tokens/Monitoring tiles ab **live (green)** dikhenge, parity count update hoga. Sandbox test: 1/1 (27 assertions).

---

## Command #17 — Web Disk (Files section complete)
```bash
sudo alphacp-sync get 11c2d134e21794992e08e5b187c9165428e7f955 installer/webdisk.sh /tmp/webdisk-v1.0.sh f5dcd1acc8aa253ca5e677c231bd3b2cb7b82e3633fab2e56071c71db1efbd8e && sudo bash /tmp/webdisk-v1.0.sh
```
Expected: `==> WEB DISK v1.0 INSTALLED`. Page: `/webdisk` — WebDAV accounts (ro/rw). Sandbox test: WebDiskTest **4/4 pass**.
Dashboard tile bhi live (dashboard-sync #16 ke saath). **Brand-clean — rebrand NAHI.**

---

## Command #18 — File extras: Images + Optimize Website + Trash
```bash
sudo alphacp-sync get a0f22a05c9b0184a37745a378b904f386b2bf1a6 installer/filextras.sh /tmp/filextras-v1.0.sh 6ce28d9b8c306896f56a46789260f6b17221d1680fcc56a3cd925ffb1c9efa3f && sudo bash /tmp/filextras-v1.0.sh
```
Expected: `==> FILE EXTRAS v1.0 INSTALLED`. Pages: `/images`, `/optimize-website`, `/trash`. Sandbox test: FileXtrasTest **4/4 pass**
(traversal-guarded). Dashboard tiles live (dashboard-sync #16 ke saath). **Brand-clean — rebrand NAHI.**

> ⚠️ **SSL note:** base panel me **SSL Status + AutoSSL pehle se live** hai (`/ssl`) — alag se install NAHI karna (maine duplicate feature drop kar di).

---

## Command #19 — DNS Cluster (WHM) — dashboard tile KHUD update hota hai
```bash
sudo alphacp-sync get 6308c465ca3ad95a921ccf9e0f50d4c85478e35b installer/dns-cluster.sh /tmp/dns-cluster-v1.0.sh 5b201540a0d7dfc4316b4c6a75924d14dbec2d9e1f92d0c04f8595dff65fbaca && sudo bash /tmp/dns-cluster-v1.0.sh
```
Expected: `==> DNS CLUSTER v1.0 INSTALLED`. Page: `/dns-cluster` — nodes add/remove + zone sync.
Sandbox test: DnsClusterTest **4/4 pass**. **Is installer me tile self-flip hai — dashboard-sync alag se NAHI chalana.**

> 📌 **Naya rule:** ab har naya feature-installer apna dashboard tile **khud** live karta hai, isliye `dashboard-sync`
> dobara **kabhi** manually nahi chalana. (install-all me wo sirf fresh-install ke liye internal catch-all hai.)

---

## Command #20 — cPanel PORTS PARITY (2082/2083/2086/2087/2095/2096)
```bash
sudo alphacp-sync get d2a54510170378bedfa99546b33e17551589a2da installer/ports-parity.sh /tmp/ports-parity-v1.0.sh 7520598cceb2117d193e00be07c349a28469d8eb56e54f6a624e3ebcf69ea3cb && sudo bash /tmp/ports-parity-v1.0.sh
```
Expected: `==> PORTS PARITY v1.0 APPLIED`. Phir panel in ports par bhi khulega:
- **cPanel:** `https://<host>:2083` (http 2082 redirect)
- **WHM:** `https://<host>:2087` (http 2086 redirect)
- **Webmail:** `https://<host>:2096` (http 2095 redirect)
Sandbox me sed-transform verified (8 listen lines). Server par `nginx -t` + **auto-revert** guard — fail ho to config wapas, panel safe.
`ufw` me ye 6 ports allow hote hain. Idempotent.

---

## Command #21 — Owner Ports Config (ports par OWNER ka control)
```bash
sudo alphacp-sync get 56e2925de37b27572d39c1a78d38bc8af5e7be29 installer/ports-config.sh /tmp/ports-config-v1.0.sh d6cb4015f7eba821bdd2006c8f50bba38754552487fea2778576759c8f0b710b && sudo bash /tmp/ports-config-v1.0.sh
```
Expected: `==> PORTS CONFIG v1.0 INSTALLED`. Owner page: `/ports` — 8090 primary + compatibility ports (2083/2087/2096)
toggle + custom ports. Save par `var/ports.json` likhta hai. Sandbox: PortsTest **2/2**. Brand-clean.

## Command #22 — Apply Ports (owner ke chune ports nginx par lagao)
```bash
sudo alphacp-sync get 56e2925de37b27572d39c1a78d38bc8af5e7be29 installer/apply-ports.sh /tmp/apply-ports-v1.0.sh 0866ad57e50415ea502c4282b2c52f4d7ea2f517be641b7f065b46c9e8e35cd2 && sudo bash /tmp/apply-ports-v1.0.sh
```
`/ports` se save karne ke BAAD ye chalao — nginx vhost ports.json se sync + ufw + `nginx -t`/auto-revert.
Sandbox me rewrite-logic verified (idempotent). **Flow: #21 → /ports par set → #22.** (#20 ab optional hai — owner-control aa gaya.)

---

## Command #23 — Demo/Test accounts (har panel ka ek account — testing ke liye)
```bash
sudo alphacp-sync get d5b3483fb4150885c1a43891176cf9747c4086e1 installer/demo-accounts.sh /tmp/demo-accounts-v1.0.sh 435d0b404c9417cc11b6ec9a9177a44128ce96b769220f931a36cb1a090dbb3c && sudo bash /tmp/demo-accounts-v1.0.sh
```
Custom password/domain chahiye to: `sudo bash /tmp/demo-accounts-v1.0.sh 'AapkaPass123' 'customer1.test'`
Banata hai: `demoresel` (Reseller) · `democust` (Hosting customer + hosting account + domain) · `demomail` (Email-only).
Root admin pehle se hai. Idempotent (password sirf `--reset-password` se badalta hai). **Testing-only — install-all me nahi.**
Sandbox: DemoAccountsTest **6/6 (46 assertions)** — login POST + login-page URL bhi verify.
**Login page = `https://<ip>:8090/`** (`/login` sirf POST accept karta hai — GET par error).

---

## Command #24 — Guest-safe error pages (404/405 par 500 crash fix)
```bash
sudo alphacp-sync get d5b3483fb4150885c1a43891176cf9747c4086e1 installer/guest-errors.sh /tmp/guest-errors-v1.0.sh 9cceccabc18feb32474189ee0fd8cff1186d6789fbfa768c01c4a184a1ef1c94 && sudo bash /tmp/guest-errors-v1.0.sh
```
Base bug: logged-out visitor galat URL khole to `layouts.panel` header `auth()->user()->username` maangta tha → **500 crash**.
Fix: header null-safe (backup `.bak` + idempotent). Sandbox: GuestErrorPageTest **3/3** — patch khud installer lagata hai
(unpatched run me 500 reproduce hua).

---

## Command #25 — Reseller scoping (WHM parity: reseller sirf apne accounts dekhe)
```bash
sudo alphacp-sync get bb722fd1ab561d34a39a1c959551698663f7d5b0 installer/reseller-scope.sh /tmp/reseller-scope-v1.0.sh f2392ae12c3391b26527c6569d29f40c1c4a9634deb50c60948294a14267a139 && sudo bash /tmp/reseller-scope-v1.0.sh
```
Base gap: reseller ko SAB accounts/users dikhte the. Ab additive provider se: accounts sirf apne (URL se bhi 404),
users list scoped, reseller reseller/root role nahi bana sakta. Root/CLI par zero asar. Sandbox: ResellerScopeTest **5/5**.

---

## Command #26 — Owner LIFETIME + UNLIMITED license (apna server kabhi lock nahi)
```bash
sudo alphacp-sync get 24f0f0fdb295c295ebb9da734da354e78cd1aa3a installer/owner-license.sh /tmp/owner-license-v1.0.sh 384c92a5550fc1b6aecdc5c0de7b41b06740b62c3ff7961fa52fc9e30486ea2a && sudo bash /tmp/owner-license-v1.0.sh
```
Trial (20 accounts / 13 Oct expiry) ki jagah owner tier: **max_accounts -1 (unlimited), expires_at null (lifetime)**,
fingerprint-bound offline record. Customer licenses alag se /license-server se issue hote hain.
Sandbox: LicenseOwnerTest + LicenseRenewTest **5/5 (18 assertions)**.

---

## Command #27 — Entry separation (cPanel jaisi alag entries)
```bash
sudo alphacp-sync get 545b56b411404f456325ccbef938c50a3c800ff9 installer/entry-gate.sh /tmp/entry-gate-v1.0.sh b0230ecd63f4de4a61487b146484b7ac9b42ac630a59e6d886c3b05792ead41d && sudo bash /tmp/entry-gate-v1.0.sh
```
8090/2087 = **Server Manager entry** (root/reseller) · 2083/2096 = **Account Panel entry** (customer/mail).
Galat entry par valid login bhi reject (role-enumeration safe). 2083 enable nahi hai to **single-entry mode** (sab 8090 par).
Activate karne ke liye: /ports par compatibility ports ON → Save → apply-ports (#22). Sandbox: EntryGateTest **3/3 (25)**.

---

## Command #28 — License Server v2 (customer = plan ke according license)
```bash
sudo alphacp-sync get 24f0f0fdb295c295ebb9da734da354e78cd1aa3a installer/license-server.sh /tmp/license-server-v2.0.sh f28931e26d4cea443e32d7bb65acbb183731c3839762474d210d309ef6aa6e22 && sudo bash /tmp/license-server-v2.0.sh
```
Plans: **starter=10 · pro=50 · business=200 accounts · owner=UNLIMITED+lifetime** (0 din sirf owner).
`/license-server` se key issue karo (license_uid) → customer panel `/license` par activate karta hai
(`/api/v1/activate`); cap + lifetime server-side enforce. Ed25519 signed (sodium hosts); warna online-verified hmac mode.
Sandbox: LicensePlanFlow + LicenseServer **10/10 (53 assertions)** + owner regression **5/5**.

---

## Command #29 — Standalone error pages (GET /login 500 ka DECISIVE fix)
```bash
sudo alphacp-sync get 8a4f61334cb09bd542896818f2a004d9ce5977c8 installer/error-pages.sh /tmp/error-pages-v2.0.sh fbb0808b057f4c84d8339db7e2e464d067eac798900cd865ea528647b48c75ed && sudo bash /tmp/error-pages-v2.0.sh
```
Root cause: fallback → `errors/404` → `layouts.panel` (DB `Panel::server()` + `auth()->user()`) —
guest context me crash = **500**. Ab **saare error pages (403/404/405/419/429/500/503) standalone**:
koi layout, DB ya auth dependency NAHI — galat URL kabhi 500 nahi dega.
Sandbox proof: layout ko jaan-boojh kar **break** karke bhi **7/7 tests (16 assertions)** pass,
exact `GET /login` no-500 test ke saath. `view:clear` bhi chalta hai (stale compiled views hat jayengi).
Expected: banner `==> STANDALONE ERROR PAGES v2.1 APPLIED (env hardened)` → `alphacp-sync`.
(v2.1: storage ownership fix + sab caches clear + php-fpm restart — stale compiled views/opcache blind-spot khatam.)

---

## Command #30 — Error-pages v2.1 env hardening (agar #29 ke baad bhi 500)
```bash
sudo alphacp-sync get bccc913ab1936742c7bd1d0d57329d626a1bee11 installer/error-pages.sh /tmp/error-pages-v2.1.sh 9cd9953ebaccf90f5455680c7665cad6060614b2193b564588dade692472f2c4 && sudo bash /tmp/error-pages-v2.1.sh
```
Karta hai: 7 standalone error views (v2.0 jaisa) + `chown storage/bootstrap` (fpm user) +
view/config/route/cache clear + **php8.4-fpm restart** (opcache flush).
Sandbox: worst-case (broken layout) me bhi **7/7 (16 assertions)**.
Agar iske baad bhi 500 dikhe (nahi chahiye), ye ek line paste karo — exact exception milegi:
`sudo tail -n 60 /usr/local/alphacp/panel/storage/logs/laravel.log | grep -A6 'ERROR' | tail -20`

---

## Command #34 — Storage ownership FINAL fix (socket-based detection + debug off)
```bash
sudo alphacp-sync get 4ec2f93d4fd7e46669fb7a5c7122d1c8eb1f4cda installer/storage-fix.sh /tmp/storage-fix-v1.0.sh 7d50758a32064a0106089e47a71fe2068f5ea4fe54a0c1685f2d662a7cd714eb && sudo bash /tmp/storage-fix-v1.0.sh
```
nginx socket → fpm pool → **asli web user** detect karke storage/bootstrap ko deta hai
(guess nahi), `APP_DEBUG=false` revert, fpm restart, `/` + `/login` status print.
Expected: `web user: alphacp` → `/ => 200`, `/login => 404` → `STORAGE FIX v1.0 APPLIED`.

---

## Command #33 — open_basedir 500 PERMANENT fix ⚡ PEHLE YE
```bash
sudo alphacp-sync get 0df1588bd63366c78a41c64a3c4b1e949081831f installer/openbasedir-fix.sh /tmp/openbasedir-fix-v1.0.sh 4d4205ac605b6e02c07221ecfd9315a4ce6775cf26e0d010c119e29f0af1eec4 && sudo bash /tmp/openbasedir-fix-v1.0.sh
```
Asli root cause (laravel log se): `EntryLoginController` `var/ports.json` par `is_file()`
karta tha — **open_basedir se bahar** → ErrorException → har request 500. Ab controllers
`etc/ports.json` (allowed path) use karte hain, try/catch ke saath — kabhi crash nahi.
Expected: `+ EntryLoginController.php` `+ PortsController.php` → `/ => 200`, `/login => 404`
→ `==> OPEN_BASEDIR FIX v1.0 APPLIED`. Sandbox proof: prod-repro **/ => 200, /login => 404**,
EntryGate **3/3 (25)**, errorpages **7/7 (16)**.

---

## Command #31 — EMERGENCY ownership fix (site down recovery)
```bash
sudo alphacp-sync get 68fb915fc912bb4a0c1b483409e459b91221fa52 installer/fix-ownership.sh /tmp/fix-ownership-v1.0.sh 7ae5f888cb5f4e9a0caba585843637b0c80c5af97e504b6207c4e1725c26ded6 && sudo bash /tmp/fix-ownership-v1.0.sh
```
#30 (v2.1) ne galti se storage ko root diya tha (fpm MASTER detect hua tha) → workers
ka write access gaya → poora panel 500. Ye script: **asli worker user** (count-based
detection + pool-config fallback) ko storage/bootstrap wapas deti hai, sahi php binary
(`/usr/bin/php8.4`) se view/config/route/cache clear, fpm restart, aur **aakhri ERROR
lines print** karti hai. Expected: `fpm worker user: www-data` → clears → `==> OWNERSHIP
FIX v1.0 APPLIED` → sync. Phir `https://IP:8090/` wapas live.

---

## 🚀 MASTER — naye/fresh VPS par SAB KUCH ek command se (portable 100%)
Base panel (alphacp-sync v1.5) ke baad, ye EK script saare 9 features laga deti hai
(FTP, Metrics, License, IP Blocker, WAF, App Installer, Monitoring, WHM API, API Tokens):
```bash
sudo alphacp-sync get 93121034992cf62b53107a03b667c628dc1f9d3b installer/install-all.sh /tmp/install-all-v1.0.sh 281988d3bd0583dd4e1cb70b7160e3706b36db9e26d6b113788eb3009c5734d6 && sudo bash /tmp/install-all-v1.0.sh
```
(Current server par sab already live hai — ye **future fresh servers** ke liye hai; idempotent, dobara chalana safe.)
- sha256: `1e197e0860ca3c59e400e1b5c8504d894ec889e316007143493d5e3855557db5`
- **Kyun:** v1.4 ke live run me bhi 4 files gayab rahi — un views/tests me placeholder
  (`-----BEGIN OPENSSH PRIVATE KEY-----`) / dummy `ghp_` token ko v1.4 ke hard patterns "secret"
  samajhte the. v1.5 me saare patterns **sirf config files** par; source par sirf literal
  server-secret scan. Ab completeness **khaali** aani chahiye aur asli 4 views GitHub par aayengi.
- Expected: banner `v1.5` → `completeness: panel ki har source file … snapshot me hai` →
  `==> SYNC OK ✅`.
- Verify (push ke baad):
  `gh api repos/abhay751218-hue/AlphaCP/contents/server-snapshot/files/usr/local/alphacp/panel/resources/views/transfer-tool/index.blade.php`
  → 200 (pehle 404).
- Test: `sudo bash tools/sim/sync-sim.sh` → **72/72**.
- ⚠️ Sync tool update + turant sync; panel code ko nahi chhoota. Safe.

### alphacp-sync v1.4 — ✅ chal chuka (7 Oct, 21:58Z). Superseded by v1.5.
```bash
sudo alphacp-sync get ff810fb2dacc19a0ce1f36ebad965fc64e71df07 installer/alphacp-sync.sh /tmp/acp-sync-v1.4.sh d32bc3fc9196867bd96fcafa34ba3c803a17bf893bdf27ed14f5a0f1f1ee0515 && sudo bash /tmp/acp-sync-v1.4.sh
```
- sha256: `d32bc3fc9196867bd96fcafa34ba3c803a17bf893bdf27ed14f5a0f1f1ee0515`
- **Kyun:** v1.3 ke live run me completeness check ne dikhaya ki 4 files phir bhi nahi aayi —
  un views me `PASSWORD: password,` jaisi JS lines ko v1.3 ka pattern abhi bhi "secret" samajhta
  tha, aur `bootstrap/cache`/`logs`/`backups` junk leak hone laga tha. v1.4 me KEY=VALUE scan
  **sirf config files** par hai aur junk prune hota hai. Tafseel `CHANGELOG.md`.
- Expected: banner `v1.4` → `completeness: panel ki har source file … snapshot me hai` →
  `==> SYNC OK ✅`. Ab STATE.md me missing list **khaali** honi chahiye.
- Verify (push ke baad):
  `gh api repos/abhay751218-hue/AlphaCP/contents/server-snapshot/files/usr/local/alphacp/panel/resources/views/transfer-tool/index.blade.php`
  → 200 aana chahiye (pehle 404 tha).
- Test: `sudo bash tools/sim/sync-sim.sh` → **70/70**.
- ⚠️ Ye sync tool update karta hai + turant sync karta hai. **Panel code ko chhoota tak nahi** —
  websites/email/DNS safe hain. Dobara chalana safe hai.

### alphacp-sync v1.3 — snapshot completeness fix. ✅ chal chuka (6 Oct, 21:35Z)
Superseded by **v1.4** (upar). v1.3 ne completeness check diya (usi ne 4 missing files pakdi),
par secret-scan abhi bhi unhe gira raha tha — isliye v1.4 zaroori hai.

### alphacp-sync v1.2 — ✅ chal chuka (server par `tool: alphacp-sync v1.2`, LAST-SYNC 6 Oct 08:47Z)
Superseded by **v1.3** (upar). Dobara mat chalao.

### Uske baad panel updates: panel-update 0.3.0 (private-ready) — agli panel release ke saath
- commit `0c90863a10e6c70984e627af1b819e66ae60b600`, sha256 `204b78af0b59b75614a61455df1ca96b5eb3c05f744b744647da1a33c4da8480`
- artifact + sync tool pehle `alphacp-sync get` se, fallback public URL. update-sim **54/54** (U5 private+get, U6 private+purana sync → saaf error).
- Abhi chalane ki zaroorat nahi (server already 0.3.2).

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
