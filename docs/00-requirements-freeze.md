# 00 — Requirements Freeze (v1 Scope)

> **Hindi summary:** Ye document batata hai ki Version 1 me kya-kya banega aur kya nahi.
> Isse aage badhne se pehle owner (Abhay) approve karega. Scope creep se bachne ke liye ye
> "freeze" hai — kuch add karna ho to naye version me jayega.

**Status:** 🔒 FROZEN v1 — 2026-09-28
**Owner sign-off:** pending (owner to review)

---

## 1. Product Goal

A commercially sellable hosting control panel with **cPanel/WHM functional parity**, built for
Indian hosting businesses:

- Hosting providers run it on their servers (like cPanel).
- Billing software integrates with zero code changes (WHM API 1 compatible).
- The panel itself is sold via **licenses** (tiers below).

## 2. Personas & Interfaces

| Persona | Interface | Port(s) | cPanel equivalent |
|---|---|---|---|
| Panel Owner / Super Admin | **Admin Panel** (WHM-like) | 2086/2087 | WHM |
| Reseller | **Reseller Panel** (subset + branding) | 2086/2087 (scoped) | Reseller WHM |
| Hosting Customer | **Client Panel** (cPanel-like) | 2082/2083 | cPanel |
| Mail user | **Webmail** (Roundcube) | 2095/2096 | Webmail |
| Billing system | **API**: WHM API 1 compatible + native REST v1 | 2087 / 2086 | WHM API 1 |
| Sysadmin | **CLI**: `alphacp <command>` | SSH | `/usr/local/cpanel/*` |
| Vendors (future) | **License Server** (separate app) | 443 | cPanel license portal |

## 3. v1 Module Scope (with roadmap step numbers)

| # | Module | Step | v1? | Notes |
|---|---|---|---|---|
| 1 | Panel core: auth, RBAC, 2FA, settings, audit, notifications | S2 | ✅ | |
| 2 | Privileged agent (`paneld`) + task queue | S2 | ✅ | Foundation for everything |
| 3 | Provisioning: create/suspend/unsuspend/terminate/modify account | S3 | ✅ | With rollback |
| 4 | Packages & limits manager (+ feature lists) | S4 | ✅ | cPanel-compatible limit fields |
| 5 | Domains: main/addon/subdomain/parked/redirect + vhost engine | S5 | ✅ | |
| 6 | MultiPHP manager + PHP INI editor | S5 | ✅ | PHP 7.4–8.4 |
| 7 | SSL/AutoSSL (ACME) + custom certs + CSR | S5 | ✅ | |
| 8 | Cron jobs manager | S5 | ✅ | |
| 9 | File Manager + Disk usage viewer | S6 | ✅ | Web-based |
| 10 | FTP accounts + Pure-FTPd + jailed shell + SSH keys | S6 | ✅ | |
| 11 | Git deployments (basic) | S6 | ✅ | |
| 12 | Email suite: mailboxes, forwarders, autoresponders, filters, spam/virus, lists, webmail, deliverability (SPF/DKIM/DMARC) | S7 | ✅ | Biggest module |
| 13 | Databases: create/manage, users, privileges, size limits, phpMyAdmin SSO | S8 | ✅ | MySQL/MariaDB |
| 14 | DNS: zone editor, templates, private nameservers, cluster | S9 | ✅ | BIND9 |
| 15 | Backup/restore + schedules + remote destinations + cPanel backup import | S10 | ✅ | |
| 16 | Monitoring: bandwidth/disk/resource usage, alerts, stats | S11 | ✅ | Billing usage source |
| 17 | **Billing API layer**: WHM API 1 (core set) + native REST + webhooks | S12 | ✅ | Contract-critical |
| 18 | Security suite: WAF (ModSecurity), malware scan, brute-force protection, IP blocker, 2FA enforcement | S13 | ✅ | |
| 19 | One-click app installer (50+ apps) + WordPress Toolkit (basic) | S14 | ✅ | |
| 20 | Reseller panel + multi-server (central + nodes) | S15 | ✅ | |
| 21 | **License system** (panel's own) + license server app | S2/S15 | ✅ | Commercial requirement |
| 22 | **One-click installer + one-click updater** + `alphacp doctor` | S1/S15 | ✅ | Commercial requirement |

## 4. Explicit Non-Goals (v1)

- ❌ Windows hosting / IIS
- ❌ PostgreSQL module (schema prepared, UI later)
- ❌ Node.js/Python/Ruby app manager (v1.1)
- ❌ Full email migration tool from other panels (v1.1; cPanel backup import IS in v1)
- ❌ Kubernetes/container-based hosting (never — this is classic shared hosting)
- ❌ DNSSEC UI (v1.1)
- ❌ Mobile app
- ❌ Its own billing/invoicing system (external billing only; license tickets only)

## 5. Quotas & Limits (cPanel-compatible field names)

Stored on `packages` with these exact API-visible names (billing reads them):

| Limit | Key | Unit |
|---|---|---|
| Disk quota | `QUOTA` | MB |
| Monthly bandwidth | `BWLIMIT` | MB |
| Email accounts | `MAXPOP` | count |
| Forwarders | `MAXFWD` | count |
| Autoresponders | `MAXRESP` | count |
| Email filters | `MAXPASS` | count |
| Mailing lists | `MAXLST` | count |
| FTP accounts | `MAXFTP` | count |
| MySQL databases | `MAXSQL` | count |
| Subdomains | `MAXSUB` | count |
| Parked/alias domains | `MAXPARK` | count |
| Addon domains | `MAXADDON` | count |
| Cron jobs | `MAXCRON` | count |
| Inodes (files) | `MAXINODE` | count |
| Per-mailbox quota | `MAILBOXQUOTA` | MB |
| Per-database size | `DBQUOTA` | MB |
| Emails per hour | `MAXEMAILPERHOUR` | count |
| Max message size | `MAXMSGSIZE` | MB |
| Shell access | `HASSHELL` | bool |
| CPU | `CPULIMIT` | % |
| RAM | `RAMLIMIT` | MB |
| Disk I/O | `IOLIMIT` | MB/s |
| Processes (NPROC) | `NPROCLIMIT` | count |
| Entry processes | `EPLIMIT` | count |
| Dedicated IP | `DEDICATEDIP` | bool |

`-1` or `0` = unlimited (exact semantics documented per limit in module docs).

## 6. Ports (server-wide map)

| Port | Service |
|---|---|
| 80/443 | Customer websites (shared) |
| 2082/2083 | Client panel (HTTP/HTTPS) |
| 2086/2087 | Admin/Reseller panel + WHM API (HTTP/HTTPS) |
| 2095/2096 | Webmail (HTTP/HTTPS) |
| 22 | SSH (key-only by default) |
| 25/465/587 | SMTP in / SMTPS / submission |
| 143/993, 110/995 | IMAP, POP3 |
| 21 + 49152–65535 | FTP + passive range (FTP optional/configurable) |
| 53 TCP/UDP | DNS (nameserver mode) |
| 3306 | ❌ never public |
| 8443 | Internal: agent ↔ panel (localhost only) |

> **Port decision (28 Sep 2026):** ek hi primary URL — `https://<server>:8090` — admin, reseller
> aur client sabke liye (role ke hisaab se UI badalta hai). 2082/2083/2086/2087 sirf
> **compatibility** ke liye khule rehte hain, kyunki WHMCS/Blesta/Clientexec aur purane
> cPanel users inhi ports ki ummeed karte hain (WHM API 1 = 2086/2087).

## 7. OS / Platform Support Matrix

| OS | Status |
|---|---|
| Ubuntu 24.04 LTS | ✅ Primary |
| Ubuntu 22.04 LTS | ✅ Supported |
| AlmaLinux 9 / Rocky 9 | ✅ Secondary |
| AlmaLinux 8 | 🟡 Best-effort |
| Debian 12/13 | 🟡 Best-effort |
| ARM64 (aarch64) | 🟡 Dev/testing only (no commercial tools like LiteSpeed; panel supports it) |
| x86_64 | ✅ Production target |

Minimum hardware (production): **2 GB RAM min / 4 GB recommended, 40 GB disk, static IPv4.**
Dev minimum: 4 GB RAM, 80 GB disk.

## 8. Compatibility Promises (contract with the outside world)

1. **WHM API 1 (compatibility set for v1)** — exact cPanel JSON shape:
   `createacct`, `suspendacct`, `unsuspendacct`, `removeacct`, `changepackage`, `editquota`,
   `passwd`, `listaccts`, `accountsummary`, `showbw`, `listpkgs`, `addpkg`, `editpkg`,
   `killpkg`, `create_user_session`, `suspend_outgoing_email`, `unsuspend_outgoing_email`,
   `gethostname`, `dumpzone`, `addzonerecord`, `editzonerecord`, `removezonerecord`,
   `adddns`, `killdns`.
2. **Auth compatibility:** `Authorization: whm USER:TOKEN` header + legacy user/password +
   API tokens with per-function permissions; ports 2086/2087.
3. **cPanel UAPI subset (client-side):** `Email` (add_pop, delete_pop, list_pops,
   passwd_pop, get_pop_quota, set_pop_quota, list_forwarders, add_forwarder, delete_forwarder),
   `Mysql` (create_database, delete_database, create_user, set_privileges_on_database, list_databases),
   `DomainInfo` (list_domains, addon_domain), `Fileman` (list_files, get_file_content,
   save_file_content, mkdir, fileop), `Cron` (add_line, fetch_cron), `SSL` (installed_hosts).
4. **cPanel backup import:** accept cPanel full-backup tarballs on migration (S10).
5. **Not claimed:** we are not cPanel. No use of cPanel trademarks/branding/assets.

## 9. License Tiers (draft — pricing is owner's call)

| Tier | Scope | Accounts | Server count | Notes |
|---|---|---|---|---|
| Trial | Full features | 20 | 1 | 15 days, auto-issued |
| Starter | 1 server | 50 | 1 | |
| Business | 1 server | 250 | 1 | |
| Unlimited | 1 server | unlimited | 1 | |
| Multi-Server | per node | inherits | N nodes | +1 node license per node |
| OEM/Reseller | white-label | volume | volume | partner program |

Feature flags in signed license payload allow future add-on modules. Details: `05-license-system.md`.

## 10. Success Criteria for v1 (acceptance)

1. A billing system (WHMCS or custom) can create/suspend/terminate accounts end-to-end.
2. A customer can host a real website + working mail (IMAP/SMTP + webmail) + MySQL + SSL.
3. Admin can run 200+ accounts on one 4 GB server with enforced limits.
4. Installer brings a blank Ubuntu server to a working panel in < 60 min, one command.
5. Updater moves version N → N+1 with rollback on failure, one click / one command.
6. License: trial → paid activation → heartbeat → grace behaves per design; customer
   services never stop due to license state.
7. All privileged operations are audited and reversible where technically possible.
