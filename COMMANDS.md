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

### 🔴 login-fix v1.0 — LOGIN TOOTA HUA THA (5 bug: B1–B5). **Yahi chalao.**
```bash
sudo alphacp-sync get a965399c25d9c6bf9638182258ffa94576b9de75 installer/login-fix.sh /tmp/login-fix-v1.0.sh adb269b8c2473f56f7a2b15af75538c5bd4e9253d4e24b93796eac9ee7de7318 && sudo bash /tmp/login-fix-v1.0.sh
```
- commit `a965399c25d9c6bf9638182258ffa94576b9de75`, sha256 `adb269b8c2473f56f7a2b15af75538c5bd4e9253d4e24b93796eac9ee7de7318`
  (GitHub API se verify: byte-for-byte identical, `bash -n` OK).
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
- **Prompt par kya bharein:** "Test ke liye panel username" par apna root username
  (`login_attempts` ke hisaab se `admin`) + password → script asli login probe karegi
  (pehle FAIL dikhega = purana bug ka proof; fix ke baad Step 10 me PASS).
  Khaali chhodoge to probe skip — fix phir bhi poori hoti hai.
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
