# 11 — cPanel 100% Parity Map (server-derived, 7 Oct 2026)

**Source of truth:** `server-snapshot/files/usr/local/alphacp/panel/` (sync `af47c8f`) —
**71 controllers · 84 views · 207 routes · 75 tests (453 pass, 0 fail)**.
Har row server ke asli controller/route se verify ki gayi hai (grep), guess nahi.

Legend: ✅ = controller+view+test hai · 🟡 = partial · ❌ = missing (banana hai)

---

## A. Jo already bana hua hai (cPanel ke barabar)

| cPanel section | AlphaCP controllers | Status |
|---|---|---|
| **Email** (accounts, forwarders, routing, autoresponders, filters, global filters, mailing lists, spam, boxtrapper, default addr, address importer, track delivery, deliverability, webmail, email disk, calendar, encryption) | 20+ controllers | ✅ |
| **DNS / Zones** (zone editor, templates, ttl, dynamic dns, ns-report, hostname-a, dns-sync, dns-cleanup) | DnsZones, ZoneEditor, ZoneTemplates, ZoneTtl, DynamicDns, NsReport, HostnameA, DnsSync, DnsCleanup | ✅ |
| **Domains** (domains, park, forward/redirect, transfer tool/review/restore) | Domains, ParkDomain, DomainForward, TransferTool/Review/Restore | ✅ |
| **Databases** (mysql db, users, wizard, phpmyadmin, remote mysql) | MysqlDatabases, MysqlUsers, MysqlWizard, Phpmyadmin, RemoteMysql | ✅ |
| **Files** (file manager, indexes, mime types, disk usage, directory privacy) | Files, Indexes, MimeTypes, DiskUsage, Privacy | ✅ |
| **Backup** (backup, wizard, config, destination, restoration, user-selection, file/dir restoration) | 8 controllers | ✅ |
| **Security** (ssl, ssh, security/ip, encryption) | Ssl, Ssh, Security, Encryption | ✅ |
| **PHP** (version, ini, handlers) | Php, PhpIni, Handlers | ✅ |
| **Advanced** (cron, error pages, track dns, system, audit, users, accounts, packages, license) | Cron, ErrorPages, TrackDns, System, Audit, Users, Accounts, Packages, License | ✅ |

## B. Jo MISSING hai — 100% cPanel tak pahunchne ke liye banana hai

| # | cPanel feature | AlphaCP | Priority | cPanel me kahan |
|---|---|---|---|---|
| **G1** | **FTP Accounts** ✅ LIVE (pure-ftpd, 7 Oct) · Web Disk abhi pending | ✅/🟡 | 🔴 high | Files → FTP Accounts |
| **G2** | **Metrics**: Awstats, Webalizer, Analog, Raw Access, Errors, Bandwidth, Visitors, Metrics Editor | ❌ | 🔴 high | Metrics (poora section) |
| **G3** | **App Installer / Site Software / WordPress Manager / Site Publisher** | ❌ | 🟡 med | Software |
| **G4** | **ModSecurity (WAF), Virus Scanner, Leech Protection, Hotlink Protection, IP Blocker UI, SSL/TLS Status** | ❌/🟡 | 🟡 med | Security |
| **G5** | **Terminal, Git Version Control, Images, Optimize Website** | ❌ | 🟢 low | Files/Advanced/Software |
| **G6** | **Monitoring** (resource/disk/mem alerts) — roadmap Step 11 | ❌ | 🟡 | WHM |
| **G7** | **Billing / WHMCS-like** — roadmap Step 12 | ❌ | 🟢 | WHM |
| **G8** | **Reseller full** (Step 15) | 🟡 partial | 🟢 | WHM |

---

## C. Build order (ek-ek feature, har ek: code + sandbox test + pinned deploy command)

1. **G1 FTP Accounts** — ✅ **DONE + LIVE (7 Oct)**: controller/model/support/migration/view/routes +
   portable `installer/ftp-accounts.sh` + FtpTest 4/4. Server par pure-ftpd configured+started, table migrated.
2. **G2 Metrics** (AGLA) — Awstats/Webalizer parse + Visitors/Errors/Bandwidth dashboards.
3. **G4 Security** — ModSecurity toggle, Virus Scanner (ClamAV), IP Blocker, Hotlink/Leech.
4. **G3 App Installer** — Softaculous-style catalog + WordPress one-click.
5. **G5** Terminal/Git/Images/Optimize.
6. **G6 Monitoring → G7 Billing → G8 Reseller.**

**Har feature ka flow (project rule):** sandbox me code + test (453-suite me add) → `installer/<feature>.sh`
(commit-pinned + sha256) → user server par ek command chalata hai → sync se GitHub update.

**Note:** main sandbox se aapke server ko directly deploy nahi kar sakta; har feature ek
verified installer-script banega jo aap ek pinned command se chalayenge (jaise sync v1.5 chalaya tha).
