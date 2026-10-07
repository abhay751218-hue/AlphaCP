# ROADMAP — 16 Steps to Full cPanel/WHM Feature Parity

**Legend:** ✅ done · 🟡 implemented/active, but parity or acceptance remains · ⏳ pending
**Updated:** 2026-10-07

The latest deployed panel baseline is v0.75.0 (`server-snapshot/`). This branch prepares a v0.76.0 source update and has not been deployed. A 🟡 means that features/routes exist but are not a claim of complete cPanel parity or production acceptance; the detailed status is in [`docs/09-cpanel-parity-checklist.md`](docs/09-cpanel-parity-checklist.md).

| Step | Deliverable | Status |
|---|---|---|
| **S0** | Blueprint: requirements, architecture, DB schema, security matrix, coding standards, license design, installer design, ADRs | ✅ **DONE** |
| **S1** | Server base stack, installer, and `alphacp` CLI basics | ✅ **DEPLOYED BASE**; additional install/upgrade hardening continues |
| **S2** | Panel core: auth/RBAC/2FA/audit, `paneld` task queue, license client/trial, base UI shell | ✅ **CORE DEPLOYED**; license-server API/paid activation pending |
| **S3** | Provisioning: account create/suspend/unsuspend/terminate/limits and account UI | 🟡 Core flows and ownership scoping are present; full provisioning/limit lifecycle acceptance remains |
| **S4** | Packages, feature lists and account limits | 🟡 Core package/limit flows present; reseller-owned package ACLs are being extended |
| **S5** | Domains, vhosts, PHP versions, SSL/AutoSSL, cron and error pages | 🟡 Modules are present; parity and production integration remain |
| **S6** | File manager, disk usage, FTP, jailed shell, SSH keys, Git deploys and trash | 🟡 Modules are present; parity and production integration remain |
| **S7** | Email suite: mailboxes, routing, filters, spam, deliverability and webmail | 🟡 Modules are present; parity and production integration remain |
| **S8** | Database management, users/privileges, limits and phpMyAdmin SSO | 🟡 Modules are present; parity and production integration remain |
| **S9** | DNS zones, templates, nameservers and cluster | 🟡 Modules are present; parity and production integration remain |
| **S10** | Backup/restore, schedules, remote destinations and cPanel import | 🟡 Modules are present; end-to-end parity/acceptance remains |
| **S11** | Monitoring, usage/bandwidth, resource limits, alerts and health | 🟡 Initial metrics/health surfaces exist; complete monitoring/resource controls remain |
| **S12** | WHM API 1 compatibility, native REST API, webhooks and API-token UI | 🟡 Partial WHM API coverage; exact-shape and endpoint coverage remain |
| **S13** | WAF, malware scan, brute-force protection, IP blocker and security center | 🟡 Security modules exist; coverage and operational acceptance remain |
| **S14** | One-click apps and WordPress Toolkit | 🟡 App tooling exists; full app/WordPress parity remains |
| **S15** | Reseller panel, ACLs, multi-server, full updater, support bundle, license server and launch | 🟡 v0.76 source adds role-specific reseller workspace and account/package scoping; ACL depth, branding controls, multi-server and launch readiness remain |

## Current UI work (v0.76.0 source, not deployed)

- Separate AlphaCP-branded WHM/operator, reseller, and customer workspaces with role-specific navigation and color palettes.
- Reseller dashboard and reseller-scoped account/package data and mutations.
- `/resellers` listing is restricted to `roles.manage`.
- Full 77-file panel test matrix passes: **481 pass, 0 fail, 6 wasm-skip** via php-wasm; browser/responsive review and the remaining security audits are pending.
- This is a shared Laravel application with authorization boundaries—not yet three separately deployed products/hosts. Do not deploy without explicit authorization.

## Post-v1 backlog (not committed)

PostgreSQL module · Node/Python/Ruby app manager · DNSSEC UI · self-service license portal ·
email migration from other panels · mobile refinements · plugin SDK for third parties.
