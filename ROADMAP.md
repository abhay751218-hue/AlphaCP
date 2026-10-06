# ROADMAP — 16 Steps to Full cPanel Parity

**Legend:** ✅ done · 🟡 in progress · ⏳ pending
**Updated:** 2026-09-28

| Step | Deliverable | Status |
|---|---|---|
| **S0** | Blueprint: requirements, architecture, DB schema, security matrix, coding standards, license design, installer design, ADRs | ✅ **DONE** |
| **S1** | Server base stack + **one-click installer** (Phase 1-2) + `alphacp` CLI basics (`status`, `doctor`) | ✅ **DONE** (installer + `alphacp` CLI live; panel 0.75.0 HTTP 200) |
| **S2** | Panel core: auth/RBAC/2FA/audit, **paneld agent**, task queue, license client + trial, base UI shell, systemd units | 🔄 **2A ✅** · **2B-1 ✅** · **2B-2 ✅** (password + 2FA + RBAC pages verified) · **2C 🟡** (license client/trial deployed; license-server API pending) |
| **S3** | Provisioning engine: account create/suspend/unsuspend/terminate/limits (+ rollback), account UI | 🔄 core deployed+tested (AccountsController); provisioning UI live |
| **S4** | Packages & limits manager (+ feature lists, cPanel-compatible limit keys) | 🔄 core deployed+tested (PackagesController) |
| **S5** | Website layer: domains (addon/sub/parked/redirect), vhost engine, MultiPHP, SSL/AutoSSL, cron, error pages | 🔄 core deployed+tested (Domains/Park/Forward, Php/PhpIni, Ssl, Cron, ErrorPages); AutoSSL pending |
| **S6** | File Manager, disk usage, FTP (+ jailed shell), SSH keys, Git deploys, trash | 🔄 Files/DiskUsage/Ssh live; **FTP ✅ LIVE (pure-ftpd, 7 Oct)**; Git + Web Disk pending (docs/11 G1/G5) |
| **S7** | Email suite: mailboxes/quotas, forwarders, autoresponders, filters, spam/virus, deliverability (SPF/DKIM/DMARC), lists, webmail | 🔄 email suite live (20+ controllers); **virus scan pending** (docs/11 G4) |
| **S8** | Databases: create/manage, users, privileges, size limits, phpMyAdmin SSO, remote access | 🔄 core deployed+tested (Mysql db/users/wizard, phpMyAdmin, RemoteMysql) |
| **S9** | DNS: zone editor, templates, nameservers, cluster | 🔄 core deployed+tested (DnsZones, ZoneEditor/Templates/Ttl, DynamicDns, NsReport) |
| **S10** | Backup/restore + schedules + remote destinations + **cPanel backup import** | 🔄 core deployed+tested (Backup + wizard/config/destination/restoration) |
| **S11** | Monitoring: usage sync, bandwidth, resource limits (cgroups), alerts, stats, health checks | ⏳ pending (docs/11 G6) |
| **S12** | 💳 **Billing API layer**: WHM API 1 core set (exact shape), native REST v1, webhooks, API tokens UI | ⏳ pending (docs/11 G7) |
| **S13** | Security suite: WAF (ModSecurity), malware scan, brute-force protection, IP blocker, 2FA enforcement, security center | 🔄 Ssl/Security live; **ModSecurity/WAF + virus + IP-blocker UI pending** (docs/11 G4) |
| **S14** | One-click app installer (50+ apps) + WordPress Toolkit (staging/clone/scan/update) | ⏳ pending (docs/11 G3) |
| **S15** | Reseller panel + **multi-server** (central+nodes, rolling updates) + **full updater** (UI one-click, rollback, `support-bundle`) + license server app + launch checklist | 🔄 Accounts/Packages/Transfer live; reseller+multi-server+updater+license-server pending |

## Post-v1 backlog (not committed)
PostgreSQL module · Node/Python/Ruby app manager · DNSSEC UI · self-service license portal ·
email migration from other panels · mobile-friendly refinements · plugin SDK for third parties.
