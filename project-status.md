# 📊 PROJECT STATUS — Custom Hosting Panel
**Last updated:** 28 Sep 2026

---

## 🖥️ Server Setup Progress

| # | Kaam | Status | Date |
|---|---|---|---|
| 1 | AWS account + $100 credits check | ✅ Done | 28 Sep |
| 2 | Region: Mumbai (ap-south-1) set | ✅ Done | 28 Sep |
| 3 | Instance `dev-srv1` create (4 GB / 2 vCPU / 80 GB, Ubuntu 24.04) | ✅ Done | 28 Sep |
| 4 | **Static IP attach** → naya permanent IP: `13.207.123.177` (StaticIp-1) | ✅ Done | 28 Sep |
| 5 | Firewall: 80 ✅ · 443 ✅ · 2082 ✅ · 2083 ✅ · 2086-2087 ✅ (sab complete) | ✅ Done | 28 Sep |
| 6 | **SSH verification ✅** — `x86_64` · RAM 3.7Gi (472Mi used) · Disk 77G (7%) · Swap 2G · Ubuntu 24.04.05 | ✅ Done | 28 Sep |
| 7 | Billing budget alert | ✅ Done | 28 Sep |
| 8 | Automatic snapshots ON (user ne confirm kar diya) | ✅ Done | 28 Sep |

> 🟢 **SERVER VERIFIED & READY** — 28 Sep 2026. Step 0 start karne ke liye tayyar!

## 📐 Project Steps Progress

| Step | Kaam | Status |
|---|---|---|
| **Step 0** | Blueprint: requirements, architecture, DB schema (55 tables), security matrix, coding standards, license system design, installer/updater design, ADRs, module blueprint | ✅ **DONE (28 Sep)** |
| Step 1 | Server base stack + one-click installer + `alphacp` CLI — Apache fix done, page live; v0.1.1 re-verify pending | 🔄 **95%** (28 Sep) |
| Step 2A | **paneld agent + task queue + CLI v0.2** — dev-srv1 par install ✅, queue test `agent.ping` success in 2ms | ✅ **DONE (28 Sep)** |
| Step 2B-1 | **Panel LIVE on dev-srv1** — https://13.207.123.177:8090 (Laravel 13 v0.3.0: login, dashboard w/ live paneld data, User Manager, RBAC, 2FA, audit) | ✅ **INSTALLED & VERIFIED on dev-srv1** (29 Sep 00:02) |
| Step 2B-2 | First-login password change + 2FA login verification + RBAC/User Manager pages | ✅ **VERIFIED** |
| 2B-UI | **Paper Lantern theme (cPanel-grade UI)** — `#FF6C2C` accent, 82-tool icon grid (9 sections), right-rail panels, live tool search, 51 SVG icons; demo: `demo/cpanel-theme-demo.html`; gate: `tools/sim/theme-check.py` 21/21 | ✅ **DONE (09 Oct)** |
| 2B-WHM | **WHM admin + Reseller panels (sabhi panel cPanel company-grade)** — dark-sidebar WHM shell, 49-item admin menu + 13-item reseller menu (parity rows ke saath), `/admin` + `/reseller` routes, Server information + Accounts + Services on WHM home; client↔WHM↔reseller cross-links; demo me WHM+reseller mockups; gate: `theme-check.py` 42/42 | ✅ **DONE (09 Oct)** |
| THEME-LIVE | **theme-fix v1.0→v1.2 live deploy** — CSP inline-JS block root-cause fix (external panel.js), cPanel LIGHT vs WHM DARK, product-wise 36 icons, hamburger/search/favorites ab chalte hain; 7 files, backup+auto-rollback, live verify 3×200 | ✅ **DONE (09 Oct)** |
| DEPTH-AUDIT | **Feature-depth audit** — andar ke options ka cPanel-parity audit (`docs/10-feature-depth-audit.md`): breadth ~100 tools ✅, depth ~30-40% 🟡; Waves D1–D5 process defined | ✅ **DONE (09 Oct)** |
| D1-EMAIL | Email Accounts + Forwarders depth parity (quota/password edit, disk usage, Connect Devices, pw generator/meter, search) | ✅ installer ready, sims green — deploy row COMMANDS.md me |
| Step 2B-3 | Real TLS (Let's Encrypt) + service control buttons | ⏳ |
| Step 2C | Offline-first license client + 15-day trial + optional license-server activation | 🟡 **CLIENT DEPLOYED** — local trial verified; license-server API pending |
| Step 3 | Provisioning engine (account create/suspend/terminate) | ⏳ |
| Step 4 | Packages & limits manager | ⏳ |
| Step 5 | Domains, vHost, MultiPHP, SSL, Cron | ⏳ |
| Step 6 | File Manager + FTP + Git + SSH | ⏳ |
| Step 7 | Email suite (Exim/Dovecot/SpamAssassin/ClamAV) | ⏳ |
| Step 8 | Databases (MySQL management) | ⏳ |
| Step 9 | DNS management + nameservers | ⏳ |
| Step 10 | Backup / Restore / Migration | ⏳ |
| Step 11 | Monitoring, stats, resource limits | ⏳ |
| Step 12 | 💳 Billing API layer (WHM API 1 + native REST) | ⏳ |
| Step 13 | Security suite + WAF | ⏳ |
| Step 14 | One-click app installer + WordPress toolkit | ⏳ |
| Step 15 | Reseller + multi-server + launch | ⏳ |

## 🔑 Server Info (Reference)
| Item | Value |
|---|---|
| Provider | AWS Lightsail · Mumbai (ap-south-1a) |
| Instance | `dev-srv1` · Ubuntu 24.04 · 4 GB / 2 vCPU / 80 GB |
| **Public IPv4 (FINAL/STATIC)** | **`13.207.123.177`** ✅ (permanent — kabhi nahi badlega) |
| Private IPv4 | 172.26.4.65 |
| Public IPv6 | 2406:da1a:1e50:4c00:1ea0:3e3c:f40a:da4c |
| Static IP name | `StaticIp-1` |
| SSH user | `ubuntu` (browser terminal ya default key se) |
| Firewall (IPv4) | 22 ✅ · 80 ✅ · 443 ✅ · 2082 ✅ · **2083 ⏳** · 2086-2087 ✅ |
| Monthly cost | ~$24 (credits se covered, ~4 mahine) |
| Old temp IP | 3.109.132.244 (released — static attach hone pe replace ho gaya, normal hai) |

## 🗂️ Project Files (Workspace)

### Blueprint / Design (Step 0 output)
| File | Kya hai |
|---|---|
| `AI_CONTEXT.md` | ⭐ **Sabse pehle ye** — kisi bhi AI/dev ke liye pura context (rules, map, status) |
| `AGENTS.md` | AI assistants ke liye hard rules |
| `docs/00-requirements-freeze.md` | v1 scope lock (kya banega/kya nahi) |
| `docs/01-architecture.md` | System architecture + agent design + data flows |
| `docs/02-database-schema.sql` | **55 tables** ka complete DB design (step-tagged) |
| `docs/03-security-matrix.md` | Roles, permissions matrix, task safety classes |
| `docs/04-coding-standards.md` | Code rules (PHP/TS) + AI collaboration rules |
| `docs/05-license-system.md` | 💰 Commercial license system + license server design |
| `docs/06-installer-updater.md` | One-click install + one-click upgrade + rollback |
| `docs/07-decision-log.md` | 10 ADRs — "ye kyun aise kiya" |
| `docs/08-module-blueprint.md` | Har module ka fixed shape (AI-friendly) |
| `ROADMAP.md` | 16 steps + status |
| `CHANGELOG.md` | Version history |
| `panel/` `agent/` `installer/` `license-server/` | Repo skeletons (READMEs with rules) |

### Planning docs (Step 0 se pehle ke)
| File | Kya hai |
|---|---|
| `cpanel-jaisa-custom-panel-master-plan.md` | Master plan — 16 steps, features, timeline |
| `kaam-ka-batwara-aur-server-playbook.md` | Kaun kya karega + dono server scenarios |
| `vps-server-guide-hosting-business.md` | Provider list + prices (production ke liye) |
| `server-requirement-analysis.md` | RAM/disk ka poora technical hisaab |
| `aws-lightsail-setup-guide.md` | Lightsail setup (complete ho gaya) |
| `panel-demo-preview.html` | Dashboard ka visual demo (browser me kholo) |

> ⭐ **Parity contract:** `docs/09-cpanel-parity-checklist.md` — cPanel/WHM ki 208 tools ki
> checklist. Yahi decide karta hai ki project kab 100% complete hai.

## 29 Sep — panel 500 ka ASLI root cause (PROVEN)
`/usr/local/alphacp` par systemd ka `ProtectSystem=full` (Ubuntu/Ondrej php8.4-fpm unit) lagta hai
→ php-fpm workers ke liye poora `/usr` **read-only** → laravel log/session/compiled-view likh hi
nahi sakta → **har web request 500**; CLI par ye bandish nahi lagti (isliye saare probes "OK").
Local container me wahi condition bana kar verify: 500 → `ReadWritePaths=/usr/local/alphacp`
drop-in → 200 → PANEL READY ✅. Doctor v1.6 = https://paste.rs/G72oK (detect + auto-fix).

## 29 Sep — installer v0.3.8 + S2C bundle
Fresh installs me PHP-FPM restart se pehle permanent systemd drop-in create hota hai:
`ReadWritePaths=-/usr/local/alphacp` + `ReadWritePaths=-/run/php`. Installer ab active Laravel 13
panel-code artifact `artifacts/panel-code-0.3.1.tar.gz` ko SHA-256 verify karke fetch karta hai;
isliye S2C license client fresh installs me bhi included rahega. Source aur generated installer
`bash -n` se validate hain. Existing dev-srv1 par naya bundle deploy karna abhi pending hai.
