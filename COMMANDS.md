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

## ✅ Abhi chalani hai (NEXT STEP) — 4 Oct 2026

### 1) panel-update 0.71.0 — S10: cpmove MySQL restore
```bash
sudo alphacp-sync get ff8e7079f173f2ab78422de84c33ed5717bf7677 installer/panel-update.sh /tmp/acp-panel-update-0.71.0.sh b744348c82239f0bd255bee5648bdc0230da4a9b76b1c9e23f0b724c11200640 && sudo bash /tmp/acp-panel-update-0.71.0.sh
```
- Updater SHA-256: `b744348c82239f0bd255bee5648bdc0230da4a9b76b1c9e23f0b724c11200640`.
- Expected: banner `updater 0.71.0` → panel **0.71.0** + agent **0.64.0** → `==> UPDATE COMPLETE ✅` → HTTP 200.
- **Naya kya:** cPanel import ka doosra hissa — pehle archive se sirf **home** aata tha, ab
  `cpmove-<user>/mysql/*.sql` dumps bhi **asli MariaDB** databases me restore hote hain
  (`<acct>_<suffix>`, utf8mb4). Panel me **Transfer Tool** aur **Transfer or Restore** dono pages par
  naya option **"MySQL databases bhi restore karo"** (+ chaho to sirf kuch databases: `mysql_only`).
  Pehle sab dumps **stage + sanitise** hote hain, phir MariaDB ko chhua jata hai — hostile archive se
  aadhe-adhure databases nahi bante. Apne database ke `USE`/`DROP|CREATE DATABASE` lines strip hote
  hain (mysqldump ka normal shape); **doosre database ka naam, `INTO OUTFILE`, `LOAD DATA`, `GRANT`,
  `CREATE/DROP USER`, `SET GLOBAL` → poora dump refuse**. Dumps client ko **stream** hote hain (RAM me
  nahi), staging hamesha delete. S8 ka sab kuch (Databases/MySQL Users) waise hi kaam karta rahega.

### 2) S10 live verification (recommended — ek hi command, sab khud saaf karta hai)
```bash
sudo alphacp-sync get fb19113582c5ffadf570ec6f94a0e43775c70560 tools/verify/s10-mysql-restore-check.sh /tmp/acp-s10-mysql-restore-check.sh 9f2d0292587a964f48a46870a9a6153756e21ea611d8ffdefdc75f65912af6b0 && sudo bash /tmp/acp-s10-mysql-restore-check.sh
```
- Server par **asli tar.gz** archive banata hai (homedir + `mysql/<acct>_acpverify.sql`), `db.restore`
  se import karta hai, aur **asli MariaDB** se verify karta hai — database bana? table bani? rows gine?
  row content sahi? koi MariaDB **user** na bane? Phir ek **hostile** archive (doosre database ka naam)
  refuse hone par koi database na bane — ye bhi check hota hai. Ant me sab saaf (dono database drop,
  archive dir delete, temp account terminate — `trap` me bhi).
- Expected last line: `=== S10 MYSQL RESTORE LIVE CHECK: 18 pass, 0 fail ===`.

- **Agar isme koi FAIL aaye** to ye read-only diagnostic chalao (server par kuch nahi badalta) aur
  uska output bhej do — isme asli wajah likhi hoti hai (version, `db.restore` allowlist, archive dir,
  panel account ↔ asli Linux user, MariaDB root, pichle db.restore tasks, bacha-khucha):
```bash
sudo alphacp-sync get 1035d1e68d5bf1cfbe8e35e4b7b27d3a451a4727 tools/verify/s10-diag.sh /tmp/acp-s10-diag.sh 75e9f863b1afa189a67aac9f2e8bdddbc76088448f7cfed6bf93802ccf94dc7c && sudo bash /tmp/acp-s10-diag.sh
```
- Chaaho to ye step skip karo — update khud-tested hai (panel **437/0** + 6 wasm-skip, update-sim
  **234/234**, provision-sim **109/109**, mysql-sim **PASS**, s10-mysql-restore-sim **15/0**).

- Ye 0.70.1 (agent useradd fix) ke upar baithta hai — purani command dobara chalane ki zaroorat nahi.

## ✅ Latest deployment (4 Oct 2026; already completed)

### panel-update 0.70.1 — agent 0.63.0: useradd GECOS fix (deployed 4 Oct, 15:14Z)
```bash
sudo alphacp-sync get 6fe360aba5d55b970aebf85995ead92124ebf547 installer/panel-update.sh /tmp/acp-panel-update-0.70.1.sh f2870f78225a53d821d0808f95c69e351fa98b5b7a44479594952e46ca2c8c64 && sudo bash /tmp/acp-panel-update-0.70.1.sh
```
- **Live result:** agent **0.63.0**, HTTP 200; `tools/verify/s8-live-check.sh` → **21 pass / 0 fail**
  (tasks #184–#191) — yahi script ne pakda tha ki panel ka Create Account colon-wale GECOS ki wajah se
  har asli host par fail hota tha.

### panel-update 0.70.0 — S8: real MySQL/MariaDB databases + users (deployed 4 Oct, 14:55Z)
```bash
sudo alphacp-sync get 683e8b6a48d6b62373d405e5e0c5e3f43bf14889 installer/panel-update.sh /tmp/acp-panel-update-0.70.0.sh 010b0a3379a226c58aa055a2fed4bac646600db7ec37ce040f1df0d2c82ca098 && sudo bash /tmp/acp-panel-update-0.70.0.sh
```
- **Live result:** panel **0.70.0** + agent 0.62.0, HTTP **200**, Ghunghar ``server-snapshot``
  (``mysql_users`` / ``mysql_user_grants`` tables live hain).
- Databases/Wizard/MySQL Users ab asli MariaDB objects banate hain (SQL stdin se, password sirf ek baar).
- Isi release ne asli host ka pehla account-create bug bhi ujagar kiya — fix 0.70.1 me hai.

### panel-update 0.69.0 — S10: real cPanel account import + transfer job history
```bash
sudo alphacp-sync get b23fafec02f187c49294b25b0bf708161d95885d installer/panel-update.sh /tmp/acp-panel-update-0.69.0.sh 83f15b4b2f030b8a347429cb72963a5d10ee907c2b9884b8938e7e1d5afd1b7d && sudo bash /tmp/acp-panel-update-0.69.0.sh
```
- **Live result:** server snapshot 2026-10-04 03:30Z → panel **0.69.0**, agent **0.61.0**, HTTP **200**.
- Updater SHA-256: `83f15b4b2f030b8a347429cb72963a5d10ee907c2b9884b8938e7e1d5afd1b7d`.
- WHM transfer pages (Transfer Tool / Transfer or Restore / Review Transfers) ab asli
  `cpmove-<user>.tar.gz` (ya legacy/nested) import karte hain: sha256 verify, sirf `homedir`,
  staging + home swap, purana home `/home/.acp-prerestore-<user>-<stamp>` me, hostile archive fail closed,
  aur Review page asli job history dikhata hai. Naya drop dir `/usr/local/alphacp/incoming`.

### panel-update 0.68.0 — S10: scheduled backups (cron, history)
```bash
sudo alphacp-sync get 337516473d108f7c40e19cbce7b0c168aafe59fb installer/panel-update.sh /tmp/acp-panel-update-0.68.0.sh d3cb42257ac05e4fc7e1596962ac8059f6355a177fe1efef3f4044768e624071 && sudo bash /tmp/acp-panel-update-0.68.0.sh
```
- Live result (0.68.0 ke waqt): panel **0.68.0**, agent **0.60.0**, HTTP **200**.
- WHM → Backup Config ka daily/weekly/monthly schedule ab cron se sach me chalta hai; ek window me ek hi
  pass, retention + Backup User Selection list honour hoti hai. Default schedule disabled hai —
  auto-backups ke liye WHM → Backup Configuration me schedule set karna zaroori hai.

### panel-update 0.65.0 — S10: verified home archive + account-scoped download (history)
```bash
sudo alphacp-sync get 491c62966025ba8a251a9bb0a72a5c7071abed2d installer/panel-update.sh /tmp/acp-panel-update-0.65.0.sh 2e77e8f9ee58e5ab9c58595edbe3d7193b63dc8d6125432b019ec12cf879aec5 && sudo bash /tmp/acp-panel-update-0.65.0.sh
```
- **Live result:** `UPDATE COMPLETE`; panel **0.65.0**, agent **0.58.0**, HTTP **200**. Server snapshot was synced at 2026-10-03 14:20 UTC. Rollback backup: `/usr/local/alphacp/releases/panel-backup-20261003142005`.
- Updater SHA-256: `2e77e8f9ee58e5ab9c58595edbe3d7193b63dc8d6125432b019ec12cf879aec5`.
- Bundles pinned to commit `8b1ca1e3ac735dcd5ff103d7f07b8344489ca3b7`: panel SHA-256 `2e0310c4eb946353401bbb992ed39e987a4f10e8986ec5621f6b828f699cbf0e`; agent SHA-256 `b30f340806b1eed18ed0e58a50bc1ff0f39b612f6762851f772f350e916b1083`.
- This updated the server directly from **0.63.0 / 0.56.0**; the 0.64 changes are included, so no separate 0.64 update was needed. The command is retained for audit; **do not rerun unless intentionally redeploying**.
- Us waqt (0.65.0) S10 partial tha: only home files are archived. Mail/MySQL exports, safe restore, schedules, remote storage, real cPanel transfer/import, and transfer/restore history remain incomplete. A live customer archive/download has not yet been end-to-end exercised.
- Tests: panel **401 pass / 0 fail / 6 wasm-skip**, provision-sim **91/91**, update-sim **185/185**, GNU tar round-trip/symlink smoke test PASS.

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
| panel-update 0.67.0 (`4d99491…`) → panel 0.67.0 + agent 0.60.0 | 4 Oct | ✅ UPDATE COMPLETE (snapshot 02:04Z), HTTP 200; safe home restore live |
| panel-update 0.66.0 (`039efb9…`) → panel 0.66.0 + agent 0.59.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200; symlink root-write escape fix live |
| panel-update 0.65.0 (`491c629…`) → panel 0.65.0 + agent 0.58.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200; home archive slice deployed |
| panel-update 0.63.0 (`4c1195b…`) → panel 0.63.0 + agent 0.56.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Transfer or Restore a cPanel Account |
| panel-update 0.62.0 (`16f2c24…`) → panel 0.62.0 + agent 0.55.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Transfer Tool |
| panel-update 0.61.0 (`0b4919d…`) → panel 0.61.0 + agent 0.54.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, File and Directory Restoration |
| panel-update 0.60.0 (`65eb642…`) → panel 0.60.0 + agent 0.53.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Backup User Selection |
| panel-update 0.59.0 (`56d98f5…`) → panel 0.59.0 + agent 0.52.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Backup Restoration |
| panel-update 0.58.0 (`98da0f4…`) → panel 0.58.0 + agent 0.51.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Backup Config |
| panel-update 0.57.0 (`1c769fe…`) → panel 0.57.0 + agent 0.50.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, File Restoration |
| panel-update 0.56.0 (`90314bf…`) → panel 0.56.0 + agent 0.49.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Backup Wizard |
| panel-update 0.55.0 (`8105811…`) → panel 0.55.0 + agent 0.48.0 | 3 Oct | ✅ UPDATE COMPLETE, HTTP 200, Backup |
| panel-update 0.54.0 (`76efb34…`) → panel 0.54.0 + agent 0.47.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Nameserver Selection |
| panel-update 0.53.0 (`439efc4…`) → panel 0.53.0 + agent 0.46.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Synchronize DNS Records |
| panel-update 0.52.0 (`2ba0e82…`) → panel 0.52.0 + agent 0.45.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Setup/Edit Domain Forwarding |
| panel-update 0.51.0 (`60bdca4…`) → panel 0.51.0 + agent 0.44.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Set Zone TTL |
| panel-update 0.50.0 (`4154c83…`) → panel 0.50.0 + agent 0.43.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Perform a DNS Cleanup |
| panel-update 0.49.0 (`a41d41a…`) → panel 0.49.0 + agent 0.42.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Park a Domain |
| panel-update 0.48.0 (`c3512a3…`) → panel 0.48.0 + agent 0.41.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Nameserver Record Report |
| panel-update 0.47.0 (`f9ebf5c…`) → panel 0.47.0 + agent 0.40.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Email Routing Configuration |
| panel-update 0.46.0 (`571bfe6…`) → panel 0.46.0 + agent 0.39.0 | 2 Oct | ✅ UPDATE COMPLETE, HTTP 200, Edit Zone Templates |
| panel-update 0.45.0 (`b90627a…`) → panel 0.45.0 + agent 0.38.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Hostname A |
| panel-update 0.44.0 (`45d19bf…`) → panel 0.44.0 + agent 0.37.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Add/Delete DNS Zone |
| panel-update 0.43.0 (`976fee2…`) → panel 0.43.0 + agent 0.37.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, DNS Zone Manager |
| panel-update 0.42.0 (`5027974…`) → panel 0.42.0 + agent 0.37.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Track DNS |
| panel-update 0.41.0 (`8fba292…`) → panel 0.41.0 + agent 0.36.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Dynamic DNS |
| panel-update 0.40.0 (`c93bd5c…`) → panel 0.40.0 + agent 0.35.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Zone Editor |
| panel-update 0.39.0 (`32a37c8…`) → panel 0.39.0 + agent 0.34.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Remote MySQL |
| panel-update 0.38.0 (`67e6e74…`) → panel 0.38.0 + agent 0.33.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, phpMyAdmin |
| panel-update 0.37.0 (`0b4e24a…`) → panel 0.37.0 + agent 0.32.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Database Wizard |
| panel-update 0.36.0 (`2dc2f39…`) → panel 0.36.0 + agent 0.32.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, MySQL Databases |
| panel-update 0.35.0 (`035aae4…`) → panel 0.35.0 + agent 0.31.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Webmail |
| panel-update 0.34.0 (`86b31ef…`) → panel 0.34.0 + agent 0.30.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Email Disk Usage |
| panel-update 0.33.0 (`7ba1223…`) → panel 0.33.0 + agent 0.29.0 | 1 Oct | ✅ UPDATE COMPLETE, HTTP 200, Calendar |
| panel-update 0.32.0 (`8e19b70…`) → panel 0.32.0 + agent 0.28.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, BoxTrapper |
| panel-update 0.31.0 (`8617eab…`) → panel 0.31.0 + agent 0.27.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Encryption |
| panel-update 0.30.0 (`5fc5fb7…`) → panel 0.30.0 + agent 0.26.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Address Importer |
| panel-update 0.29.0 (`acb64df…`) → panel 0.29.0 + agent 0.26.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Global Email Filters |
| panel-update 0.28.0 (`0120747…`) → panel 0.28.0 + agent 0.25.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Track Delivery |
| panel-update 0.27.0 (`c3a4404…`) → panel 0.27.0 + agent 0.24.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Email Routing |
| panel-update 0.26.0 (`9c8d47e…`) → panel 0.26.0 + agent 0.23.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Mailing Lists |
| panel-update 0.25.0 (`75e7dee…`) → panel 0.25.0 + agent 0.22.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Spam Filters |
| panel-update 0.24.0 (`d8269bd…`) → panel 0.24.0 + agent 0.21.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Deliverability |
| panel-update 0.23.0 (`548da67…`) → panel 0.23.0 + agent 0.20.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Email Filters |
| panel-update 0.22.0 (`30bb628…`) → panel 0.22.0 + agent 0.19.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Default Address |
| panel-update 0.21.0 (`920ea35…`) → panel 0.21.0 + agent 0.18.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Autoresponders |
| panel-update 0.20.0 (`d71a2ab…`) → panel 0.20.0 + agent 0.17.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Forwarders |
| panel-update 0.19.0 (`373d7e2…`) → panel 0.19.0 + agent 0.16.0 | 30 Sep | ✅ UPDATE COMPLETE, HTTP 200, Email Accounts |
| panel-update 0.18.0 (`379c9ea…`) → panel 0.18.0 + agent 0.15.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, SSH Access |
| panel-update 0.17.0 (`c9bdbf5…`) → panel 0.17.0 + agent 0.14.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, Disk Usage |
| panel-update 0.16.0 (`55142ec…`) → panel 0.16.0 + agent 0.13.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, Directory Privacy |
| panel-update 0.15.0 (`a03bdc7…`) → panel 0.15.0 + agent 0.12.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, File Manager |
| panel-update 0.14.0 (`beaca4c…`) → panel 0.14.0 + agent 0.11.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, Apache Handlers |
| panel-update 0.13.0 (`b2c1fc7…`) → panel 0.13.0 + agent 0.10.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, MIME Types |
| panel-update 0.12.0 (`be1ba8c…`) → panel 0.12.0 + agent 0.9.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, Indexes |
| panel-update 0.11.0 (`14e8fcf…`) → panel 0.11.0 + agent 0.8.0 | 29 Sep | ✅ UPDATE COMPLETE, HTTP 200, Error Pages |
| panel-update 0.10.0 (`fd5cb6f…`) → panel 0.10.0 + agent 0.7.0 | 29 Sep 03:41Z | ✅ UPDATE COMPLETE, HTTP 200, MultiPHP INI Editor |
| panel-update 0.9.0 (`2060887…`) → panel 0.9.0 + agent 0.6.0 | 29 Sep 03:30Z | ✅ UPDATE COMPLETE, HTTP 200, AutoSSL Let's Encrypt |
| panel-update 0.8.0 (`e854e3a…`) → panel 0.8.0 + agent 0.5.0 | 29 Sep 03:17Z | ✅ UPDATE COMPLETE, HTTP 200, SSL/TLS Status self-signed |
| panel-update 0.7.0 (`ca2f337…`) → panel 0.7.0 + agent 0.4.0 | 29 Sep 02:57Z | ✅ UPDATE COMPLETE, HTTP 200, MultiPHP + Cron, php-all 7.4–8.4 |
| panel-update 0.6.0 (`48f94a6…`) → panel 0.6.0 + agent 0.3.0 | 29 Sep 02:41Z | ✅ UPDATE COMPLETE, HTTP 200, domains migration, WHM/cPanel split |
| panel-update 0.5.0 (`35b2497…`) → panel 0.5.0 + agent 0.2.0 | 29 Sep 01:21Z | ✅ UPDATE COMPLETE, HTTP 200, packages migration, trial same |
| panel-update 0.4.0 (`430ccf0…`) → panel 0.4.0 + agent 0.2.0 | 29 Sep 01:07Z | ✅ UPDATE COMPLETE, HTTP 200, accounts routes + migration, trial same |
| alphacp-sync v1.2 (`4b4573f…/installer/alphacp-sync.sh`) + `get` test | 29 Sep | ✅ private repo; `sudo alphacp-sync get` pass |
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
