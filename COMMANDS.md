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

### ⭐ alphacp-sync v1.3 — snapshot completeness fix. **Sabse pehle yahi chalao.**
```bash
sudo alphacp-sync get a569e66fd2bbdb058769f05b50c836b772b299c4 installer/alphacp-sync.sh /tmp/acp-sync-v1.3.sh d5beafb39244457bdedd750d4afc8376789fe5f3e1228885227be5779a9f897b && sudo bash /tmp/acp-sync-v1.3.sh
```
- sha256: `d5beafb39244457bdedd750d4afc8376789fe5f3e1228885227be5779a9f897b`
- **Kyun zaroori hai:** v1.2 tak sync panel ki 6 source files chup-chaap chhod deta tha
  (`views/backup/`, `views/ssl/`, `views/backup-destinations/`, `views/transfer-tool/`,
  `tests/Feature/SshTest.php`, `tests/Feature/TransferToolTest.php`). Isliye GitHub wala repo
  server jaisa nahi tha, aur repo se panel dobara banane par `/backup`, `/ssl`,
  `/backup-destinations`, `/transfer-tool` **500** dete. Poori tafseel `CHANGELOG.md` (6 Oct).
- Expected: banner `v1.3` → `completeness: …` → `==> SYNC OK ✅`. Push hone ke baad GitHub par
  `server-snapshot/STATE.md` me naya **"Snapshot completeness"** section dikhega — wahan
  `panel ki har source file … snapshot me hai — repo = server ✔` aana chahiye.
- Verify (push ke baad, kahin se bhi):
  `gh api repos/abhay751218-hue/AlphaCP/contents/server-snapshot/files/usr/local/alphacp/panel/resources/views/backup/index.blade.php`
  → 200 aana chahiye (pehle 404 tha).
- Test: `sudo bash tools/sim/sync-sim.sh` → **67/67** (7 naye completeness tests).
- ⚠️ Ye sync tool update karta hai + turant sync karta hai. **Panel code ko chhoota tak nahi** —
  websites/email/DNS safe hain. Dobara chalana safe hai.

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
