> **7 Oct 2026 safety notice:** `docs/MASTER-PLAN.md` is the continuation entry point. Editable panel source 0.3.2 predates captured code 0.75.0. Historical updater commands below are not approved for the newer captured server; reconcile/test first. No new server command is issued by this audit.

## Current continuation — read-only diagnostics (7 Oct)

Owner screenshot confirmed panel MANIFEST 0.75.0. Owner reports working single-entry login after an earlier HTTP 500; its cause remains unverified. Keep the working login unchanged.

Next: inspect actual TCP listeners (no writes, reloads or new ports):
```bash
sudo ss -ltn '( sport = :8090 or sport = :2087 or sport = :2083 or sport = :2096 )'
```
Sandbox syntax/exit test: exit 0; no matching sandbox listeners. This does not verify live server reachability, TLS, firewall or role access. Await owner output before any entry configuration change.

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

### alphacp-sync v1.2 — private repo support (`get` mode). Repo PRIVATE karne se PEHLE chalao.
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

## Stage 1 — TLS manager entry 2087 (7 Oct; owner acceptance pending)
Only adds listener; preserves 8090 and panel code. No firewall/UI/auth/2083/2096 changes.
Tests: 5 stubbed rollout scenarios; Bash syntax; GitHub bytes compared with tested source.
```bash
sudo alphacp-sync get 05eccbd42b1a7deed719157e123d842bf19a5daa installer/manager-entry.sh /tmp/acp-manager-entry-0.1.0.sh c28c77be6cc72087c5b07fe33a2f3b69edb9ac20adc83524ab55d6d17a685e08 && sudo bash /tmp/acp-manager-entry-0.1.0.sh
```
Expected banner manager-entry v0.1.0 then MANAGER ENTRY READY. Validation/reload/new-port health
failures restore old config; if rollback reload fails, stop and report output. Full separation not claimed.
