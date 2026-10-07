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

### panel-update 0.3.1 → panel 0.76.0 — 3 colour panels (WHM dark navy / cPanel light / webmail teal) 🎨
```bash
sudo alphacp-sync get becb79066e91c685610438fd1f6d3626cfc6e5eb installer/panel-update.sh /tmp/panel-update-0.3.1.sh 3e471c99a5b1aa850ac6f7bf051dadfc5abe9aa18d7bb118d79f19d9f47a8f81 && sudo bash /tmp/panel-update-0.3.1.sh
```
- sha256: `3e471c99a5b1aa850ac6f7bf051dadfc5abe9aa18d7bb118d79f19d9f47a8f81`
- Expected: banner `0.3.1` → `artifact source: alphacp-sync get (deploy key)` → `artifact checksum verified` →
  `==> UPDATE COMPLETE ✅` · `New panel: 0.76.0`
- Kya badlega: panel 0.75.0 → **0.76.0** — sirf UI: WHM (root/reseller) ko **dark navy theme + left sidebar**
  (Server/Accounts/DNS/Backup/Security/System), customer ko **light theme** (cPanel-jaisa blue),
  `/webmail` page ko **teal theme**. `.env`, APP_KEY, DB, accounts, customer sites — kuch nahi chhedta.
- Test: `bash tools/sim/panel-tests.sh` → **479 pass, 0 fail, 6 wasm-skip** · `sudo bash tools/sim/update-sim.sh` → **53 pass, 0 fail** (3 skip: clone history truncated).

### Uske baad (repo private karne se PEHLE): alphacp-sync v1.2 — `get` mode
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/4b4573f96f55927ee1fbf526037785dcdb82aea1/installer/alphacp-sync.sh -o /tmp/acp-sync-v1.2.sh && sudo bash /tmp/acp-sync-v1.2.sh
```
- sha256: `c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852`
- Expected: banner `v1.2` → (key pehle se hai, dobara add nahi karni) → `==> SYNC OK ✅` (ya "koi badlav nahi").
- Test: `sudo bash tools/sim/sync-sim.sh` → 60/60. (Server par pehle se chala — dobara zaroorat nahi.)

### panel-update 0.3.0 (private-ready) — purani release (superseded by 0.3.1)
- commit `0c90863a10e6c70984e627af1b819e66ae60b600`, sha256 `204b78af0b59b75614a61455df1ca96b5eb3c05f744b744647da1a33c4da8480`
- artifact + sync tool pehle `alphacp-sync get` se, fallback public URL. update-sim **54/54** (U5 private+get, U6 private+purana sync → saaf error).
- Abhi chalane ki zaroorat nahi (server already 0.3.2).

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
| alphacp-sync v1.0 setup (`aa2091d…/installer/alphacp-sync.sh`) | 29 Sep | ✅ `main` par pehla snapshot `d5ae8d2` (314 files). Timer har ghante chalta hai. Manual: `sudo alphacp-sync`, status: `sudo alphacp-sync --status` |
| updater 0.1.0 (dusre AI ka, panel 0.3.1) | 29 Sep | ✅ server par 0.3.1 = source byte-for-byte (snapshot se verify) |
| panel-update 0.2.1 (`d741f79…`) → panel 0.3.2 + sync v1.1 | 29 Sep 00:17Z | ✅ UPDATE COMPLETE, HTTP 200, trial same (expiry 13 Oct), snapshot `ebdbd75` |
| panel-update 0.3.0 (`0c90863a…`) | 29 Sep | superseded — 0.3.1 use karo |

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
