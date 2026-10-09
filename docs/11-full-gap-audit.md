# 11 — FULL GAP AUDIT v2: "Kya kya kami hai" (09 Oct 2026, D5 ke baad)

> User ki complaint bilkul sahi hai: **theme/shell sab jagah hai, D1–D5 ke 13 pages cPanel-depth
> me hain — lekin baaki ~75 inner pages abhi bhi purane plain style me hain, kuch tools sirf
> "preference/JSON" stub hain, aur kuch real-cPanel tools bilkul missing hain.**
> Ye list poore code scan se bani hai (90 controllers, 88 view-folders, 102 agent tasks).

---

## 1) ✅ DEEP — cPanel/WHM-depth COMPLETE (D1–D5 + theme, LIVE)

| Page | Wave |
|---|---|
| Email Accounts, Forwarders | D1 |
| Domains, Zone Editor | D2 |
| MySQL Databases, MySQL Users, phpMyAdmin (pref) | D3 |
| WHM Create Account, List Accounts, Packages | D4 |
| Cron Jobs, SSL/TLS Status, File Manager (basic), Dark Mode | D5 |
| Login, dashboards (cPanel grid + WHM server info), sidebar/topbar/icons | theme v1.2 |

**Total: ~16 pages company-grade. Baaki neeche.**

## 2) 🟡 KAAM KARTA HAI par cPanel-JAISA NAHI DIKHTA (purana plain style)

In sab me backend sahi hai (agent task + DB), lekin UI me stats-strip / search / icons /
Manage-expander / help-cards nahi — wahi purani chhoti table + form:

**Email group:** Default Address, Autoresponders, Email Routing, Email Filters (per-box),
Global Filters, Mailing Lists, Spam Filters, BoxTrapper, Calendars & Contacts, Encryption (PGP),
Email Disk Usage, Track Delivery, Address Importer, Deliverability (SPF/DKIM)
**Files group:** FTP Accounts, Web Disk, Disk Usage, Backup, Backup Wizard, Git Version Control,
File/Directory Restoration, Trash
**Security group:** IP Blocker, Directory Privacy, Hotlink Protection, Leech Protection,
SSH Access, Security Policy/Password/Sessions, API Tokens
**Software/Advanced:** PHP Selector (MultiPHP), MIME Types, Apache Handlers, Indexes,
Error Pages, Optimize Website
**WHM side:** DNS Zones (server), DNS Cluster/Sync/Cleanup, Zone Templates/TTL, Park Domain,
NS Report, Hostname A, Dynamic DNS, Domain Forward, Track DNS, Resellers, Users/Roles,
Transfer Tool (import cPanel), Backup Destinations/Config, License, System/Tasks/Audit,
Global Email Routing, Ports, Service Status (read-only)

**≈ 55+ pages — in sab ko D1–D5 wala hi UI-pattern chahiye. Backend change nahi lagega
(sirf views) isliye ye SABSE SASTA sabse bada visual upgrade hai.**

## 3) 🔴 STUB / PREFERENCE-ONLY — button hai, ASLI app nahi

| Tool | Abhi kya hai | Real cPanel me kya hota hai | Chahiye kya |
|---|---|---|---|
| Webmail | on/off + "open" (JSON pref) | Roundcube inbox browser me | Roundcube install + SSO (agent + installer) |
| phpMyAdmin | on/off pref + connect card | pura DB GUI browser me | phpMyAdmin app + auto-login |
| Terminal | read-only output (`terminal.run` task hai) | interactive shell | web terminal UI (xterm.js + polling) |
| Service Status (WHM) | read-only list | restart/stop buttons | agent me `service.restart` task |
| Metrics | 4 counters + top pages | Awstats-jaise graphs, monthly | charts + date-range (data `metrics.access` me hai) |
| Monitoring | 17-line stub | CPU/RAM/IO graphs | time-series collect + charts |
| Apps/Optimize/Images/Disk | chhote stubs | installers, mod_deflate, thumbnailer, disk treemap | per-tool kaam |

## 4) ❌ BILKUL MISSING — real cPanel/WHM me hai, yahan nahi

**Client side:** Raw Access Logs download · Visitors log-viewer · Awstats/Webalizer analytics ·
Resource Usage (CPU/RAM per account) · Site Publisher templates · WordPress/1-click installer UI
(`apps.install` agent task ready hai!) · Two-Factor Authentication (2FA) · MultiPHP INI Editor
**WHM side:** Tweak Settings · Basic Setup wizard · Feature Manager UI (DB me hai, UI nahi) ·
**Customization/Branding (logo + colors — reseller apna brand lagaye)** · Service Manager
(restart) · Mail Queue Manager · ModSecurity UI (`waf.*` tasks ready hain!) · Server Time ·
Update Preferences · Email All Users · cPHulk-style brute-force UI · PHP Configuration (EA4-style)

## 5) ⛔ Agent-change pending (pehle se note kiye)

D1.5 mailbox suspend · D2.5 DNS TTL/MX-priority/AAAA · D3.5 `db.user.revoke` + check/repair ·
File Manager upload/zip/chmod (`files.set` me ops add karne honge)

---

## PLAN — agle waves (recommended order)

| Wave | Kya | Risk | Files |
|---|---|---|---|
| **D6a** | UI-uniformity pass 1: Email group ke 14 pages → D1-style pattern | views-only, zero | ✅ DEPLOYED LIVE 09 Oct |
| **D6b** | UI pass 2: Files + Security groups (Images, Privacy, Disk, FTP, WebDisk, BackupWiz, Git, FileRest, Trash, Indexes, SSH, IP Blocker, API Tokens, Audit) | views-only | ✅ DEPLOYED LIVE 09 Oct |
| **D6c** | UI pass 3: Software/Advanced + WHM pages | views-only | ✅ BUILT — installer ready |
| **D7** | Metrics real: graphs (CSS/JS charts), Raw Access viewer, Visitors | views+JS | medium |
| **D8** | Service Manager restart + Mail Queue + ModSecurity UI (waf tasks) | agent+panel | medium |
| **D9** | Webmail (Roundcube+SSO) + phpMyAdmin app + Terminal interactive | bada — server install | high |
| **D10** | 1-click App Installer (WordPress) UI + 2FA + Branding/Customization | mixed | medium |

**Rule wahi:** har wave = sim-tested installer + commit-pinned COMMANDS.md row + rollback.

> Note (pehle se maana hua): "cPanel" naam/logo/trademark kabhi use nahi hoga — look & depth 100% same, branding AlphaCP.

## Dashboard ke "Sx me aayega" tiles (live ModuleCatalog se — 09 Oct verified)

Live server ka dashboard counter: **94 live · 8 planned · 1 optional · total 103.**
Ye hi 9 tiles greyed-out dikhte hain — in sab ke liye backend (controller + route +
kuch me agent task) chahiye, isliye ye UI-wave me nahi, apni feature-wave me aayenge:

| Tile | Step | Kis wave me banega |
|---|---|---|
| Errors (error log viewer) | S11 | **D7** (metrics/logs wave) |
| Raw Access (raw logs download) | S11 | **D7** |
| Awstats (visitor graphs) | S11 | **D7** |
| Network Tools (dig/trace) | S11 | **D7** |
| Security Policies | S13 | **D8** (security wave) |
| Node.js Selector | S14 | **D10** (software wave) |
| PHP Composer | S14 | **D10** |
| Updates (panel self-update UI) | S15 | **D10** |
| PostgreSQL | post-v1 | optional addon — v1 ke baad |

Baaki 94 tiles LIVE hain — unki kami "feature missing" nahi, "UI plain" thi,
jo D6a/D6b/D6c UI-waves me cPanel-style ho rahi hai.

> **Correction (09 Oct):** 2FA pehle "missing" list me tha — galat. Live `security` page par 2FA (TOTP enable/disable), password change aur active sessions already maujood hain.
