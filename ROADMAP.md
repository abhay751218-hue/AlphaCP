# ROADMAP — 16 Steps to Full cPanel Parity

**Legend:** ✅ done · 🟡 in progress · ⏳ pending
**Updated:** 2026-09-28

| Step | Deliverable | Status |
|---|---|---|
| **S0** | Blueprint: requirements, architecture, DB schema, security matrix, coding standards, license design, installer design, ADRs | ✅ **DONE** |
| **S1** | Server base stack + **one-click installer** (Phase 1-2) + `alphacp` CLI basics (`status`, `doctor`) | ⏳ next |
| **S2** | Panel core: auth/RBAC/2FA/audit, **paneld agent**, task queue, license client + trial, base UI shell, systemd units | ✅ **2A/2B** · **2C 🟡** (license client/trial deployed; license-server API pending) |
| **S3** | Provisioning engine: account create/suspend/unsuspend/terminate/limits (+ rollback), account UI | ✅ **deployed 0.4.0** (dedicated IP later) |
| **S4** | Packages & limits manager (+ feature lists, cPanel-compatible limit keys) | ✅ **deployed 0.5.0** |
| **S5** | Website layer: domains (addon/sub/parked/redirect), vhost engine, MultiPHP, SSL/AutoSSL, cron, error pages | ✅ **through Apache Handlers (0.14.0)** |
| **S6** | File Manager, disk usage, FTP (+ jailed shell), SSH keys, Git deploys, trash | 🟡 **FM + Privacy + Disk Usage + SSH (0.18.0)** — zip/FTP/jail later |
| **S7** | Email suite: mailboxes/quotas, forwarders, autoresponders, filters, spam/virus, deliverability (SPF/DKIM/DMARC), lists, webmail | 🟡 **through Webmail (0.35.0)** — virus/ClamAV later |
| **S8** | Databases: create/manage, users, privileges, size limits, phpMyAdmin SSO, remote access | 🟡 **through Remote MySQL (0.39.0)** — users/SSO later |
| **S9** | DNS: zone editor, templates, nameservers, cluster | 🟡 **through NS Record Report (0.48.0)** — BIND later |
| **S10** | Backup/restore + schedules + remote destinations + **cPanel backup import** | ⏳ |
| **S11** | Monitoring: usage sync, bandwidth, resource limits (cgroups), alerts, stats, health checks | ⏳ |
| **S12** | 💳 **Billing API layer**: WHM API 1 core set (exact shape), native REST v1, webhooks, API tokens UI | ⏳ |
| **S13** | Security suite: WAF (ModSecurity), malware scan, brute-force protection, IP blocker, 2FA enforcement, security center | ⏳ |
| **S14** | One-click app installer (50+ apps) + WordPress Toolkit (staging/clone/scan/update) | ⏳ |
| **S15** | Reseller panel + **multi-server** (central+nodes, rolling updates) + **full updater** (UI one-click, rollback, `support-bundle`) + license server app + launch checklist | ⏳ |

## Post-v1 backlog (not committed)
PostgreSQL module · Node/Python/Ruby app manager · DNSSEC UI · self-service license portal ·
email migration from other panels · mobile-friendly refinements · plugin SDK for third parties.
