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

### 🔴 agent-fix v1.0 — MySQL provisioning live par FATAL tha (B6). **Yahi chalao.**
```bash
sudo alphacp-sync get 321c81929df94e6d2b05a29b912eaa31fba4209d installer/agent-fix.sh /tmp/agent-fix-v1.0.sh d03f3cd69620d21e1f0c4aef9f84b85fa1e7ec115899526f69c805bcb567e9a1 && sudo bash /tmp/agent-fix-v1.0.sh
```
- commit `321c81929df94e6d2b05a29b912eaa31fba4209d`, sha256 `d03f3cd69620d21e1f0c4aef9f84b85fa1e7ec115899526f69c805bcb567e9a1`.
- **Kya karta hai:** live agent me `src/MysqlServer.php` **gayab** thi → panel se MySQL
  database/user banana + cPanel-import/backup-restore ka mysql path agent-step par
  `Class "Alphacp\Agent\MysqlServer" not found` se **fatal** tha. Ye script wahi class
  (sandbox me reconstruct + **212/0** verify) sirf EK file me rakhta hai; baaki agent untouched.
  **Koi interactive prompt nahi** — command chalte hi poori hogi.
- **Expected output:** banner `AlphaCP — AGENT FIX v1.0` → preflight → backup →
  `MysqlServer.php likhi` → `php -l clean` → `SELF-TEST 1/2 … static smoke PASS` →
  `SELF-TEST 2/2 … agent suite GREEN (passed=212 failed=0)` → `paneld active` →
  `alphacp-sync complete` → `FINAL VERDICT … APPLY ho gaya`.
- **Agar self-test fail ya paneld active na ho** → script **apne aap rollback** kar deta hai
  (backup wapas), deploy ruk jaata hai — server tootta hua nahi chhoda jaata.
- **Sirf dekhna ho, kuch badle nahi:** `sudo bash /tmp/agent-fix-v1.0.sh --diagnose`
- **Wapas jaana ho:** `sudo bash /tmp/agent-fix-v1.0.sh --rollback`
  (backup: `/usr/local/alphacp/releases/agentfix-<ts>/`)
- **Verify (fix ke baad):** panel me ek MySQL database + user banao (pehle fatal hota tha, ab
  banna chahiye). Ya terminal se: `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1`
  → `passed: 212   failed: 0`.
- Test: `sudo bash tools/sim/agent-fix-sim.sh` → **17/17**.

### ✅ login-fix v1.0 — APPLIED 7 Oct 13:04Z (ab MAT chalao; sirf history/rollback ke liye)
```bash
sudo alphacp-sync get 269eb3c22ae49dd5bba76a5ce2750b594f65ff2c installer/login-fix.sh /tmp/login-fix-v1.0.sh 6ca53d4d163f7dc736963f5d8cb18084c3b44c6185a8891a8b61981a8960e6cf && sudo bash /tmp/login-fix-v1.0.sh
```
- commit `269eb3c22ae49dd5bba76a5ce2750b594f65ff2c`, sha256 `6ca53d4d163f7dc736963f5d8cb18084c3b44c6185a8891a8b61981a8960e6cf`
  (GitHub API se verify: byte-for-byte identical). **Isme koi interactive prompt NAHI hai** —
  mobile SSH par pichla run Step 2 ke prompt par atak kar adhoora reh gaya tha, isliye fix
  apply hi nahi hui thi. Purane pin (`a965399`/`adb269b8…`, `38d790b`/`c00b06c8…`,
  `c5115e7`/`db40c7d3…`) superseded — dobara mat chalao.
  Superseded pins: `38d790b`/`c00b06c8…` (PANEL_USER detection artisan-owner se hoti thi —
  live par `root` mila, jabki fpm pool `alphacp` hai; Step 8 storage root:root kar deta)
  aur `c5115e7`/`db40c7d3…` (comment count). **Purane pin dobara mat chalao.**
- **Expected output:** banner `AlphaCP LOGIN FIX - v1.0` → Step 1 me `BUG B1 … B5` lines (kitne bug
  the) → Step 3 `unlocked users: N` → Step 4/4b/5/6 `installed:` / `patched` → Step 7 truth file →
  Step 9 `SELFTEST: 16 pass, 0 fail` (usme `PASS B5: session se user resolve hua`) →
  Step 10 `==> FIX APPLY HO GAYA ✅`.
- **Asli wajah (B5):** `ResellerScopeProvider` ke global scopes `Auth::user()` call karte the, jo
  khud `retrieveById()` → wahi scope → **infinite recursion** → PHP fatal → har authenticated page
  par HTTP 500. Login POST 302 deta tha, phir `/dashboard` 500. Tests isko nahi pakad paate the
  (`actingAs()` shortcut). Detail: CHANGELOG `[Unreleased]`.
- **Sirf dekhna ho, kuch badle nahi:** `sudo bash /tmp/login-fix-v1.0.sh --diagnose`
- **Entry separation ASLI me live karni ho** (nginx par 2083/2087/2096 listen) — ye **port badlav**
  hai, isliye OPT-IN hai; pehle pooch ke hi chalao:
  `sudo bash /tmp/login-fix-v1.0.sh --enable-ports`  (`nginx -t` fail → apne aap rollback)
- **Wapas jaana ho:** `sudo bash /tmp/login-fix-v1.0.sh --rollback`
  (backup: `/usr/local/alphacp/releases/loginfix-<ts>/`; kuch delete nahi hota)
- **Koi prompt nahi** — command chalte hi poori hogi, kuch type nahi karna. Live login probe
  chahiye to alag se: `sudo ACP_FIX_USER=admin ACP_FIX_PASS='******' bash /tmp/login-fix-v1.0.sh`
  (password shell history me jaayega, isliye default run me probe skip hi theek hai).
- Test: `sudo bash tools/sim/login-entry-sim.sh` → **53/53** (P8+P9 = bug-proof).
- Agar `alphacp-sync get` par `unknown option` aaye to server ka sync tool v1.2 se purana hai —
  batao, pehle sync-tool upgrade denge.

### alphacp-sync v1.2 — private repo support (`get` mode). ✅ Server par v1.5 chal raha hai (LAST-SYNC.md).
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/4b4573f96f55927ee1fbf526037785dcdb82aea1/installer/alphacp-sync.sh -o /tmp/acp-sync-v1.2.sh && sudo bash /tmp/acp-sync-v1.2.sh
```
- sha256: `c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852`
- Expected: banner `v1.2` → (key pehle se hai, dobara add nahi karni) → `==> SYNC OK ✅` (ya "koi badlav nahi").
- Test: `sudo bash tools/sim/sync-sim.sh` → 60/60 (Run 8 = get: sha verify, galat sha, traversal, PR-ref commit, no key).
  GitHub par SHA-fetch + `refs/pull/*` fetch asli repo par verify kiya (29 Sep).

### ✅ AB CHALAO — panel-update 0.77.0 (P-UI-1: cPanel / WHM / Webmail alag-alag colours)
```bash
sudo alphacp-sync get 3255940d55549f3e26f73364c143ccd758ad800b installer/panel-update.sh /tmp/panel-update-0.77.0.sh a21a65d8c409a73d903d5a8e7c3887fdbb2f094868783306c4b58d583411d253 && sudo bash /tmp/panel-update-0.77.0.sh
```
- sha256 (script): `a21a65d8c409a73d903d5a8e7c3887fdbb2f094868783306c4b58d583411d253`
- **Kya badlega:** panel 0.75.0 → **0.77.0** = P-UI-1 per-panel theme engine —
  `root/reseller` → **WHM look** (navy sidebar `#293A4A` + orange `#FF6C2C`), `customer` → **cPanel look**
  (paper-white Tools grid + right rail **General Information + Statistics**), `mail` role → **Webmail look**.
  Saath me: top **"Find functions quickly…"** search, **Change Style** page, nav icons.
  Detail: `docs/10-ui-parity-design.md`. Panel suite **483/0** (6 wasm-skip = sandbox limit).
- **Kya nahi badlega:** agent (0.83.0 hi), database/schema (koi migration nahi), `.env`, APP_KEY,
  storage, admin password. Health check fail = **auto rollback**.
- Backups: `/usr/local/alphacp/releases/panel-backup-<ts>/` (aakhri 3 rakhta hai).
  Log: `/var/log/alphacp-panel-update.log`.
- Expected banner: `AlphaCP existing-server updater 0.77.0` → `Panel bundle: 0.77.0` →
  `artifact source: alphacp-sync get (deploy key)` → `==> UPDATE COMPLETE ✅`.
- Test: `sudo bash tools/sim/update-sim.sh` → **60/60** (U1 update 0.3.0→0.77.0, U2 sha mismatch,
  U3 health-fail rollback, U4 prune + sync-tool checksum fail, U5 private repo + `get`, U6 private +
  purana sync → saaf error; + 5 naye P-UI-1 file checks).
- Wapas jaana ho: `sudo mv /usr/local/alphacp/releases/panel-backup-<ts> /usr/local/alphacp/panel && sudo systemctl restart php8.4-fpm`
  (batao to rollback script bhi de denge).

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
| alphacp-sync v1.0 setup (`aa2091d…/installer/alphacp-sync.sh`) | 29 Sep | ✅ `main` par pehla snapshot `d5ae8d2` (314 files). Timer har ghante chalta hai. Manual: `sudo alphacp-sync`, status: `sudo alphacp-sync --status` |
| updater 0.1.0 (dusre AI ka, panel 0.3.1) | 29 Sep | ✅ server par 0.3.1 = source byte-for-byte (snapshot se verify) |
| panel-update 0.2.1 (`d741f79…`) → panel 0.3.2 + sync v1.1 | 29 Sep 00:17Z | ✅ UPDATE COMPLETE, HTTP 200, trial same (expiry 13 Oct), snapshot `ebdbd75` |
| alphacp-sync v1.5 (server par khud) | 7 Oct 09:51Z | ✅ `LAST-SYNC.md` me `tool: alphacp-sync v1.5`; timer active. `get` mode available (v1.2+) |

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
