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

### 🔴 mail-fix v1.1 — ABHI CHALAO (live ke 3 asli suite failures ka fix)
```bash
sudo alphacp-sync get f2ce8cd163e9725e0d7dcac0247f9596a58c0b8f installer/mail-fix.sh /tmp/mail-fix-v1.1.sh c3bd9568377e7dc9cdc77c6909ea7eeff5f441d6e45c8fbd54780818de82e442 && sudo bash /tmp/mail-fix-v1.1.sh
```
- commit `f2ce8cd163e9725e0d7dcac0247f9596a58c0b8f`, sha256 `c3bd9568377e7dc9cdc77c6909ea7eeff5f441d6e45c8fbd54780818de82e442`.
- **Kya karta hai:** v1.0 live par `passed: 219 failed: 3` par block hua. Teeno asli
  failures: (1+2) `installed()` asli FS par `is_executable()` dekhta tha — live par
  exim4/dovecot/bind9 hamare hi installers se lage hain, isliye "binaries absent"
  branch kabhi sach nahi hota tha → ab env-override na ho to injected executor probe
  (+ tests me `FakeCommandExecutor->binsAbsent`); (3) `repairMaildirs` uid<=0 par skip
  karta tha → root-owned world-writable Maildir parents kabhi 0700 nahi hote → ab
  chown sirf valid uid par, mode tighten HAMESHA. 4 agent files byte-for-byte
  (MailServer, BindServer, 2 tests) + suite gate (passed>=222 failed=0).
- **Expected output:** `backup: …/releases/mailfix-<ts>` → `lint clean (4)` →
  `paneld active` → `suite: passed: 222   failed: 0` → `agent suite GREEN` → sync →
  `FINAL VERDICT … mail-fix v1.1 APPLY ho gaya`.
- **Fail par:** suite-fail par die + poora slog `${LOG%-suite-tail}` (root-only 600 —
  `sudo grep -a -A1 "  FAIL " <file>`); gate-fail par auto-rollback. **Wapas:** `--rollback`.
- **Iske baad:** live suite authoritative = 222/0 → P-UI-2 (WHM sidebar) shuru.
- Test: `bash tools/sim/mail-fix-sim.sh` → **36/36** (PRE=v1.0-era, root-run proof).

### ❌ mail-fix v1.0 — RAN 8 Oct 19:57 IST, gate blocked (219/3) → SUPERSEDED by v1.1
```bash
sudo alphacp-sync get 3195ff6fdd69de57537c43b1b96554e3ba239aa9 installer/mail-fix.sh /tmp/mail-fix-v1.0.sh 487f0f163bfbac46e8e4dffc0dfd689e8b3c2a2359aeef8e138d42ed3e99e703 && sudo bash /tmp/mail-fix-v1.0.sh
```
- Natija: sha ✅, backup `mailfix-20261008142436`, lint 3 ✅, paneld active ✅, suite
  219/3 → gate block (suite-fail path par rollback nahi hota — live par v1.0 files
  lagi rahin). 3 FAIL names slog se nikale: dns.bind status / mail.server status-list
  (installed-detection) + root-owned Maildir repair → teeno fix v1.1 me. **Dobara mat chalao.**

### ❌ tests-sync v1.0 — RAN 8 Oct 19:36 IST, gate blocked (219/3) → SUPERSEDED by mail-fix v1.0
```bash
sudo alphacp-sync get 3d649d8f8f225db2bd7ac46b45b302e0ad8bc3b7 installer/tests-sync.sh /tmp/tests-sync-v1.0.sh f9b298f580d87a8ad5f662059cda1e2ecfe7baedb929d84e07911a3c56706478 && sudo bash /tmp/tests-sync-v1.0.sh
```

- **Natija:** sha verified, backup `releases/testsync-20261008140438`, lint clean (2),
  suite `passed: 219 failed: 3` → gate ne sahi block kiya, auto-rollback ho gaya
  (live tests wapas stale era). Slog: `/usr/local/alphacp/logs/tests-sync-20261008140438-suite-tail.txt`.
- **Seekh:** 3 failures me se 2 mail-filter tests thi (root par `ensureFilterEtcSearchable`
  bug — agent CODE fix chahiye tha, sirf tests sync kaafi nahi). **mail-fix v1.0 me test
  files + MailServer fix dono hain — tests-sync ko dobara MAT chalao.**

### ✅ ui1-fix v1.0 — APPLIED 8 Oct 09:01 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get 4cd18899a942ef7b92e1d9256938ad530bc101fb installer/ui1-fix.sh /tmp/ui1-fix-v1.0.sh 0085da7ea7e0675c48b32c316ec222ea90573160c09c0a28a01ea552da5a9b0e && sudo bash /tmp/ui1-fix-v1.0.sh
```
- commit `4cd18899a942ef7b92e1d9256938ad530bc101fb`, sha256 `0085da7ea7e0675c48b32c316ec222ea90573160c09c0a28a01ea552da5a9b0e`.
- **Kya karta hai (P-UI-1):** panel ka look cPanel Paper-Lantern jaisa — paper-white cards,
  navy top bar `#1c2733` + orange accent `#FF6C2C` (AlphaCP branding, koi cPanel mark nahi);
  customer dashboard par cPanel-jaisa layout: left tool-grid + right sidebar
  (**General Information**: user/domain/home/PHP/package/status/theme/version ·
  **Statistics**: disk meter, bandwidth, domains) + top bar me **search** jo tool tiles
  live filter karti hai. 4 panel files byte-for-byte; koi agent/DB change nahi.
- **Expected output:** `backup: …/releases/ui1fix-<ts>` → `panel files likhi (4) — theme +
  layout asserts pass` → optimize:clear → fpm active → `/login 200` → sync →
  `FINAL VERDICT … ui1-fix v1.0 APPLY ho gaya`.
- **Fail par:** auto-rollback. **Dekhna ho:** `--diagnose` · **wapas:** `--rollback`.
- **Verify:** browser me **hard-refresh (Ctrl+Shift+R)** → naya light theme + dashboard
  sidebar + search box (type karo "ftp" → sirf FTP tiles bachein).
- Test: `bash tools/sim/ui1-fix-sim.sh` → **29/29**.

### ✅ suite-enable v1.1 — RAN 8 Oct 11:24 IST (pdo_sqlite ✅ 8.4.26; gate ne stale tests pakde — mission done)
```bash
sudo alphacp-sync get bc7b95f08316bbcae96754a755ca5b522c35a15b installer/suite-enable.sh /tmp/suite-enable-v1.1.sh 02517c05bb694c5cb7b6a508cd7c3d794e243f1eac9059a9319c8dcc11487d75 && sudo bash /tmp/suite-enable-v1.1.sh
```
- commit `bc7b95f08316bbcae96754a755ca5b522c35a15b`, sha256 `02517c05bb694c5cb7b6a508cd7c3d794e243f1eac9059a9319c8dcc11487d75`. **v1.1:** v1.0 suite header ke baad silent exit ho jata tha (pipefail trap); ab suite output log me jata hai, fatal par tail-25 + clear message.
- **Kya karta hai:** live PHP me `pdo_sqlite` nahi tha → agent suite ke TaskLogger tests
  live par nahi chal sakte the (installers skip-note dete the). Ab apt se
  `php8.4-sqlite3` lagta hai, verify hota hai, phir **poora 222-test suite LIVE** chalta
  hai (gate: passed>=222 failed=0). Koi panel/agent file nahi badalti. Idempotent
  (pdo_sqlite pehle se ho to install skip).
- **Expected output:** `pdo_sqlite pehle se loaded` YA `php8.4-sqlite3 install hua` →
  `pdo_sqlite loaded (8.4.x)` → `suite: passed: 222   failed: 0` →
  `agent suite GREEN live par` → sync → `FINAL VERDICT … suite-enable v1.0 APPLY ho gaya`.
- **Fail par:** exit 1 (koi file change nahi hoti). **Wapas:** `--rollback` (apt remove).
- **Iske baad:** agle installers suite-skip note nahi denge — suite gate live par enforce.
- Test: `bash tools/sim/suite-enable-sim.sh` → **8/8**.

### ✅ b3-fix v1.0 — APPLIED 8 Oct 08:32 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get 830f68c7fe1e1bb4cc30507fdc459cc9c98b5106 installer/b3-fix.sh /tmp/b3-fix-v1.0.sh 7794106c241ecb90d6ad0b10ca3eecb618fe32d5912e51c6b099da0dd0208aed && sudo bash /tmp/b3-fix-v1.0.sh
```
- commit `830f68c7fe1e1bb4cc30507fdc459cc9c98b5106`, sha256 `7794106c241ecb90d6ad0b10ca3eecb618fe32d5912e51c6b099da0dd0208aed`.
- **Kya karta hai:** Web Disk page sirf DB row likhta tha — koi WebDAV exist hi nahi
  karta tha (audit B3). Ab root agent provision karta hai: `<home>/etc/webdisk.digest`
  (Apache Digest auth, `login:realm:md5-A1`; plaintext kahin nahi), `<home>/etc/webdisk.conf`
  (managed snippet: `Alias /webdisk <home>` + `DAV on` + Digest; write methods sirf
  `rw` logins ke liye — `ro` accounts par denied), vhost me `IncludeOptional` + apache
  reload. Registry 96 → **99 types** (`webdisk.list/create/delete`). Panel controller ab
  agent-truth (`Paneld::run`); DB row sirf ownership cache; view me password field +
  connect-info card. Installer apache modules `dav/dav_fs/auth_digest` enable karta hai
  (missing hone par a2enmod; warn-only).
- **Expected output:** `backup: …/releases/b3fix-<ts>` → `agent files likhi + lint clean (7)` →
  `SMOKE OK` → suite gate (live par `pdo_sqlite nahi — suite skip` note) → `paneld active
  (99-type registry)` → `a2enmod dav dav_fs auth_digest` (ya "pehle se enabled") →
  `panel files likhi + lint clean (2)` → fpm active → `/login 200` → sync →
  `FINAL VERDICT … b3-fix v1.0 APPLY ho gaya`.
- **Fail par:** auto-rollback. **Dekhna ho:** `--diagnose` · **wapas:** `--rollback`.
- **Verify:** panel → **Web Disk** → account banao (login+password+rw/ro) → WebDAV client
  (ya `curl --digest -u login:pass https://<host>/webdisk/`) se files dikhen.
  `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1` → `passed: 222   failed: 0`
  (agar pdo_sqlite ho).
- Test: `bash tools/sim/b3-fix-sim.sh` → **41/41**.

### ✅ b2-fix v1.0 — APPLIED 8 Oct 08:09 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get 2e9bc49363893665c4d1b45be380904306317e1a installer/b2-fix.sh /tmp/b2-fix-v1.0.sh 11a24ab51244cc43ce8f342dbd9625a6f580edca0fca219086b4d191171df594 && sudo bash /tmp/b2-fix-v1.0.sh
```
- commit `2e9bc49363893665c4d1b45be380904306317e1a`, sha256 `11a24ab51244cc43ce8f342dbd9625a6f580edca0fca219086b4d191171df594`.
- **Kya karta hai:** Metrics section live par khali/error dikhta tha — panel ka parser
  web-FPM se `/var/log/apache2/{user}-access.log` padhta tha jo `open_basedir` me nahi
  (audit B2). Ab parsing **root agent** par: naya task `metrics.access` (readonly, 60s;
  account guard + PathGuard-validated optional `log_path`, warna distro candidates;
  >8MB file par last-8MB tail; 2M-line cap) — bytes/visitors/requests/errors/top-10.
  Registry 95 → **96 types**. Panel `MetricsController` ab `Paneld::run('metrics.access',…)`;
  `Support/Metrics` sirf display helper (`human()`). Missing log = zero-stats, error nahi.
- **Expected output:** `backup: …/releases/b2fix-<ts>` → `agent files likhi + lint clean (4)` →
  `SMOKE OK` → suite gate (live par `pdo_sqlite` nahi → `suite skip` note, static smoke
  enforced) → `registry me metrics.access PRESENT` → `panel files likhi + lint clean (2)` →
  `php8.4-fpm active` → `panel /login HTTP 200` → `sync complete` →
  `FINAL VERDICT … b2-fix v1.0 APPLY ho gaya`.
- **Fail par:** auto-rollback. **Dekhna ho:** `--diagnose` · **wapas:** `--rollback`.
- **Verify:** panel → **Metrics** (Bandwidth/Visitors cards) kholo — numbers aayen
  (naya account = zeros bhi valid). `sudo /usr/local/alphacp/agent/bin/alphacpd --diagnose 2>/dev/null | head`
  ya `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1` → `passed: 221   failed: 0`
  (agar pdo_sqlite ho).
- Test: `bash tools/sim/b2-fix-sim.sh` → **40/40**.

### ✅ sec-fix v1.0 — APPLIED 8 Oct 07:56 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get 935a3e392436ed2c04b393219cd50208666f23eb installer/sec-fix.sh /tmp/sec-fix-v1.0.sh f9f85ccd456dcfc96f542e432dd15e293bb4b898594d7ecfaadb2a82b564bee6 && sudo bash /tmp/sec-fix-v1.0.sh
```
- commit `935a3e392436ed2c04b393219cd50208666f23eb`, sha256 `f9f85ccd456dcfc96f542e432dd15e293bb4b898594d7ecfaadb2a82b564bee6`.
- **Kya karta hai:** Security section ke actions live par **500** dete the — `Support/Firewall`
  (ufw deny/delete) aur `Support/Waf` (a2query/a2enmod/a2dismod/apache restart/clamscan)
  web-FPM se shell chalate the (proc_open disabled — audit B1-ext). Ab sab **root agent**:
  `security.ipBlock` / `security.ipUnblock` (ufw, IP filter_var-validate), `waf.status` /
  `waf.enable` / `waf.disable` (ModSecurity + apache restart), `security.scan` (clamscan;
  exit 1 = "infected" result, failure nahi). Registry 89 → **95 types**. Panel Support files
  ab sirf Paneld enqueue/run — **poore panel app/ me kahin Process:: nahi bacha**.
- **Expected output:** `backup: …/releases/secfix-<ts>` → `agent files likhi + lint clean (9)` →
  `SMOKE OK` → `agent suite GREEN (passed=220 failed=0)` → `paneld active` →
  `panel files likhi + lint clean (2) — koi Process:: nahi` → `php8.4-fpm active` →
  `panel /login HTTP 200` → `sync complete` → `FINAL VERDICT … sec-fix v1.0 APPLY ho gaya`.
- **Fail par:** auto-rollback. **Dekhna ho:** `--diagnose` · **wapas:** `--rollback`.
- **Verify:** panel → **IP Blocker** se ek IP block/unblock karo · **Security Tools** me
  ModSecurity toggle + public_html scan chalao (pehle 500 aata tha).
  `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1` → `passed: 220   failed: 0`.
- Test: `bash tools/sim/sec-fix-sim.sh` → **40/40**.

### ✅ b1-fix v1.0 — APPLIED 8 Oct 01:10 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get ec50182305cdd324a93db159e738ec3881e74ebc installer/b1-fix.sh /tmp/b1-fix-v1.0.sh 046bfbbce36f92c1d5af59431e95b187b14097bf749f2446c6d725f0fd21bfca && sudo bash /tmp/b1-fix-v1.0.sh
```
- commit `ec50182305cdd324a93db159e738ec3881e74ebc`, sha256 `046bfbbce36f92c1d5af59431e95b187b14097bf749f2446c6d725f0fd21bfca`.
- **Kya karta hai:** Git Version Control / Terminal / Site Software (WordPress) pages live par
  **500** dete the (controllers web-FPM se shell chalate the; proc_open disabled — audit B1).
  Ab saara kaam **root agent** karta hai: git.list/clone/pull/status (repos home ke andar
  `<home>/git/<dir>`, `git clone --`, pull `--ff-only`), terminal.run (read-only whitelist,
  agent par dobara validate), apps.install (public_html + `<account>_wp` DB/user/grant +
  WordPress extract + wp-config + chown). Registry 83 → **89 types**. Panel sirf queue/dikhawa
  (`Paneld::run` se sync list/status; baaki enqueue). Agent 10 + panel 4 files byte-for-byte.
- **Expected output:** `backup: …/releases/b1fix-<ts>` → `agent files likhi + lint clean (10)` →
  `SMOKE OK` → `agent suite GREEN (passed=218 failed=0)` → `paneld active` →
  `panel files likhi + lint clean (4) — koi Process:: nahi` → `php8.4-fpm active` →
  `panel /login HTTP 200` → `sync complete` → `FINAL VERDICT … b1-fix v1.0 APPLY ho gaya`.
- **Fail par:** apne aap **rollback**. **Dekhna ho:** `--diagnose` · **wapas:** `--rollback`.
- **Verify:** panel → **Git Version Control** (repo clone), **Terminal** (`ls -la`, `df`),
  **Site Software** (WordPress queue). Terminal se:
  `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1` → `passed: 218   failed: 0`.
- Test: `bash tools/sim/b1-fix-sim.sh` → **40/40**.


### ✅ ftp-fix v1.0 — APPLIED 8 Oct 00:39 IST, user-confirmed (ab MAT chalao; history/rollback ke liye)
```bash
sudo alphacp-sync get 047d974540aceff0fa686a8b3e3fc65a104f0f21 installer/ftp-fix.sh /tmp/ftp-fix-v1.0.sh 8f2cdafec0f2fea7a9065109364ca60438ee77bfa72a428189ffcff4cb780809 && sudo bash /tmp/ftp-fix-v1.0.sh
```
- commit `047d974540aceff0fa686a8b3e3fc65a104f0f21`, sha256 `8f2cdafec0f2fea7a9065109364ca60438ee77bfa72a428189ffcff4cb780809`.
- **Kya karta hai:** live par **FTP Accounts** kholte hi / naya account banate hi **HTTP 500**
  aata tha — panel web-FPM se `Process::run(['pure-pw', …])` chalata tha aur pool me `proc_open`
  disabled hai (audit B1). cPanel ki tarah ab shell kaam **root agent** karta hai aur panel sirf
  **queue** karta hai. Script agent ki 7 files (`src/Ftp.php`, `src/Tasks/FtpTask|FtpAdd|FtpPasswd|
  FtpDel.php`, `src/CommandRunner.php` = pure-pw allowlist, `config/tasks.php` = 83 types) +
  panel ki 2 files (`app/Support/Ftp.php`, `app/Http/Controllers/FtpController.php`)
  **byte-for-byte** deploy karti hai. Password hamesha **stdin** par jaata hai (argv me nahi),
  chroot home account ke andar hi ban sakta hai (PathGuard), virtual login `<account>_<name>`
  hi allow hai, uid/gid `getent` se (>=1000). **Koi interactive prompt nahi.**
- **Expected output:** `backup: /usr/local/alphacp/releases/ftpfix-<ts>` →
  `agent files likhi + lint clean (7)` → `SMOKE OK` → `agent static smoke PASS` →
  `suite: passed: 215   failed: 0` → `agent suite GREEN (passed=215 failed=0)` → `paneld active` →
  `panel files likhi + lint clean (2) — koi Process:: nahi` → `php8.4-fpm active (opcache clear)` →
  `panel /login HTTP 200` → `sync complete` → `FINAL VERDICT … ftp-fix v1.0 APPLY ho gaya`.
- **Fail par:** script **apne aap rollback** (backup se purani files + paneld/fpm restart) —
  server toota hua nahi chhoda jaata.
- **Sirf dekhna ho, kuch badle nahi:** `sudo bash /tmp/ftp-fix-v1.0.sh --diagnose`
  (dikhata hai: agent `src/Ftp.php` PRESENT/MISSING · registry me `ftp.add` (1) · `ftp` total (3) ·
  allowlist me `pure-pw` (>0) · panel `Support/Ftp` me `Process::` (**0** = theek) ·
  `FtpController` me `AccountProvisioner::enqueue` (**3** = theek))
- **Wapas jaana ho:** `sudo bash /tmp/ftp-fix-v1.0.sh --rollback`
  (backup: `/usr/local/alphacp/releases/ftpfix-<ts>/`)
- **Verify (apply ke baad):** panel → **FTP Accounts** → ek account banao (pehle 500 aata tha, ab
  queue me ja kar banna chahiye). Terminal se:
  `sudo php8.4 /usr/local/alphacp/agent/tests/run-tests.php | tail -1` → `passed: 215   failed: 0`;
  `sudo pure-pw list` → tumhara `<account>_<name>` user dikhega.
- Test: `bash tools/sim/ftp-fix-sim.sh` → **40/40** (reproduce → apply → rollback → re-apply).

### ✅ agent-fix v1.0 — APPLIED 7 Oct 14:31Z, user-confirmed (ab MAT chalao; history/rollback ke liye)
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
