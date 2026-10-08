# MASTER-PLAN — AlphaCP ka single source-of-truth checklist

> **Ye file kholo sabse pehle.** Agar ye chat toote ya naya chat/AI se kaam karwana ho, to
> bas itna bolo: *"AlphaCP repo kholo, `docs/MASTER-PLAN.md` padho, wahin se continue karo."*
> Repo hi source of truth hai — is file + `docs/FEATURE-AUDIT.md` + `COMMANDS.md` + `CHANGELOG.md`
> me sab kuch recorded hai. Branch: `arena/79da9999-alphacp`.

---

## 0. Kaise resume karein (naye chat / kisi bhi AI ke liye)
1. Repo: `abhay751218-hue/AlphaCP` (PRIVATE), branch `arena/79da9999-alphacp`.
2. Padho (is order me): `START-HERE.md` → `AGENTS.md` → **`docs/MASTER-PLAN.md` (ye)** →
   `docs/FEATURE-AUDIT.md` → `COMMANDS.md` → `CHANGELOG.md`.
3. **Binding rules** (`START-HERE.md` §3 / `AGENTS.md`): reply Hinglish me; har message me
   **ek hi command/step**, phir output ka wait; **bina verify kiye command mat do**
   (sandbox me reproduce → fix → `tools/sim/` + tests se prove → phir pinned command);
   commands **commit-pin + sha256** (`sudo alphacp-sync get <COMMIT> installer/<x>.sh /tmp/<x>.sh <SHA256> && sudo bash …`);
   server-mutating script ke **end me `alphacp-sync`**; **ports/URL, DB columns, naye dependency
   badalne se PEHLE poochho**; `declare(strict_types=1)` + full type-hints + docblocks;
   conventional commits.
4. Code ki source-of-truth:
   - **Panel** = `server-snapshot/files/usr/local/alphacp/panel/` (root `panel/` purana 0.3.x hai — chhoo mat).
   - **Agent** = root `agent/` (canonical, live 80-task; snapshot mirror sync se refresh hota hai).
   - Tests: agent = `php8.4 agent/tests/run-tests.php`; panel = php-wasm (`tools/sim/panel-tests.sh`);
     sims = `tools/sim/*.sh`.

---

## 1. Version inventory (7 Oct 2026 tak)
| Component | Live | Repo/template | Note |
|---|---|---|---|
| Panel app | **0.75.0** | `config/acp.php` default `0.72.0` (stale) | live `.env ACP_VERSION` se override |
| Agent (`paneld`) | **0.83.0** | `agent/src/Bootstrap.php` `0.83.0`; `config/acp.php` default `0.62.0` (stale) | |
| `alphacp-sync` | **v1.5** | `installer/alphacp-sync.sh` header `1.2` (bootstrap) | live self-upgrade hua |
| `panel-update.sh` (updater) | 0.3.0 | bundle `0.3.2` | |
| `install.sh` / `panel-install.sh` / `step2-install.sh` / `step2b-*` | — | 0.1.2 / 0.3.0 / 0.2.0 / 0.3.8 | |
| `login-fix.sh` | **v1.0 APPLIED** (7 Oct 13:04Z) | same | pin `269eb3c`/`6ca53d4d…` |
| `agent-fix.sh` | **v1.0 APPLIED** (7 Oct 14:31Z) | same | pin `321c819`/`d03f3cd6…` |
| `ftp-fix.sh` | **v1.0 APPLIED live 8 Oct 00:39 IST** (user screenshot-confirmed) | same | pin `047d974`/`8f2cdafec…` |
**Drift notes:** `config/acp.php` ke default versions (0.72/0.62) live (0.75/0.83) se peeche hain —
UI footer galat version dikhata hai; ek chhota fix chahiye (env se override theek hai par default update karo).

---

## 2. S0–S15 step map (`docs/00-requirements-freeze.md` se) + status
Persona → panel: **Owner/Admin = WHM-like (2086/87)** · **Reseller = scoped WHM** ·
**Customer = cPanel-like (2082/83)** · **Mail user = Webmail/Roundcube (2095/96)** ·
**Billing = WHM API 1** · **Sysadmin = `alphacp` CLI** · **Vendors = License Server (443)**.

| Step | Module | Status (7 Oct) |
|---|---|---|
| S1 | One-click installer + updater + `alphacp doctor` | ✅ (sync/update/doctor sims green) |
| S2 | Panel core (auth/RBAC/2FA/settings/audit) + `paneld` + task queue | ✅ (login-fix se entry-gate theek) |
| S3 | Provisioning create/suspend/unsuspend/terminate/modify | ✅ |
| S4 | Packages & limits | ✅ |
| S5 | Domains + vhost + MultiPHP + SSL + Cron | ✅ |
| S6 | File Manager + Disk + **FTP** + jailed shell + SSH keys + **Git** | ⚠️ FTP/Git **B1** (proc_open) |
| S7 | Email suite (mailboxes→deliverability, webmail) | ✅ (agent 212/0) |
| S8 | Databases + users + privileges + phpMyAdmin | ✅ **ab** (B6 MysqlServer fix deployed) |
| S9 | DNS zone/templates/ns/cluster + BIND9 | ✅ |
| S10 | Backup/restore + remote dest + cPanel import | ✅ (mysql path B6 se theek) |
| S11 | Monitoring/bandwidth/stats | ✅ **b2-fix v1.0 shipped** (agent-side `metrics.access`, pin `2e9bc49`) |
| S12 | Billing API (WHM API 1 + REST + webhooks) | ⚠️ partial — audit karna hai |
| S13 | Security suite (WAF/malware/brute-force/IP-blocker/2FA) | ⚠️ partial (2FA✅, WAF/malware audit) |
| S14 | One-click apps + WordPress Toolkit | ⚠️ **B1** (AppInstaller proc_open) |
| S15 | Reseller panel + multi-server + License | ⚠️ License base✅, hardening baaki (**B-list**) |

---

## 3. Functional fix queue (FEATURE-AUDIT se) — pehle ye
| ID | Bug | Status |
|---|---|---|
| B0 | Agent source-of-truth drift (3-task stale → downgrade risk) | ✅ `606ac97` |
| B6 | `MysqlServer.php` missing → db.*/db.restore fatal | ✅ repo `fd9aca9` + **live `agent-fix v1.0`** |
| B1 | FTP/Git/Terminal/Apps web-FPM se `Process` (proc_open disabled) → 500 | ✅ FTP **live**; ✅ Git/Terminal/Apps **live**; ✅ Firewall/Waf **live** (`935a3e3`) — **B1 POORA khatam** |
| B2 | Metrics `open_basedir` se blocked | ✅ **LIVE APPLIED 8 Oct 08:09 IST** (`2e9bc49`) — Metrics ab root-agent se |
| B3 | WebDisk sirf DB rows (WebDAV provisioning nahi) | ✅ **LIVE APPLIED 8 Oct 08:32 IST** (`830f68c`) — WebDAV provisioning + mods enabled |
| B4 | 6 panel test-debt failures | ✅ **repo-fixed** `58fb3cb` (static-aligned); suite-run CI/dev env me (pdo_sqlite + dev vendor) |
| B5 | 6 wasm-skip tests (server par record karna) | 🔜 |
**Ship channel:** chhote agent-only fix = `installer/agent-fix.sh` pattern; **agent+panel dono** =
`installer/ftp-fix.sh` pattern (multi-token builder `tools/build-<x>-fix.py` + `tools/sim/<x>-fix-sim.sh`).
Panel-only bada change = panel-update artifact.
**Gate har phase par:** `php8.4 agent/tests/run-tests.php` (212/0) + `tools/sim/update-sim.sh` (54/54) +
`tools/sim/panel-tests.sh` + `tools/sim/login-entry-sim.sh` (53/53) + `tools/sim/agent-fix-sim.sh` (17/17).

---

## 4.  cPanel-PARITY workstream (NAYA — user ka requirement)
**Goal:** design, layout, colours, icons, sections, responsiveness aur **alag-alag panels**
(WHM / cPanel / Webmail) cPanel company jaise **100% same** hon (colour thoda alag chalega, baaki same).
Reference: user-attached screenshots (`uploads/howtologinwhmlogin.png`, `howtologin*`, `cpanel_responsive2-*.png`,
`latest-Home-Hero-Image.webp`) + cPanel/WHM ki asli design language. Online feature-list verify karni hai
(docs.cpanel.net) har module ke liye — jo sandbox se na khule to in screenshots + cPanel knowledge se.

### 4.1 Abhi kya hai (gap)
- Sirf **2 layouts**: `guest.blade` + `panel.blade`. WHM vs cPanel farq sirf `ModuleCatalog::modeFor()`
  ka ek flag hai — **alag themed panels NAHI**. Webmail ek config-page hai, **alag Webmail app NAHI** (Roundcube nahi).
- Design cPanel jaisa nahi: cPanel ka icon-grid + section-grouping + right "General Information" column +
  top "Search Tools (/)" + dark-navy sidebar (WHM) — ye sab missing/basic hai.

### 4.2 Design tokens (cPanel/WHM se match karna hai)
- **cPanel (customer):** paper-white background; left dark-navy sidebar (`#1c2733`-ish) collapsible groups;
  top bar me search "Find functions quickly…"; content = **icon-grid sections** (FILES / DATABASES /
  DOMAINS / EMAIL / SECURITY / SOFTWARE / METRICS / PREFERENCES); icons circular colored badges;
  right column **General Information** (Current User, Primary Domain, Home Dir, IPs, Last Login);
  accent **orange** (`#FF6C2C` / `#FE7A00`); footer me version + logo.
- **WHM (root/reseller):** dark slate-blue sidebar (`#22303f`-ish) me searchable nav tree
  (Server Configuration, Support, Networking, Security Center, Resellers, Backup, Clusters, Packages,
  DNS Functions, Database Services, IP Functions…); home = **Favorites** cards (List Accounts, Process
  Manager, Create a New Account, DNS Zone Manager) + **Statistics** (hostname, load avg, OS) + CPU graph.
- **Webmail:** alag login (email + password), white + **orange "Webmail"** wordmark; andar Roundcube.
- **Responsive/mobile:** hamburger sidebar, stacked sections, touch icons (cPanel_responsive jaisa).

### 4.3 Feature-parity checklist (customer cPanel — har icon/page)
- **FILES:** File Manager, Images, Directory Privacy, Disk Usage, Web Disk, Backup, Backup Wizard
- **DATABASES:** phpMyAdmin, MySQL Databases, MySQL Wizard, Remote MySQL
- **DOMAINS:** Site Publisher, Addon Domains, Subdomains, Aliases(Park), Redirects, Simple Zone Editor
- **EMAIL:** Email Accounts, Forwarders, Autoresponders, Default Address, Track Delivery, Global Email
  Filters, Mailing Lists, Encryption, Address Importer, SpamAssassin, Calendars & Contacts (3×),
  Email Deliverability, Email Filters, Email Routing, Email Disk Usage, Authentication
- **SECURITY:** IP Blocker, SSL/TLS (+Status), Hotlink Protection, Leech Protect, Two-Factor Auth
- **SOFTWARE:** PHP MultiPHP/Selector, Site Software, Perl Modules, WordPress/App installer
- **METRICS:** Visitors, Errors, Bandwidth, Raw Access, Awstats/Webalizer, Resource Usage, Metrics Editor
- **ADVANCED:** Cron Jobs, Apache Handlers, MIME Types, Track DNS, SSH Access, Terminal, Indexes,
  Error Pages, Optimize Website (.htaccess)  ← *web-search se confirm (cPanel dashboard ka 9va section)*
- **PREFERENCES:** Password, Contact Info, Language, Style/Theme, User Management, Notifications
- **SIDEBAR/RIGHT:** "General Information" (Current User, Primary Domain, Home Dir, IPs, Last Login)
  + "Statistics" (disk/bandwidth meters) + top **Search bar** — ye teeno har page par visible.
> Note: sandbox se `docs.cpanel.net` block hai, par **web_search chalta hai** — cPanel ki section-list
> (Files/Databases/Domains/Email/Metrics/Security/Software/Advanced/Preferences + General Info/
> Statistics) online sources se confirm ho chuki hai (7 Oct). Har module ki detail parity baad me
> step-by-step online verify karenge.
### 4.4 WHM parity checklist (root/reseller)
Account Functions (List/Create/Suspend/Unsuspend/Terminate/Modify/Upgrade) · Packages · Resellers ·
DNS Functions (Zone Editor/Cluster/Nameservers) · SSL/TLS (Manage/Install/AutoSSL) · Service Configuration
(Apache/PHP/FTP/Mail/DB) · Server Configuration (Basic/Settings/Tweaks) · Networking Setup · Security Center
(ModSecurity/BruteForce/IP-Blocker/2FA-policy) · Backup Configuration + Wizard + Restoration · Transfers
(cPanel import) · Clusters · System (Reboot/Status/Logs/Process Manager) · MultiPHP · Mail Server Config
(Exim/Dovecot/Spam) · Monitoring (load/disk/bandwidth) · Licenses.
### 4.5 Phases (UI parity)
| Phase | Kaam |
|---|---|
| P-UI-1 | Theme engine: 3 alag themes/layouts (WHM / cPanel / Webmail) + design tokens (colours, sidebar, cards, icons) |
| P-UI-2 | **cPanel (customer)** icon-grid home + sections + right General-Info column + top search + footer |
| P-UI-3 | **WHM (root/reseller)** sidebar nav-tree + Favorites + Statistics home |
| P-UI-4 | **Webmail** alag app (Roundcube SSO) + alag login (2095/96 opt-in) |
| P-UI-5 | Responsive/mobile (hamburger + stacked) + icons set + white-label/branding hooks |
| P-UI-6 | Har module-page ko cPanel ke page-layout se match (forms/tables/buttons consistency) |

---

## 5. Decisions pending (user se)
1. Entry separation **live** karni hai? (`login-fix --enable-ports`, nginx 2083/87/96) — opt-in.
2. License hardening scope: per-key limits, offline grace, revoke-propagation, white-label, docs.
3. ~~Brand/product name~~ → **DECIDED (7 Oct): `AlphaCP` hi rahega** (license + UI me wahi naam).
4. ~~Priority order~~ → **DECIDED (7 Oct): sab kuch cPanel-jaisa karna hai** — logo/naam/trademark
   chhod kar baaki 100% cPanel-company jaisa system; agent apni marzi se order banaye aur
   **verified commands deti rahe** (functional fixes + P-UI parity saath-saath aage badhenge).
5. WebDisk: implement (WebDAV) ya UI se hide.

---

## 6. Pinned-command history (superseded mat chalao)
| Script | Status | Pin (commit / sha256) |
|---|---|---|
| `login-fix.sh` v1.0 | APPLIED 7 Oct 13:04Z | `269eb3c22ae49dd5bba76a5ce2750b594f65ff2c` / `6ca53d4d163f7dc736963f5d8cb18084c3b44c6185a8891a8b61981a8960e6cf` |
| `agent-fix.sh` v1.0 | APPLIED 7 Oct 14:31Z | `321c81929df94e6d2b05a29b912eaa31fba4209d` / `d03f3cd69620d21e1f0c4aef9f84b85fa1e7ec115899526f69c805bcb567e9a1` |
| `ftp-fix.sh` v1.0 | **APPLIED 8 Oct 00:39 IST** | `047d974540aceff0fa686a8b3e3fc65a104f0f21` / `8f2cdafec0f2fea7a9065109364ca60438ee77bfa72a428189ffcff4cb780809` |
| `b1-fix.sh` v1.0 | **APPLIED 8 Oct 01:10 IST** | `ec50182305cdd324a93db159e738ec3881e74ebc` / `046bfbbce36f92c1d5af59431e95b187b14097bf749f2446c6d725f0fd21bfca` |
| `sec-fix.sh` v1.0 | **APPLIED 8 Oct 07:56 IST** | `935a3e392436ed2c04b393219cd50208666f23eb` / `f9f85ccd456dcfc96f542e432dd15e293bb4b898594d7ecfaadb2a82b564bee6` |
| `b2-fix.sh` v1.0 | **APPLIED 8 Oct 08:09 IST** | `2e9bc49363893665c4d1b45be380904306317e1a` / `11a24ab51244cc43ce8f342dbd9625a6f580edca0fca219086b4d191171df594` |
| `b3-fix.sh` v1.0 | **APPLIED 8 Oct 08:32 IST** | `830f68c7fe1e1bb4cc30507fdc459cc9c98b5106` / `7794106c241ecb90d6ad0b10ca3eecb618fe32d5912e51c6b099da0dd0208aed` |
| `suite-enable.sh` v1.1 | pdo_sqlite ✅ live (8.4.26); suite-run v1.1 se dobara | `bc7b95f08316bbcae96754a755ca5b522c35a15b` / `02517c05bb694c5cb7b6a508cd7c3d794e243f1eac9059a9319c8dcc11487d75` |
| `tests-sync.sh` v1.0 | SHIPPED — apply pending | `3d649d8f8f225db2bd7ac46b45b302e0ad8bc3b7` / `f9b298f580d87a8ad5f662059cda1e2ecfe7baedb929d84e07911a3c56706478` |
| `ui1-fix.sh` v1.0 | **APPLIED 8 Oct 09:01 IST** | `4cd18899a942ef7b92e1d9256938ad530bc101fb` / `0085da7ea7e0675c48b32c316ec222ea90573160c09c0a28a01ea552da5a9b0e` |
| sync tool v1.2 (bootstrap) | — | `4b4573f96f55927ee1fbf526037785dcdb82aea1` / `c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852` |
| doctor v1.7 | — | `da3539029d1010f33fd550e7b4d016785c932103` / `e2915e0204df79ec41540d0ee68ef8a1c3cb3f261a39dc38651471a5115f5e88` |

## 7. Next immediate step
**B1 cluster POORA LIVE (FTP `047d974` · Git/Term/Apps `ec50182` · Security `935a3e3`, teeno
user-confirmed).** Ab **B2 Metrics shipped** — apply karne wali pinned command (COMMANDS.md 🔴 section):

```bash
sudo alphacp-sync get 2e9bc49363893665c4d1b45be380904306317e1a installer/b2-fix.sh /tmp/b2-fix-v1.0.sh 11a24ab51244cc43ce8f342dbd9625a6f580edca0fca219086b4d191171df594 && sudo bash /tmp/b2-fix-v1.0.sh
```

Apply ke baad user panel → **Metrics** kholo (pehle khali/error — open_basedir). Live par
`pdo_sqlite` nahi → suite gate `skip` note dega, baaki gates enforced (expected). Uske baad queue:
1. **B1 baaki:** Git · Terminal · Apps (same `Process` → agent-task pattern; `git.*`, `terminal.run`,
   `apps.install`) — FTP wala hi blueprint.
2. **B2 Metrics** → ✅ shipped (`b2-fix v1.0`, COMMANDS.md 🔴 section) — apply confirm hote hi B3.
3. **B4 test-debt** ✅ repo-fixed `58fb3cb` + **B5 wasm-skips** ✅ record (FEATURE-AUDIT env-skip matrix).
4. **P-UI-1…6 cPanel parity:** alag WHM / cPanel / Webmail experiences, AlphaCP branding,
   cPanel-jaise sections (Files/Databases/Domains/Email/Metrics/Security/Software/Advanced/
   Preferences + General Information/Statistics + top search) — §4 ke design tokens ke saath.

---

## 8. Sandbox / env gotchas (koi bhi AI resume kare to ye zaroor padhe)
- **php-wasm `PHP` env ko VERSION maanta hai.** Agar `PHP=/path/to/php` export ho to har child php
  call `Error: Unsupported PHP version /path/...` par fail hota hai (output khaali, exit non-zero —
  bahut confusing). Isliye: installer scripts `PHP_BIN` use karte hain + `unset PHP`; sims `PHPBIN`.
- Sandbox **reprovision** ho sakta hai: native `php8.4`, `/home/user/phpw` (php-wasm), `/tmp/live`
  (Laravel lab) sab gayab ho jaate hain, aur local `.git` base commit par reset ho sakta hai jabki
  working tree files reh jaati hain. Recovery: `git fetch origin arena/79da9999-alphacp` →
  `git reset --hard <tip>` (push kiya hua kaam kabhi lost nahi hota) → php-wasm dobara:
  `mkdir -p /home/user/phpw && cd /home/user/phpw && npm i @php-wasm/cli` → wrapper
  `/home/user/bin/php` = `exec node /home/user/phpw/node_modules/@php-wasm/cli/php-wasm.js "$@"`.
- **`packagist.org` blocked** hai (http 000) → Laravel `vendor/` rebuild nahi ho sakta → panel
  PHPUnit sims (`panel-tests.sh`, `login-entry-sim.sh`, `update-sim.sh`, `sync-sim.sh`,
  `doctor-sim.sh`) is sandbox me **nahi** chal sakte (root/`useradd`/systemd bhi missing).
  Panel-side verification ke liye: static lint + agent sims + (agar lab mile) php-wasm PHPUnit.
  `bash tools/sim/agent-fix-sim.sh` (17/17) aur `bash tools/sim/ftp-fix-sim.sh` (40/40) chalte hain.
- **`docs.cpanel.net` curl = blocked**, par **`web_search` tool chalta hai** — cPanel parity ki
  online verification web_search se karo (§4 me confirmed section-list already hai).
- Agent suite: `php agent/tests/run-tests.php` → `passed: 215   failed: 0` (php-wasm par ~3s).
