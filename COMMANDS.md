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

### panel-update 0.44.0 — Step 9: Add / Delete a DNS Zone
```bash
sudo alphacp-sync get 45d19bf7bf9dee691f341dd97d668c3e3d53948f installer/panel-update.sh /tmp/acp-panel-update-0.44.0.sh b19296a216713a265f90f1b19732146ca5fd56d7563fe3eb0797282b0252d502 && sudo bash /tmp/acp-panel-update-0.44.0.sh
```
- sha256: `b19296a216713a265f90f1b19732146ca5fd56d7563fe3eb0797282b0252d502`
- Expected: banner `updater 0.44.0` → agent **0.37.0** → `==> UPDATE COMPLETE ✅` → HTTP 200.
- WHM: Add/Delete DNS Zone (parked add + domain.remove; dns.zone). Hostile FQDN fail closed. No BIND rewrite. Main zone cannot be deleted.
- Customer cPanel does not see this tile.
- Trial/password/APP_KEY unchanged.
- Test: panel-tests **279/0**, provision-sim **68/68**, update-sim **136/136**.

## ✔️ Ho chuka (dobara chalane ki zaroorat nahi)
| Command | Kab | Result |
|---|---|---|
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
