# 🚀 Aapka Custom Hosting Panel (cPanel-Jaisa) — Full Master Plan
**Version:** 1.0 | **Date:** 28 September 2026 | **Language:** Hindi / Hinglish

> Ye document aapka **roadmap + blueprint** hai. Isme abhi code nahi hai — sirf pura plan, steps, feature checklist, aur process hai.
> Jab aap bolenge "Step 1 chalu karo" — tab main actual code/config files workspace me banana shuru karunga.

---

## 📌 1. Ek Nazar Me (Summary)

| Cheez | Detail |
|---|---|
| Kya banana hai | cPanel + WHM jaisa **apna custom hosting control panel** (multi-user, multi-role, quotas, billing-ready) |
| Kiske liye | Shared hosting / reseller hosting business (aapka billing software ke saath juda hua) |
| Total Steps | **16 Steps** (5 Milestones me divided) |
| MVP (business chalane layak) | Step 0 → Step 12 tak (~4–6 mahine, 2–3 developer team ke saath) |
| 100% Feature Parity | Step 13 & 15 tak complete (~8–12 mahine total + ongoing polish) |
| Sabse important Step | **Step 12 — Billing/API Integration** (isliye aapka WHMCS/any billing 100% kaam karega) |
| Aap ka role | Test karna, decisions dena, server dena. Code/hardening/documentation main dunga |

**Reality Check (seedhi baat):** cPanel 25+ saal se develop ho raha hai, uske andar 2000+ features hain. Isliye "100% same" ka matlab hai — **feature-by-feature parity, phase-by-phase**. Hum shortcut nahi lenge, lekin smart tarike se banayenge (neeche "3 Raste" dekhein). Har step pe aapko ek **chalta hua, testable** piece milega — ek din me poora panel nahi milega, lekin har hafte progress dikhegi.

---

## 🧭 2. "100% cPanel Jaisa" ka Asli Matlab Kya Hai?

cPanel asal me **do panel** hote hain:

| Panel | Kaun use karta hai | Kaam |
|---|---|---|
| **WHM** (Web Host Manager) | Aap (hosting owner), Reseller | Server manage, accounts banana/suspend karna, packages, IPs, DNS cluster, server settings |
| **cPanel** | End customer | Apni website, email, database, files, SSL, cron — sab manage karna |
| **Webmail / cPanel SSO** | Customer | Roundcube webmail, billing se one-click login |

**Toh hum 3 alag-alag interfaces banayenge:**

1. **Admin Panel (WHM-jaisa)** → aapke liye
2. **Reseller Panel** → aapke resellers ke liye (optional, but plan me included)
3. **User cPanel (Client Panel)** → aapke customers ke liye
4. **+ API Layer** → aapke billing software ke liye (yahi sabse critical hai)

---

## 🛠️ 3. Kis Raste Se Banayenge? (3 Options — Main Recommend Karta Hoon Option C)

| Option | Kaise | Fayda | Nuksan |
|---|---|---|---|
| **A. 100% Scratch** | Sab kuch zero se — har service ka config khud likhna | Full control, 100% apna IP | 2–3 saal ka kaam, bahut risky |
| **B. For Open-Source Fork** | HestiaCP / ISPConfig (GPL) fork karke rebrand + apna UI | Sabse fast (mahino me ready) | GPL license (source share karna padega), limited control, UI purana |
| **C. ✅ HYBRID (Recommended)** | **Apna panel + apna UI + apna API layer**, lekin andar Linux services (Nginx/Apache, Exim, Dovecot, MariaDB, BIND/PowerDNS, Pure-FTPd) ko standard, battle-tested tarike se manage karna — jaise cPanel karta hai | Balanced: apna brand + apna billing integration + tez delivery + kam bug | Thoda zyada engineering (2–3 dev, 6–10 mahine) |

**Note:** CloudPanel aur OpenPanel ki license resale/modification allow **nahi** karti — unhe base banana risky hai. HestiaCP (GPL-3.0), ISPConfig (BSD), CyberPanel (GPL-3.0) legally use kiye ja sakte hain, lekin GPL ke rules follow karne padenge. **Isliye Option C best hai** — kuch bhi fork nahi karenge, sab apna code hoga.

**Legal advice (important):**
- cPanel ka **logo, naam, branding, icons, colors** copy nahi karenge — apna brand (jaise "XYZPanel") rakhna hai.
- **Functionality aur API-compatibility** banana 100% allowed hai (ye sirf ek interface standard hai). WHMCS/Blesta me "cPanel module" kaam karega kyunki hum wahi API language bolenge, lekin hum cPanel nahi hain.
- Kabhi bhi "we are cPanel" ya "cPanel license" jaise claims nahi karenge.

---

## 🏗️ 4. Architecture — System Kaise Kaam Karega

```
                    ┌──────────────────────────────┐
   Aapka Billing  →  │   API LAYER (WHM API 1 + UAPI)│  ← WHMCS/Blesta yahan baat karega
   Software         │   Port 2087 (SSL) / 2083     │
                    └──────────────┬───────────────┘
                                   │ (API Token auth)
                    ┌──────────────▼───────────────┐
                    │        PANEL CORE            │
                    │  Auth + RBAC + REST API      │
                    │  MySQL/Postgres (panel DB)   │
                    │  Queue (Redis) + Audit Log   │
                    └──────────────┬───────────────┘
                                   │ Unix Socket (root tasks)
                    ┌──────────────▼───────────────┐
                    │   PRIVILEGED AGENT (root)    │  ← Sirf validate ki gayi
                    │   User create, quota, vhost,  │    tasks chalayega
                    │   mail, DB, DNS, backup...    │    (KHUD ROOT NAHI)
                    └──────────────┬───────────────┘
                                   │ Config templates + Reload
     ┌─────────┬─────────┬─────────┼─────────┬──────────┬──────────┐
   Nginx/    PHP-FPM   MariaDB   Exim/    Dovecot    BIND/     Pure-FTPd
   Apache     (multiple  (MySQL)  Postfix   (IMAP)    PowerDNS   + Jailkit
              versions)                                  |
                                   ┌────────────────────▼───────────┐
                                   │ Quotas (disk), cgroups/LVE      │
                                   │ (CPU/MEM/IO/process limits)     │
                                   └─────────────────────────────────┘
```

**Golden Rules (security ke liye — inhe kabhi nahi todna):**
1. Panel UI **kabhi root me nahi chalega** — sirf ek restricted agent root tasks karega.
2. Har task ka **audit log** hoga (kisne kya kiya, kab, kahan se).
3. Sab config **template engine** se generate hoga, direct `echo >> config` nahi.
4. Multi-server ready: **Central Panel + Node Agents** (aage scale karne ke liye).
5. Har account = ek Linux user + apna home + apna quota (`/home/username`), exactly cPanel pattern.

---

## 💳 5. Billing Software Integration — YEH SABSE IMPORTANT PART HAI

Aapne kaha "jaise cPanel koi bhi billing support kar leta hai". Wo isliye hota hai kyunki cPanel **WHM API 1** naam ka standard API deta hai. Hum bhi wahi API "bolenge" — toh WHMCS / Blesta / Clientexec / HostBill / aapka custom billing **koi change kiye bina kaam karega**.

### 5.1 Jo API Functions Hum 100% Implement Karenge (Step 12)

| Kaam | WHM API 1 Function | Billing me kahan use hota hai |
|---|---|---|
| Account banana | `createacct` | Order paid → hosting auto-create |
| Account suspend | `suspendacct` | Invoice unpaid → auto suspend |
| Unsuspend | `unsuspendacct` | Payment aayi → auto unsuspend |
| Account delete | `removeacct` | 30 din baad terminate |
| Password badalna | `passwd` | Client area se password change |
| Package badalna | `changepackage` | Plan upgrade/downgrade |
| Disk quota badalna | `editquota` | Custom plan |
| Accounts list | `listaccts` | WHMCS "Usage Updates" |
| Ek account ki detail | `accountsummary` | Client area info |
| Bandwidth usage | `showbw` | Monthly stats/overage |
| Packages list | `listpkgs` | WHMCS product dropdown |
| Package banana/badalna/hatana | `addpkg` / `editpkg` / `killpkg` | Admin panel |
| DNS zone | `dumpzone`, `addzonerecord`, `editzonerecord`, `removezonerecord`, `adddns`, `killdns` | DNS automation |
| Client area SSO login | `create_user_session` | "Login to cPanel" button |
| Outgoing email suspend | `suspend_outgoing_email` / `unsuspend_outgoing_email` | Spam control |
| Server info / Test connection | `gethostname`, version, load, disk | WHMCS "Test Connection" |
| cPanel-level (UAPI) | `Email::add_pop`, `Email::list_pops`, `Mysql::create_database`, `DomainInfo::list_domains`, `Fileman::*` etc. | Custom scripts, plugins |

### 5.2 WHMCS Ko Jo Cheezein Chahiye (aur hum denge)

- **Port 2087 (SSL)** pe API endpoint: `/json-api/<function>` → exactly cPanel format ka JSON response
- **Auth:** `Authorization: whm USERNAME:API-TOKEN` header + password auth (dono)
- **API Token Permissions** (in sab ko support karenge):
  `basic-whm-functions`, `basic-system-info`, `cpanel-api`, `create-acct`, `create-user-session`, `suspend-acct`, `upgrade-account`, `kill-acct`, `passwd`, `acct-summary`, `list-accts`, `show-bandwidth`, `list-pkgs`
- **cPanel side ports:** 2082/2083 (user panel), 2095/2096 (webmail) — WHMCS ka "Login to cPanel" button inhi pe jata hai
- **2 tarike se integration possible hai** — dono denge:
  - **Tarika 1 (Sabse smart):** Hum WHM API compatible endpoints banayenge → WHMCS ka **built-in cPanel module** hi kaam karega, kuch install nahi karna padega ✅
  - **Tarika 2 (Agar aap chahein):** Apna **custom WHMCS server module** (PHP) jo hamare native REST API se baat kare — zyada fast, zyada features (usage stats, live status, etc.)

**Jo bhi billing software aap use karte ho — naam batao (WHMCS? Blesta? custom?) — main Step 12 ko usi ke hisaab se exact tune karunga.**

---

## 🧰 6. Tech Stack + OS (Recommended)

| Layer | Choice | Kyun |
|---|---|---|
| OS | **AlmaLinux 9** (RHEL family) | cPanel bhi yahi family use karta hai, packages stable, long support |
| Panel Backend | **PHP 8.3 + Laravel** (ya Node.js/NestJS — aapki team pe depend) | WHMCS bhi PHP hai → easy integration, bahut devs available in India |
| Panel Frontend | **React + Tailwind** (ya Blade + Alpine for fast start) | cPanel jaisa familiar icon-grid dashboard |
| Panel Database | MySQL 8 / MariaDB (alag DB, root se isolated) | Simple, reliable |
| Queue/Cache | Redis | Account create jaise heavy tasks background me |
| Web Server | Apache (with Nginx reverse proxy) ya sirf Nginx | .htaccess support ke liye Apache zaroori hai (shared hosting me) |
| PHP Manager | PHP-FPM multi-version (7.4, 8.1, 8.2, 8.3, 8.4) | cPanel "MultiPHP Manager" jaisa |
| Mail | Exim (ya Postfix) + Dovecot + SpamAssassin + ClamAV + OpenDKIM | cPanel ka exact mail stack |
| DNS | BIND (ya PowerDNS) | Zone editor + cluster |
| FTP | Pure-FTPd | Standard |
| Webmail | Roundcube | Free, best |
| DB Admin | phpMyAdmin + Adminer | cPanel jaisa |
| SSL | Let's Encrypt (AutoSSL) + Certbot/ACME | Free SSL auto-renew |
| Security | CSF Firewall + fail2ban + ModSecurity (WAF) + CageFS/jailkit | cPanel+CloudLinux ka equivalent |
| Resource Limits | cgroups v2 + disk quotas + (optional) CloudLinux LVE | "Storage etc." limits — neeche full list |
| Stats | AWStats / GoAccess | cPanel jaisa "Metrics" |
| Backup | Restic/Borg + S3/FTP remote | cPanel Backup Wizard equivalent |

> Agar aapki team PHP me comfortable nahi hai to batao — main Node.js ya Python wala stack bhi दे सकता hoon. Lekin **PHP+Laravel recommend karta hoon** kyunki WHMCS/WHM ecosystem PHP ka hai.

---

## ✅ 7. 100% Feature Parity Checklist (Jo Kuch cPanel Karta Hai — Sab Karenge)

### A. Admin/WHM Side
- [ ] Server setup wizard (hostname, nameservers, shared IP, contact email)
- [ ] Account create / suspend / unsuspend / terminate / modify
- [ ] Hosting Package manager (unlimited + custom limits)
- [ ] Reseller Center (reseller create, limits, privileges, apne accounts)
- [ ] Feature Manager (feature lists — kis package me kaunse icon dikhe)
- [ ] IP Address Manager (shared/dedicated/main IP pool)
- [ ] DNS Cluster / Zone templates (.db default zone file)
- [ ] Service Manager (restart Apache/MySQL/Exim/Dovecot — 1 click)
- [ ] Server Information (load, RAM, disk, Uptime, services status)
- [ ] Mail Queue Manager + Mail Delivery Reports + Email Deliverability
- [ ] MySQL/Mail/DNS per-server config tools
- [ ] Server backup + Restore + Account transfer tool
- [ ] API Tokens management (per-function permissions)
- [ ] Audit log, Login history, Security Center, cPHulk-style brute force protection
- [ ] Notifications/alert system (disk full, service down)
- [ ] Multi-server: Central panel se multiple node servers manage karna
- [ ] Themes/branding: apna logo/colors per-server aur per-reseller

### B. User cPanel Side (Client Panel)
**Files**
- [ ] File Manager (upload, download, zip, extract, edit, chmod, copy, move, delete, search, permissions)
- [ ] Disk Usage viewer, Trash, Directory privacy (password protect folder)
- [ ] FTP Accounts (create/quota/delete) + Anonymous FTP + FTP Session control
- [ ] Git Version Control
- [ ] SSH Access + SSH Keys (jailed shell)
- [ ] Backups (full/home/database/email/forwarder) + Backup Wizard + Restore

**Domains**
- [ ] Addon Domains, Subdomains, Parked/Alias Domains, Redirects
- [ ] DNS Zone Editor (A, AAAA, CNAME, MX, TXT, SRV, SPF, DKIM records)
- [ ] Domain routing, Document root control, Dynamic DNS
- [ ] Domains list + SSL status per domain

**Email**
- [ ] Email Accounts (IMAP/POP/SMTP), mailbox quota, per-mailbox storage
- [ ] Forwarders, Autoresponders, Catch-all, Default Address
- [ ] Email Filters (Sieve) + Global filters
- [ ] Spam filter (per-account), Virus filter (ClamAV)
- [ ] Email Deliverability (SPF/DKIM/DMARC/PTR health check + auto-fix)
- [ ] Mailing Lists (Mailman)
- [ ] Webmail (Roundcube) + Email client config (auto imap/smtp settings)
- [ ] Email Disk Usage + Archive + Import/Export, per-hour sending limit
- [ ] Mailbox storage "jitna cPanel leta hai" — per-mailbox quota bhi

**Databases**
- [ ] MySQL Databases (create/delete/rename), DB Users, privileges, per-DB size limit
- [ ] Remote MySQL access, Access Hosts
- [ ] phpMyAdmin, PostgreSQL (optional), DB backup/restore
- [ ] Current DB usage per database

**Metrics / Stats**
- [ ] Bandwidth usage (per domain, per month), Visitors, Errors logs
- [ ] Raw Access log, Resource Usage (CPU/RAM/IO), Process manager
- [ ] AWStats/Webalizer, Disk usage breakdown chart

**Security**
- [ ] SSL/TLS: AutoSSL, Let's Encrypt, install custom cert, CSR, key manager
- [ ] IP Blocker, Hotlink Protection, Leech Protection
- [ ] ModSecurity WAF toggle, Malware scan (ImunifyAV-style)
- [ ] Two-Factor Authentication, Password policy, Session management
- [ ] Domain/website password protection

**Software / Advanced**
- [ ] MultiPHP Manager (per-domain PHP version), PHP INI editor, PHP extensions
- [ ] **One-click App Installer** (WordPress, Joomla, Magento, Laravel, etc. — Softaculous alternative)
- [ ] WordPress Toolkit (staging, clone, update, security scan)
- [ ] Cron Jobs (visual editor + email output), PHP CLI version
- [ ] Node.js / Python / Ruby app manager (Passenger/Phusion style)
- [ ] MIME Types, Apache Handlers, Error Pages, Indexes
- [ ] Site Publisher (template site), Track DNS, WHM-style shortcut links
- [ ] API Tokens (user-level) + "Login to cPanel" SSO

**Preferences**
- [ ] Profile (name, email, phone), Password change, Language (Hindi included!), Theme, Contact/Notification preferences

### C. Limits / Quotas ("Storage etc. jitna cPanel leta hai")
Har hosting package me ye sab set hoga (cPanel ke exact field names ke saath — taaki billing software ko wahi values dikhein):

| Limit | cPanel Field | Example |
|---|---|---|
| Disk Storage | `QUOTA` (MB) | 10,000 MB = 10 GB |
| Monthly Bandwidth | `BWLIMIT` (MB) | 100,000 MB = 100 GB |
| Email Accounts | `MAXPOP` | 50 |
| Email Forwarders | `MAXFWD` | 50 |
| Autoresponders | `MAXRESP` | 50 |
| Email Filters | `MAXPASS` | 50 |
| Mailing Lists | `MAXLST` | 5 |
| FTP Accounts | `MAXFTP` | 20 |
| MySQL Databases | `MAXSQL` | 25 |
| Subdomains | `MAXSUB` | 25 |
| Parked/Alias Domains | `MAXPARK` | 10 |
| Addon Domains | `MAXADDON` | 10 |
| Cron Jobs | `MAXCRON` | 20 |
| Per-mailbox quota | — | 2 GB |
| Per-DB size limit | — | 1 GB |
| Emails per hour | — | 300 (spam control) |
| Max message size | — | 50 MB |
| Inodes (file count) | — | 2,00,000 |
| Shell access | `HASSHELL` | On/Off |
| CPU / RAM / IO (cgroups) | LVE-style | 100% CPU, 1 GB RAM, 1 MB/s IO |
| Entry processes / NPROC | — | 20 / 100 |
| Dedicated IP | `IP` | On/Off |

---

## 🪜 8. Step-by-Step Plan — 16 Steps, 5 Milestones

> Har Step ke end me: **"Kya ban gaya (Deliverable)"** + **"Aap kaise test karoge"**. 
> Time estimates ek **2–3 developer** ki team ke liye hain (mera AI-assisted coding isse tez kar dega, lekin **testing aur hardening** ka time fix hai).

### 🟦 MILESTONE A — Foundation (Steps 0–2)

#### **STEP 0 — Planning, Lab Setup & Blueprint** (3–5 din)
- **Kya hoga:** Business requirements freeze, server layout decide, dev environment ready
- **Sub-steps:**
  1. Kya kya features Version 1 me honge — finalize (list freeze)
  2. Dev server (VPS: 4 vCPU, 8 GB RAM, AlmaLinux 9) setup
  3. Panel ka DB schema design (users, accounts, packages, domains, mailboxes, dbs, zones, jobs, logs)
  4. Folder structure + coding standards + Git repo
  5. Security rules document (kaun kya kar sakta hai — permission matrix)
  6. Domain + branding (panel ka naam, logo, color theme)
- **Deliverable:** Blueprint document + DB schema diagram + empty project structure + dev server ready
- **Test:** Aap docs review karke approve karoge

#### **STEP 1 — Server Base Stack + Security Foundation** (1–2 hafte)
- **Kya hoga:** Server pe saari hosting services install + secure + auto-start
- **Sub-steps:**
  1. OS hardening (SSH key auth, fail2ban, CSF firewall, kernel settings)
  2. Web: Apache + Nginx (reverse proxy) + PHP-FPM (7.4→8.4)
  3. Mail: Exim + Dovecot + SpamAssassin + ClamAV + OpenDKIM
  4. DB: MariaDB + phpMyAdmin
  5. DNS: BIND (ya PowerDNS) + zone templates
  6. FTP: Pure-FTPd + Jailkit (jailed shell)
  7. Disk quota system enable + cgroups v2 limits
  8. SSL: AutoSSL/Let's Encrypt automation
  9. Panel apna web app deploy karne ki jagah (separate vhost + SSL, jaise WHM)
- **Deliverable:** Ek "perfect server" jo hosting ke liye ready hai + ek script jo naye server pe 1 command me yahi setup kar de
- **Test:** Aap SSH karke `systemctl status` se saari services running dekh sakte ho; ek test domain manually host karke dekh sakte ho

#### **STEP 2 — Panel Core: Auth, Roles, Database, Job Queue** (2–3 hafte)
- **Kya hoga:** Panel ka skeleton — login, users, roles, permissions, background jobs
- **Sub-steps:**
  1. Login system (admin / reseller / user) + 2FA + session security
  2. RBAC (Role Based Access Control) + permission matrix
  3. Panel DB + migrations + models
  4. Redis queue + background worker (heavy tasks async)
  5. **Privileged Agent** (root daemon) + Unix socket + task validation
  6. Audit logging (har action ka record)
  7. Base UI layout: dashboard, sidebar/icon-grid, notifications
  8. CLI tool (`panel` command — jaise cPanel ka `whmapi1`/`uapi`)
- **Deliverable:** Chalne wala panel jisme admin login karke dashboard dekh sakta hai, doosra admin/user bana sakta hai, aur har action log hota hai
- **Test:** Aap login karke roles ke saath khel sakte ho, logs verify kar sakte ho

---

### 🟩 MILESTONE B — Core Hosting Engine (Steps 3–6)

#### **STEP 3 — Provisioning Engine (ACCOUNT CREATE) — Dil Ka Kaam ❤️** (3–4 hafte)
- **Kya hoga:** Billing se order aaya → 60 second me pura hosting account ready
- **Sub-steps:**
  1. Linux user create + home dir `/home/user` + skeleton files
  2. Disk quota apply (user-level) + cgroups limits (CPU/RAM/IO/processes)
  3. Default website structure + placeholder page
  4. DNS zone auto-create (domain ke records)
  5. Mail system me domain add (MX, SPF, DKIM keys generate)
  6. Default FTP user, MySQL access setup
  7. SSL certificate auto-issue
  8. Welcome email (credentials ke saath)
  9. **Suspend / Unsuspend / Terminate** flows (cPanel ke exact behaviour jaise: suspend pe "Account Suspended" page)
  10. Rollback system (agar 5th step fail ho to sab undo ho jaye — koi half-created account nahi)
- **Deliverable:** Ek command/API se poora account ban, band, delete — sab kuch
- **Test:** Aap ek test domain se account banwaoge → browser me website khulegi, mail/DNS/FTP sab live honge

#### **STEP 4 — Hosting Packages & Limits Manager** (1 hafta)
- **Kya hoga:** Section 7-C wali saari limits ka control
- **Sub-steps:**
  1. Package CRUD (create/edit/delete/duplicate) + unlimited options
  2. Har limit ka enforcement (disk, bandwidth, mailboxes, DBs, etc.)
  3. Limit cross hone pe behaviour (email alert, suspend, ya block — cPanel jaisa)
  4. Package assign/change (upgrade/downgrade) + prorated effects
  5. Package templates ready-made (Starter/Business/Pro)
- **Deliverable:** Admin panel se naya package banao → uske saare limits instantly lagu ho
- **Test:** Package bana ke account create karein, limit ke beyond jaane pe system rok de — ye test karenge

#### **STEP 5 — Website Layer: Domains, vhost, PHP, SSL, Cron** (3–4 hafte)
- **Kya hoga:** Customer apni website poori tarah manage kar sake
- **Sub-steps:**
  1. Addon domains / Subdomains / Parked / Redirects (limits ke saath)
  2. vhost template engine (Apache + Nginx) + Document Root control
  3. **MultiPHP Manager** (per-domain PHP version switch, PHP INI editor)
  4. AutoSSL + Let's Encrypt + custom SSL install + CSR generator
  5. Redirects, Error Pages, MIME Types, Apache Handlers, Indexes
  6. **Cron Job manager** (visual + email output)
  7. Node.js / Python app manager (optional V1, V2 me pakka)
  8. Per-site logs + error log viewer
- **Deliverable:** Customer panel me "Domains" aur "Software" section fully working
- **Test:** 2 domain add karke, ek pe PHP 8.3 aur doosre pe 8.1 chalayein, SSL auto lag jaye

#### **STEP 6 — File Manager + FTP + Git + SSH** (2–3 hafte)
- **Kya hoga:** cPanel ka famous File Manager — apna version
- **Sub-steps:**
  1. File Manager UI (upload [multi + drag-drop], download, rename, copy, move, delete, extract zip/tar, compress)
  2. Code editor (syntax highlighting) + file permissions (chmod) + ownership safety
  3. Disk Usage viewer (folder-wise breakdown)
  4. FTP accounts (create/quota/delete) + FTP session control + Anonymous FTP
  5. Directory privacy (password protect folder), Leech protection
  6. SSH access + SSH keys + jailed shell
  7. Git Version Control (clone/deploy)
  8. Trash system (accidental delete se bachao)
- **Deliverable:** Poora file management system browser se chalega
- **Test:** Aap 500 MB zip upload karke extract karoge, FTP se connect karoge, WordPress manually install karoge

---

### 🟨 MILESTONE C — Communication & Data (Steps 7–9)

#### **STEP 7 — Email Suite (Sabse Bada Module)** (3–5 hafte)
- **Kya hoga:** Full email hosting — jaise cPanel me hota hai
- **Sub-steps:**
  1. Email accounts create/delete + **per-mailbox quota** (storage!)
  2. Webmail (Roundcube) + auto-config for Outlook/Thunderbird/mobile
  3. Forwarders, Autoresponders, Catch-all, Default address
  4. Email Filters (Sieve) — per user + global
  5. Spam filter (SpamAssassin) + Virus scan (ClamAV) + Spam score settings
  6. **Email Deliverability** — SPF/DKIM/DMARC auto-setup + health check + PTR guidance
  7. Mailing Lists (Mailman ya apna simple list manager)
  8. Email Disk Usage + Mailbox archive + Import/Export
  9. Per-hour sending limit + outgoing mail suspend (spam abuse rokne ke liye)
  10. Mail queue manager (admin) + Mail delivery reports (kis email ka kya hua)
  11. Webmail SSO + Email client (IMAP/POP/SMTP) settings page
- **Deliverable:** Customer apna domain email — Gmail jaisa — chala sake
- **Test:** Mail bhejo-receive karo, forwarder/autoresponder test karo, spam filter test karo, phone me IMAP set karo

#### **STEP 8 — Database Management** (1–2 hafte)
- **Kya hoga:** MySQL full control
- **Sub-steps:**
  1. Database create/delete/rename + **per-DB size limit** + DB usage display
  2. DB users + privileges + password change
  3. Remote MySQL + Access Hosts
  4. phpMyAdmin + Adminer integration (SSO)
  5. DB backup/restore (per-database, downloadable .sql.gz)
  6. PostgreSQL support (optional, V2)
- **Deliverable:** Customer apna database bana ke WordPress chalaye
- **Test:** DB banao → user banao → WordPress install → DB size limit exceed karke dekho

#### **STEP 9 — DNS Management** (1.5–2 hafte)
- **Kya hoga:** Full DNS control + nameserver setup
- **Sub-steps:**
  1. Zone editor UI (A, AAAA, CNAME, MX, TXT, SRV, CAA, SPF, DKIM, DMARC records — add/edit/delete)
  2. Default zone templates (naya account bane to auto records)
  3. Records validation + propagation check tool
  4. Private nameservers (`ns1.yourdomain.com`) setup guide + glue records
  5. DNS cluster (2+ servers me zone sync — redundancy ke liye)
  6. DNSSEC (optional V2)
  7. Reverse DNS (PTR) tracking + "Track DNS" tool
- **Deliverable:** Aap khud nameserver chala sako — customer domain ke records manage kare
- **Test:** Zone add karke `dig` se verify karein, DNS cluster sync test karein

---

### 🟧 MILESTONE D — Ops, Scale & Business (Steps 10–12)

#### **STEP 10 — Backup, Restore & Migration** (2–3 hafte)
- **Kya hoga:** Data kabhi na khoye + cPanel se migration
- **Sub-steps:**
  1. Scheduled backups (daily/weekly/monthly) — full account / files / DB / email
  2. Remote backup destinations: S3-compatible, FTP, SFTP, Google Drive
  3. Restore wizard (single file, single DB, poora account, ya point-in-time)
  4. Per-account + per-reseller + server-level backup policies
  5. Backup size quota + retention (kitne din purane backup rakhne hain)
  6. **cPanel → our panel Migration Tool:** cPanel full backup (.tar.gz) import karke account restore karna (ye bahut bada selling point hai — customers aapke panel pe shift karne ke liye)
- **Deliverable:** One-click backup/restore + cPanel backup import
- **Test:** Backup lo → file delete karo → restore karo → wapas aa jaye. cPanel backup file import karke dekhna

#### **STEP 11 — Monitoring, Stats & Resource Limits** (2–3 hafte)
- **Kya hoga:** Server health + customer ka usage reporting (billing ke liye zaroori!)
- **Sub-steps:**
  1. Bandwidth usage calculation (per domain, per month) — `showbw` API ke liye data
  2. Disk usage reporting + inode count
  3. Resource usage graphs (CPU/RAM/IO/processes per account)
  4. Process manager (customer apne bhagte process dekh/dekh sake)
  5. Server monitoring dashboard (load, RAM, disk, services, mail queue, MySQL status)
  6. Alerts: disk 90% full, service down, backup fail, spam spike
  7. Logs: access logs, error logs, mail logs, auth logs (retention policy)
  8. AWStats / GoAccess website statistics
  9. Abuse detection: high resource user, outgoing spam, high I/O
- **Deliverable:** Admin dashboard + customer "Metrics" section + alerts
- **Test:** Ek account pe load daalo (stress test) → dashboard pe dikhe aur limit lage

#### **STEP 12 — 💳 BILLING INTEGRATION & API COMPATIBILITY LAYER** (2–3 hafte) ⭐ MOST IMPORTANT
- **Kya hoga:** Aapka billing software → panel → hosting account — sab automatic
- **Sub-steps:**
  1. **WHM API 1 compatible endpoints** (Section 5.1 wali puri list) — same JSON format, same ports (2086/2087)
  2. Auth: password + **API Tokens with per-function permissions** (bilkul cPanel jaisa)
  3. `createacct` → Step 3 ka provisioning engine (instant account)
  4. `suspendacct`/`removeacct` → invoice unpaid pe auto suspend, 30 din baad terminate
  5. `changepackage`/`editquota` → plan upgrade/downgrade
  6. `showbw`/`listaccts`/`accountsummary` → billing "Usage Updates" ke liye
  7. `create_user_session` → "Login to cPanel" button (billing → panel SSO)
  8. **cPanel UAPI compatible endpoints** (2082/2083) — custom scripts/plugins ke liye
  9. **Test Connection** endpoints (WHMCS ka Test Connection button kaam kare)
  10. Webhooks/Events (native): account.created, account.suspended, quota.exceeded, payment failed → aapke system ko notify
  11. **Agar aapka billing custom hai:** uska plugin/module likhna jo hamare native REST API se baat kare
  12. Documentation: Postman collection + API docs (developer ke liye)
- **Deliverable:** WHMCS (ya aapka billing) me server add karo → product banao → order aaye → account automatic ban jaye. Suspend/terminate bhi automatic.
- **Test:** WHMCS me real order place karke end-to-end test — including "Login to cPanel" button aur usage sync

---

### 🟪 MILESTONE E — Polish, Parity & Launch (Steps 13–15)

#### **STEP 13 — Security Suite + WAF** (2–3 hafte)
- ModSecurity WAF + rules manager (per-account toggle)
- Malware/antivirus scan (ImunifyAV-style) + quarantine + cleaning
- Brute-force protection (cPHulk-style): login attempts tracking, IP auto-block
- IP Blocker, Hotlink protection, Leech protection
- Comprehensive 2FA (TOTP + backup codes) admin/reseller/user sabke liye
- Password policy engine + password strength force
- Session manager (active sessions, "logout all devices")
- SSL/TLS manager advanced: cipher config, HSTS, OCSP, force HTTPS
- Security advisories + auto-updates for panel & services
- Penetration test checklist + fix
- **Deliverable:** Security Center page + reporting dashboard

#### **STEP 14 — One-Click App Installer + WordPress Toolkit** (2–3 hafte)
- 50+ apps one-click install (WordPress, WooCommerce, Joomla, Drupal, Laravel, Magento, PrestaShop, Nextcloud, phpBB, Moodle...)
- Auto-update + backup-before-update
- **WordPress Toolkit:** staging sites, clone, security scan, plugin/theme update bulk, core version management
- Installer pricing/licensing (free/paid apps list)
- **Deliverable:** Customer 60 second me WordPress install kar sake
- **Test:** 3 alag apps install karke chalayein

#### **STEP 15 — Reseller Panel + Multi-Server + Final Parity & Launch** (3–4 hafte)
- **Reseller features:** apne packages banana (white-label), apne customers create karna, apna branding/logo, reseller-level limits, apna DNS, apni billing ke saath integrate
- **Multi-server:** Central panel se 2nd/3rd server add karna (account kisi bhi node pe create ho), server health, DNS cluster
- **Parity audit:** cPanel ki feature list ke saath line-by-line comparison (kya bacha) → final gap closing
- **Performance tuning:** panel speed, 1000 accounts load test
- **Documentation:** Admin manual, customer knowledgebase, video tutorials
- **Launch:** Production deployment + monitoring + on-call process
- **Deliverable:** Production-ready panel + docs + support process

---

### 📊 Step-wise Time Summary

| Milestone | Steps | Time (2–3 dev team) |
|---|---|---|
| A. Foundation | 0–2 | ~4–6 hafte |
| B. Core Hosting | 3–6 | ~9–13 hafte |
| C. Communication & Data | 7–9 | ~6–9 hafte |
| D. Ops & Business | 10–12 | ~6–9 hafte |
| E. Polish & Launch | 13–15 | ~7–10 hafte |
| **TOTAL (100% parity)** | **16 steps** | **~32–47 hafte (8–12 mahine)** |
| **MVP (Step 0–12)** | 13 steps | **~25–37 hafte (6–9 mahine)** |

> **Mera AI-assisted development** isko 30–40% tez kar sakta hai (code likhne me), lekin **testing, security audit aur real-world stabilization** ka time kam nahi hota. Isliye main honest estimate de raha hoon — jhuthi 1-month ki promise nahi.

---

## 🔐 9. Security Plan (Ye Hissa Optional Nahi Hai!)

Ek hosting panel = server ka **master key**. Agar panel me ek bug hua to 500 customers ka data gaya, aur aapka business khatam. Isliye:

1. **Panel kabhi root me nahi chalega** — sirf restricted agent root tasks karega
2. **SQL Injection / Command Injection / Path Traversal / XSS / CSRF** — har function pe defense
3. **Har input validation** — koi bhi user input kabhi shell me direct nahi jayega
4. **Rate limiting** — brute force aur API abuse rokna
5. **Staging environment** — production pe direct code push nahi
6. **Code review + security audit** — har milestone ke baad
7. **Penetration testing** — launch se pehle (3rd party bhi karwana recommend hai)
8. **Backup + disaster recovery drill** — mahine me ek baar
9. **Least privilege** — har service apne dedicated user me, jaise `mailnull`, `mysql`
10. **Audit trail** — koi bhi action bina log ke nahi

---

## 👥 10. Team, Cost & Risks

**Recommended Team (minimum):**
| Role | Kaam | Kitne |
|---|---|---|
| Backend Dev (PHP/Laravel + Linux) | Panel core, provisioning, API | 1–2 |
| Frontend Dev (React) | cPanel-jaisa UI | 1 |
| Linux Sysadmin/DevOps | Server stack, security, monitoring | 1 (part-time chalega start me) |
| QA / Tester | Har step ka testing | 1 (part-time) |

**Rough Cost (India):** ₹60,000 – ₹1,50,000 per developer per month (experience ke hisaab se). 3 logon ki team = **~₹2–4 lakh/month**, 8–12 mahine = **~₹20–45 lakh** (in-house). Outsourcing/Freelance me alag ranges honge.
**Servers:** Dev VPS (~₹1,500–3,000/month), production servers (₹3,000–10,000/month/server), backup storage (S3 ~₹500–2,000/month).

**Top 5 Risks aur Planning:**
| Risk | Solution |
|---|---|
| Security breach | Step 13 + pen-test + restricted agent architecture |
| Scope creep ("ye bhi add karo") | Step 0 me feature freeze + version-wise roadmap |
| Email deliverability (IP blacklist) | Step 7 me proper DKIM/SPF/DMARC/rDNS + warm-up plan |
| Customer support burden | Step 15 me docs + KB + ticket templates |
| cPanel brands se confusion | Apna alag naam/branding — clear positioning |

---

## 📋 11. Aapko Kya Ready Rakhna Hoga (Checklist)

- [ ] **Billing software ka naam** aur version (WHMCS? Blesta? Clientexec? custom?)
- [ ] Billing software ka **admin access** (test ke liye) — baad me
- [ ] **Dev server:** VPS 4 vCPU / 8 GB RAM / 100 GB SSD, AlmaLinux 9 (main setup script dunga)
- [ ] **Ek test domain** + uske nameserver control (testing ke liye)
- [ ] **Branding:** panel ka naam, logo (ya main ek placeholder bana dunga)
- [ ] **Pricing plans** (Starter/Business/Pro — kitna storage, kitne emails, etc.)
- [ ] Confirm: Reseller hosting bechna hai ya nahi? Multi-server chahiye ya single se start?
- [ ] Team hai ya aap khud (ya main + aap) karoge?

---

## 🤝 12. Hum Kaise Kaam Karenge (Process)

1. **Aap bologe:** "Step 0 chalu karo" → main us step ka **actual code + config + files** workspace me bana dunga
2. **Aap test karoge** → jo issue aayega bataoge → main fix karunga
3. **Step complete** → next step pe jayenge
4. Har step me aapko milega: **code files + install script + test checklist + documentation**
5. Aap kisi bhi step pe ruk ke **sawaal puchh sakte ho** — main guide karta rahunga
6. **Order badal sakte hain** — agar aapko pehle billing integration chahiye, ya pehle email module, to batao — main reorder kar dunga (lekin Step 0–3 skip nahi kar sakte, wo foundation hai)

---

## ❓ 13. Sawaal / Jawab (FAQ)

**Q: Kya hum cPanel ka exact copy bana sakte hain?**
A: Functionality aur API 100% compatible bana sakte hain (legal), lekin cPanel ka naam/logo/design assets copy nahi kar sakte. Apna brand hoga — jaise "HostPanel", "AlphaCP" etc.

**Q: Kitne mahine me ready hoga?**
A: MVP (business start karne layak) = 6–9 mahine, 100% parity = 8–12 mahine, 2–3 dev team ke saath.

**Q: Kya ek banda kar sakta hai?**
A: Kar sakta hai but 18–30 mahine lag sakte hain. Mera suggestion: 1 backend dev + 1 sysadmin hire karo, main coding speed bahut badha dunga.

**Q: Ye panel kya kya "leta hai" — poora list?**
A: Section 7-C dekho — Disk, Bandwidth, Email accounts, Mailbox size, DBs, Subdomains, Addon/Parked domains, FTP, Cron, Inodes, CPU/RAM/IO, per-hour mail limit, message size — sab kuch cPanel jaisa configurable hoga.

**Q: Billing software badalna padega?**
A: Nahi. Hum cPanel jaisa API bana rahe hain — aapka mojooda billing waisa hi kaam karega. Sirf panel ka naam change karke API token daalna hoga.

**Q: Kya ye turant production me use kar sakte hain?**
A: Nahi — pehle Steps 0–12 complete karenge, phir 2–4 hafte **beta testing** (kuch friendly customers pe), phir production. Aapki reputation security pe depend karti hai.

---

## 🎯 Next Action (Aapke Liye)

1. Neeche diye gaye sawaalon ke jawab do (main puchh raha hoon)
2. Phir bolo **"Step 0 chalu karo"** → main blueprint + DB schema + project structure se shuru karunga
3. Ya agar aapko koi specific step pehle chahiye (jaise billing integration pehle) — batao, main plan adjust kar dunga

---
---

## ✅ 14. DECISIONS LOCKED (28 Sep 2026 ko final)

| Sawaal | Aapka Decision | Iska Matlab |
|---|---|---|
| Billing software | **Khud ka / custom billing** | Hum apna **native REST API + webhooks** denge, aur saath me **WHM API 1 compatible layer** bhi (future-proof: kal WHMCS pe shift karo to zero change) |
| Team | **Aap khud + main (AI)** | Main code, config, scripts, docs sab likhunga — aap test karoge, decisions loge, server manage karoge |
| Scope | **100% cPanel parity (saare 16 steps)** | Reseller + multi-server + app installer sab included |
| Dev server | **Oracle Cloud Free VPS** | Development/testing ke liye — neeche zaroori warnings padho ⚠️ |
| Install | **One-click installer chahiye (cPanel jaisa)** | Haan, bilkul possible — neeche Section 15 dekho |
| Route (28 Sep, update) | **100% Custom (Route B) LOCKED** ✅ | Koi fork nahi, koi shortcut nahi — pura apna panel scratch se. Time ki koi jaldi nahi |
| Customers | **India + Global (dono)** | Primary server India DC (Mumbai/Delhi/Noida), 2nd node baad me EU (Hetzner) — multi-server Step 15 |
| Kaam ka batwara | Roles + dono server paths ka full playbook alag file me | Dekho: `kaam-ka-batwara-aur-server-playbook.md` |

### Revised Timeline (Solo + AI-assisted)
| Phase | Kaam | Realistic Time |
|---|---|---|
| Steps 0–3 (Foundation + Account Engine) | Blueprint, server stack, panel core, provisioning | 6–8 hafte |
| Steps 4–9 (Hosting + Mail + DB + DNS) | Saare customer-facing modules | 14–20 hafte |
| Steps 10–12 (Backup + Monitoring + Billing API) | Ops + billing integration | 6–9 hafte |
| **MVP COMPLETE (business launch)** | **Steps 0–12 done** | **~6–8 mahine** |
| Steps 13–15 (Security + Installer + Reseller + Launch) | Full parity + polish | +6–10 mahine |
| **100% PARITY** | Sab kuch | **~12–18 mahine** |

> Solo me sabse bada risk = **burnout + testing ka time**. Isliye main code ko chhote, testable pieces me dunga aur har step ka test checklist bhi dunga.

---

## ⚠️ 15. Oracle Cloud VPS — Zaroori Baatein (Research ke baad)

### 15.1 Aapka server (Oracle Ampere A1 — ARM64)

| Point | Detail |
|---|---|
| **Architecture** | Oracle ka free VPS = **ARM64 (aarch64)**, x86 nahi |
| **cPanel khud yahan nahi chalega** | cPanel officially **sirf x86_64** support karta hai — ARM pe install hi nahi hoga |
| **Hamara panel chalega?** | ✅ **Haan** — Nginx/Apache, PHP-FPM, MariaDB, Exim, Dovecot, BIND, Pure-FTPd — sab ARM64 pe available hain. Hum installer dono architecture (x86_64 + ARM64) ke liye banayenge |
| **ARM pe kya nahi milega** | LiteSpeed Enterprise, CloudLinux, Imunify360 (ye commercial tools ARM support nahi karte) → inke free alternatives: Nginx (LiteSpeed ki jagah), cgroups v2 (CloudLinux LVE ki jagah), ClamAV + apna scanner (ImunifyAV ki jagah) |

### 15.2 Oracle Free Tier ki 2 Bad News (June 2026 me badla hai) 🔴

1. **Quota aadha kar diya gaya:** 15 June 2026 se Oracle ne Always Free Ampere A1 limit **4 OCPU/24 GB se 2 OCPU/12 GB** kar diya hai. Agar aapka instance 4/24 me chal raha hai to chalega, **lekin agar wo terminate/reclaim ho gaya to 4/24 dobara nahi mil sakta** (sirf 2/12 milega) — jab tak account PAYG me upgrade na karo.
2. **Idle reclamation:** Oracle **idle instance ko band kar sakta hai** — agar 7 din tak CPU, network aur memory usage 20% se kam rahe. Dev server mostly idle rehta hai → **reclaim ka risk real hai**.

### 15.3 Meri Recommendation
- ✅ Oracle VPS ko **development/testing server** ki tarah use karo (panel UI, API testing, code likhna) — free hai to perfect hai
- ✅ **Production ke liye paid VPS lo** jab MVP ready ho (kyunki free instance ka business-critical data pe bharosa risky hai) — jaise Hetzner / Contabo / DigitalOcean / Linode / ya Indian providers (E2E Networks, MilesWeb VPS, Hostinger VPS)
- ✅ Production standard: **x86_64 + AlmaLinux 9** (kuch bhi missing nahi hoga)
- 💡 Extra safety: Oracle pe ek chhota cron job rakhna (har 10 min CPU spike) taaki idle-reclaim na ho — main setup me bata dunga

---

## 📦 16. One-Click Installer — Haan, Bilkul Possible Hai! 🎯

cPanel aise install hota hai:
```bash
cd /home && curl -o latest -L https://securedownloads.cpanel.net/latest && sh latest
```
Bas ek command → 40-60 min me pura server ready. **Hamara bhi bilkul aisa hi hoga:**

```bash
# Aapke fresh VPS pe (root me) sirf itna:
curl -sSL https://install.alphacp.in | bash
```

### Installer ye sab automatic karega (Step 1 ka kaam)
1. OS check (AlmaLinux 9 / Rocky / Ubuntu LTS + architecture detect)
2. Saare packages install: Apache, Nginx, PHP-FPM (multi-version), MariaDB, Exim, Dovecot, ClamAV, SpamAssassin, BIND, Pure-FTPd, Redis
3. Security setup: firewall (CSF/firewalld), fail2ban, SELinux config, kernel tuning
4. SSL: Let's Encrypt auto-setup
5. Panel install: database banayega, admin account banayega, panel SSL lagayega
6. Nameserver setup: ns1/ns2 ke liye guide + zone templates
7. Post-install report: kya-kya install hua, kahan login karna hai, next steps

### Installer ke forms (aage badhate hue)
| Version | Kis liye | Command |
|---|---|---|
| Normal | Naya server setup | `curl -sSL https://install.alphacp.in \| bash` |
| Silent/auto | Billing ke saath auto-deploy, ya bulk servers | `install.sh --auto --hostname=srv1.example.com --ns1=... --email=...` |
| Update | Panel update | `alphacp update` |
| Node | 2nd/3rd server ko cluster me jodna | `alphacp join --central=panel.example.com --token=XYZ` |
| Repair | Kuch toota? | `alphacp doctor` (sab services check + fix) |

**Development me install kaise hoga:** Step 1 se hi hum ye installer script banayenge — matlab jab tak Step 12 khatam hoga, aapke paas ek **production-grade one-command installer** ready hoga. Aur testing ke liye aap Oracle VPS pe fresh install karke test kar sakoge: `bash install.sh` → 30-60 min → panel live. 🎉

---

## 🎬 17. Demo Preview

`panel-demo-preview.html` file workspace me hai — usme:
1. **WHM Admin** — server status, accounts table, quick actions
2. **cPanel User** — 48 tools ka icon grid + live stats (disk/bandwidth/email limits)
3. **Webmail** — Roundcube-jaisa UI
4. **Billing / API** — aapke custom billing software ke 12 API calls (request + response + flow) + live provisioning demo

Kisi bhi tool icon pe click karo → uska plan khulega (kaunsa Step me banega + kya kya features honge).

---
*Ye document aapke saath update hota rahega — har step complete hone pe main isme tick mark aur progress add karta rahunga.*
