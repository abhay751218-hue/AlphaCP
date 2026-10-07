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
Phir `https://<host>:8090/security-tools` — WAF on/off + Virus Scan.
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
