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
| **G1** | **FTP ✅ + Web Disk ✅ + Images ✅ + Trash ✅** (7 Oct) | ✅ | 🔴 high | Files → FTP Accounts |
| **G2** | **Metrics** ✅ core LIVE (Bandwidth/Visitors/Errors/Top, 7 Oct) · Awstats/Webalizer reports pending | ✅/🟡 |  high | Metrics (poora section) |
| **G3** | **App Installer** ✅ LIVE (WordPress one-click, 7 Oct) · Joomla/Drupal/Site Publisher pending | ✅/🟡 | 🟡 med | Software |
| **G4** | **IP Blocker ✅ + WAF ✅ + Virus ✅ + Hotlink ✅ + Leech ✅ (7 Oct)** · SSL-Status pending | ✅/🟡 | 🟡 med | Security |
| **G5** | **Terminal ✅ + Git ✅ + Optimize Website ✅** (7 Oct) | ✅ | 🟢 low | Files/Advanced/Software |
| **G6** | **Monitoring ✅ Resource Usage LIVE (7 Oct)** · alerts/cgroups pending | ✅/🟡 | 🟡 | WHM |
| **G7** | **Billing** ✅ WHM API 1 (`/json-api/*`) + API Tokens UI live (7 Oct) — WHMCS/Blesta-ready | ✅ | 🟢 | WHM |
| **G8** | **Reseller Center** ✅ live (7 Oct: promote/demote + ACL) · multi-server/DNS-cluster pending | ✅/🟡 | 🟢 | WHM |

---

## C. Build order (ek-ek feature, har ek: code + sandbox test + pinned deploy command)

1. **G1 FTP Accounts** — ✅ **DONE + LIVE (7 Oct)**: controller/model/support/migration/view/routes +
   portable `installer/ftp-accounts.sh` + FtpTest 4/4. Server par pure-ftpd configured+started, table migrated.
2. **G2 Metrics** — ✅ **DONE + LIVE (7 Oct)**: parser+controller+view+`installer/metrics.sh`+MetricsTest 3/3.
   (Awstats/Webalizer HTML reports abhi pending — stats dashboard live hai.)
3. **G4 Security** — ✅ **DONE + LIVE (7 Oct)**: IP Blocker + WAF + Virus Scanner. (Leech/Hotlink abhi pending.)
4. **G3 App Installer** — Softaculous-style catalog + WordPress one-click.
5. **G5** Terminal/Git/Images/Optimize.
6. **G6 Monitoring** ✅ Resource Usage live.
7. **G7 Billing** ✅ DONE + LIVE (7 Oct): WHM API 1 (`/json-api/*`) + `installer/whm-api.sh` + WhmApiTest 4/4;
   API Tokens UI (`/api-tokens`) + `installer/api-tokens.sh` + ApiTokensTest 3/3. WHMCS/Blesta-ready.
8. **G8 Reseller Center** ✅ DONE (7 Oct): promote/demote + ACL privileges + `installer/resellers.sh` + ResellersTest 5/5.
9. **G5 Git + Terminal** ✅ DONE (7 Oct): `installer/g5.sh` + G5Test 7/7 (traversal/dangerous blocked).
10. **S15 License Server** ✅ DONE (7 Oct): signed keys issue/verify/revoke + `installer/license-server.sh` + LicenseServerTest 5/5.
11. **Branding** ✅ (7 Oct): views brand-neutral + `tools/sim/check-brand.sh` guard — **rebrand dobara kabhi nahi**.
12. **Dashboard sync** ✅ + **self-flip rule** (7 Oct): naya feature-installer apna tile khud live karta hai — dashboard-sync manual **kabhi nahi**.
13. **DNS Cluster** ✅ (7 Oct): `installer/dns-cluster.sh` + DnsClusterTest 4/4 + tile self-flip.
14. **Ports** ✅ (7 Oct): **owner-controlled** — `/ports` (PortsConfig) + `installer/apply-ports.sh` (ports.json → nginx,
    auto-revert). 8090 primary; compatibility/custom ports owner toggle karta hai. Legal: ports trademark nahi hote.
    → **AGLA: Joomla/Drupal (apps) · Updates · awstats reports · multi-server hardening.**

**Har feature ka flow (project rule):** sandbox me code + test (453-suite me add) → `installer/<feature>.sh`
(commit-pinned + sha256) → user server par ek command chalata hai → sync se GitHub update.

**Note:** main sandbox se aapke server ko directly deploy nahi kar sakta; har feature ek
verified installer-script banega jo aap ek pinned command se chalayenge (jaise sync v1.5 chalaya tha).
