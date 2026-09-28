# COMMANDS.md — server par chalane wali LIVE commands (sirf yahi chalao)

> Har command ek **commit-pinned GitHub link** se script download karti hai — link kabhi badalta nahi,
> aur jo file test hui thi wahi server par aati hai (byte-for-byte). paste.rs ab use nahi hota.
> Har script shuru me apna **version banner** print karti hai — banner me wahi version dikhna chahiye
> jo yahan likha hai. Purani (superseded) commands scrollback se **dobara mat chalao**.

## ✅ Abhi chalani hai (NEXT STEP)

### alphacp-sync v1.0 — server → GitHub auto-sync (ek baar setup, phir har ghante khud)
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/aa2091dc3ee28850266b8348aea1ea89408c64c2/installer/alphacp-sync.sh -o /tmp/acp-sync-v1.0.sh && sudo bash /tmp/acp-sync-v1.0.sh
```
- sha256: `bef5334bf07a57a3817c160d926b264272217e4442799599e7cfb502d352fdcd`
- Screen par ek `ssh-ed25519 ...` line aayegi. Use https://github.com/abhay751218-hue/AlphaCP/settings/keys/new me
  paste karo aur **"Allow write access" par tick** lagao. Script khud wait karke aage badhegi aur `==> SYNC OK ✅` dikhayegi.
- Baad me turant sync karna ho to: `sudo alphacp-sync`. Status ke liye: `sudo alphacp-sync --status`
- Test: `sudo bash tools/sim/sync-sim.sh` → 45/45 PASS.

## ⏭️ Uske baad (queued — sync OK hone ke baad hi)

### panel-update 0.2.0 → panel 0.3.2 (admin-password rescue fix) — auto-rollback ke saath
```bash
curl -fsSL https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/1d61cb850b851d6ad09891fc9557e562847889c2/installer/panel-update.sh -o /tmp/acp-panel-update-0.2.0.sh && sudo bash /tmp/acp-panel-update-0.2.0.sh
```
- script sha256: `d52503e72a61e1cf9f358fc6b7f579d92f7a7be3ebd016d5ed1eb039efe310b0`
- artifact: `panel-code-0.3.2.tar.gz` sha256 `7734b0c1d661cad83c3be6b432228b0ae61b20d522dda6aa743fca5605d73aab` (commit 6001033)
- Expected: banner `updater 0.2.0` → checksum verified → Composer → preflight → swap → `health HTTP 200`
  → `==> UPDATE COMPLETE ✅` → `GitHub updated`. Health fail ho to khud purana panel wapas (`Rollback successful`).
- DB, `.env`, APP_KEY, sessions, license/trial file (`storage/`) same rehte hain. Backups: aakhri 3.
- Test: `sudo bash tools/sim/update-sim.sh` → 37/37; `bash tools/sim/panel-tests.sh` → 42 pass / 0 fail.

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
| `arena/01a0ea0d-alphacp/installer/panel-update.sh` (updater 0.1.0, panel 0.3.1) | chal chuka (S2C deployed); ab 0.2.0 → 0.3.2 |
| paste.rs/G72oK (doctor v1.6) | v1.7 me cwd bug fix + security step |
| paste.rs/pnV7U, LxbJT, vbVD9, wsPmr (doctor v1.5–v1.1) | superseded |
| paste.rs/0r1Mi, VD0Px, vVdFC, EPW3b (installer v0.3.3–v0.3.6) | panel install ho chuka hai; v0.3.7 aayega |
