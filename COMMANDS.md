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

## ✅ Abhi chalani hai (NEXT STEP) — 5 Oct 2026

> **0.80.0 = mail delivery ka ASLI fix.** 0.79.0 chalane ke baad `exim -bV` hi reject ho gaya
> (`"user" or "check_local_user" must be set with allow_filter`) → setup fail → purani config
> wapas → `exim -bt` **R: nonlocal** (mail abhi bhi band hai). Is baar maine andaza nahi lagaya:
> **sandbox me asli exim 4.97 build kiya** (PCRE2 + gcc, source GitHub se) aur har hypothesis
> test kiya. Chaar asli galtiyan mili — chaaron fix. Ab **10/10 real-exim test green** hain.

### 🛠 0.80.0 — asli exim se pakdi gayi 4 galtiyan
| # | Kya toota tha | Asli wajah (exim 4.97 ne khud bataya) | Ab |
|---|---|---|---|
| **1** | 0.79.0: `exim -bV` reject → setup fail → mail `nonlocal` | `allow_filter` ke saath `user` (ya `check_local_user`) **hona hi chahiye**. 0.78.0 ke "Failed to find user }" se bachne ke liye `user` hata diya tha | Router par `user`/`group` wahi SAFE extract idiom se (0.78.0 wali `{$value}{}` nesting ke bina) |
| **2** | filter chalu tha par mail `.filtered/` ki jagah **inbox** me ja rahi thi (`=> <maildir> R=alphacp_userfilter T=address_directory`) | transport `address_directory` par `directory`/`user`/`group` set karne se filter ke `save` ka path **override** ho jata hai | Transport sirf `maildir_format + create_directory`; path aur uid/gid filter/router se milte hain |
| **3** | lookup file missing → har mail par **PANIC log** | `${lookup … lsearch{file}}` file missing par *defer-like* fail karta hai, decline nahi | `require_files = <filters file>` pehle check (static path, kabhi fail nahi) |
| **4** | `require_files` akela → `defer (-1): "" is not an absolute path` → **mail queue me atak gayi** | file maujood par is address ka filter na ho to `file = ""` ho jata hai | `require_files` **+** `condition` dono |

**Aur ek safety:** config reject hone par ab **PRISTINE (distro) template wapas nahi** aati —
`.acp-prev` (aakhri kaam karne wali AlphaCP config) wapas aati hai, warna hi `.acp-orig`.
0.79.0 me pristine wapas aane se exim me alphacp routers hi nahi bache the.

**Version display fix (aapka sawal):** panel ab **`0.80.0`** dikhayega — `ACP_AGENT_VERSION`
ab release ke sath chalta hai (0.65.0 → 0.80.0) aur updater `.env` me `ACP_VERSION` = release
version likhta hai. (Panel *code bundle* 0.74.0 hi hai — wo tabhi badalta hai jab panel ka code
badle; UI me ab release number dikhta hai.)

### 1) panel-update 0.80.0
```bash
sudo alphacp-sync get a309bf5db2557e8b2058569aefff3298a633e779 installer/panel-update.sh /tmp/acp-panel-update-0.80.0.sh 9c5f8d1c2c8205130ab49e2709a88b831736b42b5d8f12443360f88815ff8a82 && sudo bash /tmp/acp-panel-update-0.80.0.sh
```
- Updater SHA-256: `9c5f8d1c2c8205130ab49e2709a88b831736b42b5d8f12443360f88815ff8a82` · banner **`updater 0.80.0`** · `Panel bundle: 0.74.0 · agent: 0.80.0`.
- Updater `mail.server setup` chalata hai: purani template ki jagah nayi (router + transport theek),
  `exim4 -bV` → **`exim -bt` smoke test** → restart. Ab wapas pristine nahi.

### 2) Verification
```bash
sudo alphacp-sync get a261f757ecb61aac56ff1ee999e844627d361fd8 tools/verify/s7-mail-check.sh /tmp/s7-mail-check.sh 2e20f1430ded828097cc548d9fd8df409cda4389df16f50fcb4242c2b6011af9 && sudo bash /tmp/s7-mail-check.sh
```
- Verifier SHA-256: `2e20f1430ded828097cc548d9fd8df409cda4389df16f50fcb4242c2b6011af9`.
- Expected: **62 pass / 0 fail** · ant me **`ASLI MAIL DELIVERY:VERIFIED`**. Part I me:
  1. `sync ne Exim filter banaya (N mailbox ke liye)` — N ≥ 1
  2. `Exim filter file mili (lookup: /etc/exim4/alphacp-filters)`
  3. `exim -bt <addr>: Maildir tak pahunch rahi hai` (koi PANIC/defer/nonlocal nahi)
  4. `FILTER KAAM KAR GAYA (#21): 'acpfilter' wali mail …/.filtered/new me (0 se 1)` +
     `DISCARD KAAM KAR GAYA (#21)` + `mail.track (#19): ASLI exim mainlog se trace`
- Fail ho to poora dump (`filter_errors` ke saath) `ACP_HOME/verify-reports/s7-diag.txt` me —
  hourly sync se aa jata hai, main khud padh kar fix dunga.

### 3) Ya dono ek hi command me
```bash
sudo alphacp-sync get a309bf5db2557e8b2058569aefff3298a633e779 installer/panel-update.sh /tmp/acp-panel-update-0.80.0.sh 9c5f8d1c2c8205130ab49e2709a88b831736b42b5d8f12443360f88815ff8a82 && sudo bash /tmp/acp-panel-update-0.80.0.sh && sudo alphacp-sync get a261f757ecb61aac56ff1ee999e844627d361fd8 tools/verify/s7-mail-check.sh /tmp/s7-mail-check.sh 2e20f1430ded828097cc548d9fd8df409cda4389df16f50fcb4242c2b6011af9 && sudo bash /tmp/s7-mail-check.sh
```

### ✅ Pichle results
- **0.79.0: 50 pass / 8 fail** — `ASLI MAIL DELIVERY:NOT-VERIFIED` (`exim -bV` reject → purani
  config wapas → exim me alphacp routers hi nahi bache → har address `nonlocal`). Yahi 0.80.0 me fix.
- **0.78.0: 58/4** (filters ne delivery tod di: `Failed to find user "}"`) — 0.79.0/0.80.0 me fix.
- **0.77.0: 54/0** (aakhri baar delivery green thi).

## ✅ Latest deployment (5 Oct 2026; already completed)

### panel-update 0.74.3 — S9 BIND9 POORA (aapne chalaya: 38 pass / 0 fail / 1 skip)
```bash
sudo alphacp-sync get e817303bc1cf48723a74fd5c96fb4f6d6c87c0bf installer/panel-update.sh /tmp/acp-panel-update-0.74.3.sh 2802dd8072d77f28ded8f6d0f9a01f5d20939834480b6f4f7bb46f41c46ebaf4 && sudo bash /tmp/acp-panel-update-0.74.3.sh
```
- **Live result:** `dig @127.0.0.1 SOA` ✅ · `dig www A = 203.0.113.10` ✅ · `dig MX` ✅ ·
  remove ke baad `dig ab khamosh hai` ✅ · `named-checkconf` har step ke baad pass.
- Checklist me 6 rows ✅ ho gaye (35, 128, 129, 135, 137, 139) — asli BIND zones.


### panel-update 0.74.2 — naya zone `rndc reconfig` se LIVE (aapne chalaya: 37/1)
```bash
sudo alphacp-sync get 91a8b89925374d678a408a6e38ef0ddaa8d47ace installer/panel-update.sh /tmp/acp-panel-update-0.74.2.sh 8a1a1202d1e4b81a45b08506d8c651737fd6cde7f9264c8e4e074578bc08649a && sudo bash /tmp/acp-panel-update-0.74.2.sh
```
- **Live result:** `named service active: bind9`, BIND 9.18.39, `dig @127.0.0.1 SOA` ✅,
  `dig www A = 203.0.113.10` ✅, `dig MX` ✅ — **asli BIND zone chal rahi hai**.
- Ek fail bacha (remove ke baad bhi purani zone serve ho rahi thi) — fix **0.74.3** me.


### panel-update 0.74.1 — BIND tools ka sahi path (aapne chalaya: green)
```bash
sudo alphacp-sync get c26bef61aa320d3fa5841dd9f4eb3932a94dcb44 installer/panel-update.sh /tmp/acp-panel-update-0.74.1.sh 5fdefb521ef6a1e7540ed38de69fc748fccfead64d17bab428443cde018c0c0c && sudo bash /tmp/acp-panel-update-0.74.1.sh
```
- **Live result:** `bind9 present (checkconf=/usr/bin/named-checkconf checkzone=/usr/bin/named-checkzone rndc=/usr/sbin/rndc dig=/usr/bin/dig)` — path wali problem khatam.
- Verify 31/5/1 tha (marker check jhootha FAIL + `rndc reconfig` ki kami) — fix **0.74.2** me.


### panel-update 0.74.0 — S9 BIND9, asli DNS zones (aapne chalaya: green)
```bash
sudo alphacp-sync get 7c67db1eb34d9a12b103cf9d21c104cb69394981 installer/panel-update.sh /tmp/acp-panel-update-0.74.0.sh 5509b25bd8240973141758fb5461dcbd1227a3c292b9c58d6ed9ed423b112e8a && sudo bash /tmp/acp-panel-update-0.74.0.sh
```
- **Live result:** panel **0.74.0** + agent **0.65.0**, `bind9 installed`, HTTP **200**.
- Path ki wajah se verify script ko `named-checkconf` nahi mila — fix **0.74.1** me (upar dekho).


### panel-update 0.73.1 — S10 destinations ka LIVE fix (aapne chalaya: 3no green)
```bash
sudo alphacp-sync get 884054a0dfe4c19d8bbd2b134bca7af4f3c242d4 installer/panel-update.sh /tmp/acp-panel-update-0.73.1.sh 04b7fee5c9d5f1a09d9182a00868a278e7598e31edf6b7d23b425b6431534890 && sudo bash /tmp/acp-panel-update-0.73.1.sh
```
- **Live result:** 3no commands green — panel 0.73.0 + agent 0.65.0, destinations 25/0, remote-pull 0 fail.
- Fix: `/usr/bin/ssh` allowlist me, remote `.part` hamesha saaf, `use Throwable` import + poore agent ke liye lint test.


### panel-update 0.73.0 — S10 remote backup destinations (deployed 5 Oct 05:39Z)
```bash
sudo alphacp-sync get 1282907fa1ff8ec74caf7f2c071cb776a7622a5e installer/panel-update.sh /tmp/acp-panel-update-0.73.0.sh 9e28574d80f7c499772efb0fbc9968e4ad2b59a879be3423968c2febc62f761d && sudo bash /tmp/acp-panel-update-0.73.0.sh
```
- **Live result:** panel **0.73.0** + agent **0.65.0**, HTTP **200**, migrations ran
  (`backup_destinations`, `backup_destination_pushes`), scheduler cron installed.
- Live check ne 6 fail diye (sab `ssh` allowlist + `.part` cleanup se) — fix **0.73.1** me hai (upar dekho).

## ✅ Latest deployment (5 Oct 2026; already completed)

### panel-update 0.72.1 — S10 remote pull: "host key MISMATCH" fix (5 Oct)
```bash
sudo alphacp-sync get 90411a8ccc74e9aac9057e5b8ae0019c62240308 installer/panel-update.sh /tmp/acp-panel-update-0.72.1.sh c08686486aa28f61f5f42255a4217e8c64d67f50de92efe865949272c617e5d9 && sudo bash /tmp/acp-panel-update-0.72.1.sh
```
- **Kya theek hua:** asli server 3 SSH keys (ed25519 + ecdsa + rsa) deta hai aur `ssh-keyscan` unka order har
  call par badalta hai; agent sirf pehli line ka fingerprint leta tha — isliye probe aur pull alag key pin
  karte the. Ab saari keys ke fingerprint aate hain aur pin kisi bhi se match hota hai (OpenSSH jaisa).

## ✅ Latest deployment (4 Oct 2026; already completed)

### panel-update 0.71.0 — S10: cpmove MySQL restore (deployed 5 Oct 01:44Z, live-verified 02:24Z)
```bash
sudo alphacp-sync get ff8e7079f173f2ab78422de84c33ed5717bf7677 installer/panel-update.sh /tmp/acp-panel-update-0.71.0.sh b744348c82239f0bd255bee5648bdc0230da4a9b76b1c9e23f0b724c11200640 && sudo bash /tmp/acp-panel-update-0.71.0.sh
```
- **Live result:** panel **0.71.0** + agent **0.64.0**, HTTP 200; migration `2026_10_04_000055` Ran;
  `s10-mysql-restore-check.sh` → **18 pass / 0 fail** (tasks #213–#218) — cpmove archive ke
  `mysql/*.sql` dumps asli MariaDB databases me restore hue (3 rows tak verify), hostile dump aur
  galat sha256 refuse hue, aur ant me sab saaf.
- Iske pehle do live-check bugs the (dono sirf `tools/` me the, agent/panel nahi): archive `/tmp` me
  ban raha tha (allowlist ke bahar) aur account aisa chuna ja raha tha jiska Linux user hi nahi tha.

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

### ❌ 0.79.0 — MAT CHALAO (config reject -> mail nonlocal)
`… get 4ee552a3135233d9614afe234733db1fc76c6b62 installer/panel-update.sh … bb749fc072c90aad…`
— isme `allow_filter` se `user` hata diya gaya tha, jisse `exim -bV` hi reject ho gaya.
**0.80.0 chalao** (upar NEXT STEP). 0.78.0/0.79.0 ab sirf history me hain.

### ❌ 0.78.0 — MAT CHALAO (mail delivery todtata hai)
`alphacp-sync get 222a113863cd3e1426cb816f76f5e5aca693e43a installer/panel-update.sh /tmp/acp-panel-update-0.78.0.sh 53b775235f1e9531a26d26f3e06c5a48649173865b9dd3f0da2ed7955de92aa8`
— iske filters ne live server par **har address defer** kar diya (`Failed to find user "}"`).
**0.79.0 chalao** (upar NEXT STEP), wo isi ka fix hai. 0.78.0 ab sirf history me hai.

| Purani command | Kyun |
|---|---|
| `arena/01a0ea0d-alphacp/installer/panel-update.sh` (updater 0.1.0, panel 0.3.1) | chal chuka (S2C deployed); ab 0.2.1 → 0.3.2 |
| `1d61cb8…/installer/panel-update.sh` (updater 0.2.0) | kabhi diya nahi gaya; 0.2.1 use karo |
| paste.rs/G72oK (doctor v1.6) | v1.7 me cwd bug fix + security step |
| paste.rs/pnV7U, LxbJT, vbVD9, wsPmr (doctor v1.5–v1.1) | superseded |
| paste.rs/0r1Mi, VD0Px, vVdFC, EPW3b (installer v0.3.3–v0.3.6) | panel install ho chuka hai; v0.3.7 aayega |
