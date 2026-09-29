# COMMANDS.md — server par chalane wali LIVE commands (sirf yahi chalao)

> Har command ek **commit-pinned GitHub link** se script download karti hai — link kabhi badalta nahi,
> aur jo file test hui thi wahi server par aati hai (byte-for-byte). paste.rs ab use nahi hota.
> Har script shuru me apna **version banner** print karti hai — banner me wahi version dikhna chahiye
> jo yahan likha hai. Purani (superseded) commands scrollback se **dobara mat chalao**.

## ✅ Abhi chalani hai (NEXT STEP)

### panel-update 0.2.1 → panel 0.3.2 + alphacp-sync v1.1 — auto-rollback ke saath
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/d741f796fd77792c5a77f6fbf8a063609b1e2866/installer/panel-update.sh -o /tmp/acp-panel-update-0.2.1.sh && sudo bash /tmp/acp-panel-update-0.2.1.sh
```
- script sha256: `d08387eaa34bcaf372286cad66ad175fe08d2e174bceafb62a04ddc45c9622fd`
- panel artifact: `panel-code-0.3.2.tar.gz` sha256 `7734b0c1d661cad83c3be6b432228b0ae61b20d522dda6aa743fca5605d73aab` (commit 6001033)
- sync tool: `alphacp-sync.sh` v1.1 sha256 `427512d87d5573bfbdf6a8d3a07d8505d3dd72738ffe7cfabc9c41c2052914f3` (commit 8cffb0c)
- Expected: banner `updater 0.2.1` → checksum verified → Composer → preflight → swap → `health HTTP 200`
  → `==> UPDATE COMPLETE ✅` → `alphacp-sync v1.1 install hua` → `GitHub updated`.
  Health fail ho to khud purana panel wapas (`Rollback successful`).
- DB, `.env`, APP_KEY, sessions, license/trial file (`storage/`) same rehte hain. Backups: aakhri 3.
- Test: `sudo bash tools/sim/update-sim.sh` → 43/43; `bash tools/sim/panel-tests.sh` → 42 pass / 0 fail.

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
| alphacp-sync v1.0 setup (`aa2091d…/installer/alphacp-sync.sh`) | 29 Sep | ✅ `main` par pehla snapshot `d5ae8d2` (314 files). Timer har ghante chalta hai. Manual: `sudo alphacp-sync`, status: `sudo alphacp-sync --status` |
| updater 0.1.0 (dusre AI ka, panel 0.3.1) | 29 Sep | ✅ server par 0.3.1 = source byte-for-byte (snapshot se verify) |

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
