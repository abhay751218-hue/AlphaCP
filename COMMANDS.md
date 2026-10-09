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

### theme-fix v1.1 — cPanel LIGHT vs WHM DARK (alag looks) + hamburger menu fix (09 Oct 2026)
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
