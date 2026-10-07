# 09 — cPanel Parity Checklist (हमारा अनुबंध)

> **Ye file hamara likhit vaada hai.** Aapne kaha: *"cPanel jaisa ek ek choti se choti, badi se badi cheez"* —
> to yahi file uska proof hai. cPanel & WHM ki **har ek tool** yahan listed hai. Koi cheez hataayi nahi jayegi.
> Jo step poora hoga, uske items ✅ ho jaayenge. **Project tab complete maana jayega jab ye file 100% ✅ ho.**

**Banaya:** 28 Sep 2026 · **Base:** cPanel 138 (Meridian, Jul 2026) + WHM full tool list
**Updated:** har step ke baad (Step 0, 1, 2A ab tak)

---

## 0. Status legend

| Symbol | Matlab |
|---|---|
| ✅ | Ban gaya aur test ho gaya |
| 🟡 | Chal raha hai (is step me ban raha hai) |
| ⏳ S3 | Planned — Step 3 me aayega (har row me step number likha hai) |
| 🔵 | Optional/paid add-on (cPanel me bhi alag service hai) |

## 1. Parity ke 5 niyam (jo hum todenge nahi)

1. **Naam wahi rahenge** — File Manager, Zone Editor, cPHulk jaisi jagah… (generic descriptive naam
   waise hi rakhenge jo customer pehchanta hai). Sirf **cPanel/WHM brand, logo, text copy nahi** karenge.
2. **Menu wahi** — wahi sections (Files, Email, Domains, Databases, Metrics, Security, Software, Advanced, Preferences)
   aur WHM ki wahi categories. Customer ko naya seekhna nahi padega.
3. **Limits wahi** — packages me wahi keys (QUOTA, BWLIMIT, MAXPOP, MAXFTP, MAXSQL, MAXSUB, MAXPARK,
   MAXADDON, MAXCRON, MAXINODE, MAILBOXQUOTA, DBQUOTA, MAXEMAILPERHOUR, CPULIMIT, NPROCLIMIT…)
4. **API wahi** — WHM API 1 ke wahi function naam aur wahi JSON shape (WHMCS/Blesta/Clientexec bina badlav chalega).
5. **Default behaviour wahi** — jaise account create par home dir + public_html, docroot, vhost, DNS zone,
   mail routing — sab waise hi banenge jaisa cPanel banata hai.

---

## 2. CLIENT PANEL (cPanel ke saare tools)

### 📁 Files

| # | cPanel tool | Kya karta hai | Humara step | Status |
|---|---|---|---|---|
| 1 | File Manager | Upload/edit/delete/zip/permissions | S6 | ⏳ S6 |
| 2 | Images | Resize/convert images | S6 | ⏳ S6 |
| 3 | Directory Privacy | Password-protected folders | S6 | ⏳ S6 |
| 4 | Disk Usage | Folder-wise space | S6 | ⏳ S6 |
| 5 | Web Disk | WebDAV drive | S6 | ⏳ S6 |
| 6 | FTP Accounts | FTP users | S6 | ⏳ S6 |
| 7 | FTP Connections | FTP session logs | S6 | ⏳ S6 |
| 8 | Backup | Manual backup download | S10 | ⏳ S10 |
| 9 | Backup Wizard | Step-by-step backup/restore | S10 | ⏳ S10 |
| 10 | File & Directory Restoration | Deleted file wapas | S10 | ⏳ S10 |
| 11 | Git™ Version Control | Git deploy/repo | S6 | ⏳ S6 |
| 12 | Trash | File Manager trash bin | S6 | ⏳ S6 |

### 📧 Email

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 13 | Email Accounts | Mailboxes + quota | S7 | ⏳ S7 |
| 14 | Forwarders | Email forward | S7 | ⏳ S7 |
| 15 | Email Routing | MX/local routing per domain | S7 | ⏳ S7 |
| 16 | Autoresponders | Vacation/auto reply | S7 | ⏳ S7 |
| 17 | Default Address | Catch-all | S7 | ⏳ S7 |
| 18 | Mailing Lists | Mailman lists | S7 | ⏳ S7 |
| 19 | Track Delivery | Delivery trace | S7 | ⏳ S7 |
| 20 | Global Email Filters | Server-side filters | S7 | ⏳ S7 |
| 21 | Email Filters | Per-mailbox filters | S7 | ⏳ S7 |
| 22 | Email Deliverability | SPF/DKIM/DMARC + fix buttons | S7 | ⏳ S7 |
| 23 | Address Importer | Bulk CSV import | S7 | ⏳ S7 |
| 24 | Spam Filters (SpamAssassin) | Spam scoring + blacklist | S7 | ⏳ S7 |
| 25 | Encryption | PGP/GnuPG email keys | S7 | ⏳ S7 |
| 26 | BoxTrapper | Challenge-response anti-spam | S7 | ⏳ S7 |
| 27 | Calendar & Contacts | CalDAV/CardDAV + web app | S7 | ⏳ S7 |
| 28 | Email Disk Usage | Per-folder mail space, purge | S7 | ⏳ S7 |
| 29 | Webmail | Roundcube/Horde link | S7 | ⏳ S7 |

### 🌐 Domains

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 30 | Domains | Overview + actions | S5 | ⏳ S5 |
| 31 | Subdomains | sub.domain.com | S5 | ⏳ S5 |
| 32 | Addon Domains | Extra domain, alag site | S5 | ⏳ S5 |
| 33 | Aliases (Parked) | Domain aliases | S5 | ⏳ S5 |
| 34 | Redirects | 301/302 redirect | S5 | ⏳ S5 |
| 35 | Zone Editor | A/CNAME/MX/TXT/… records | S9 | ⏳ S9 |
| 36 | Dynamic DNS | Dynamic IP clients | S9 | ⏳ S9 |

### 🗄️ Databases

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 37 | MySQL® Databases | DB + users + privileges | S8 | ⏳ S8 |
| 38 | MySQL Database Wizard | Step-by-step DB setup | S8 | ⏳ S8 |
| 39 | phpMyAdmin | DB GUI (SSO login) | S8 | ⏳ S8 |
| 40 | Remote MySQL | Remote access hosts | S8 | ⏳ S8 |
| 41 | PostgreSQL Databases | 🔵 optional module | post-v1 | 🔵 |
| 42 | PostgreSQL Wizard | 🔵 optional module | post-v1 | 🔵 |

### 📊 Metrics

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 43 | Visitors | Latest Apache access log | S11 | ⏳ S11 |
| 44 | Errors | Latest error log | S11 | ⏳ S11 |
| 45 | Bandwidth | Monthly bandwidth | S11 | ⏳ S11 |
| 46 | Raw Access | Download raw logs | S11 | ⏳ S11 |
| 47 | Awstats | Full stats (geo, browsers) | S11 | ⏳ S11 |
| 48 | Webalizer | Second stats engine | S11 | ⏳ S11 |
| 49 | Webalizer FTP | FTP stats | S11 | ⏳ S11 |
| 50 | Analog Stats | Third stats engine | S11 | ⏳ S11 |
| 51 | Metrics Editor | Kaunse stats chalein | S11 | ⏳ S11 |
| 52 | Site Quality Monitoring | Uptime/perf checks (2026 naya) | S11 | ⏳ S11 |
| 53 | Resource Usage (CPU/RAM/IO) | Daily process log style | S11 | ⏳ S11 |

### 🔒 Security

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 54 | SSH Access | Keys + shell access control | S6 | ⏳ S6 |
| 55 | IP Blocker | IP/range block | S13 | ⏳ S13 |
| 56 | SSL/TLS | CSR, cert install, keys | S5 | ⏳ S5 |
| 57 | SSL/TLS Status | Sab domains ka SSL ek table | S5 | ⏳ S5 |
| 58 | Two-Factor Authentication | TOTP 2FA |S2B-2|✅|
| 59 | Password & Security | Password change + strength |S2B|✅|
| 60 | Leech Protection | Password vs hotlink abusers | S13 | ⏳ S13 |
| 61 | ModSecurity (WAF) | Per-account WAF on/off | S13 | ⏳ S13 |
| 62 | Security Policy | Account-level policy | S13 | ⏳ S13 |

### 🧩 Software

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 63 | **Softaculous-style App Installer** | 50+ apps one-click (WordPress…) | S14 | ⏳ S14 |
| 64 | WordPress Toolkit | Staging/clone/scan/update | S14 | ⏳ S14 |
| 65 | WP Guardian-style security | Malware scan + vuln patch | S14 | ⏳ S14 |
| 66 | Node.js® Selector | Node apps + npm | S14 | ⏳ S14 |
| 67 | Optimize Website | Gzip/deflate | S14 | ⏳ S14 |
| 68 | MultiPHP Manager | Per-domain PHP version | S5 | ⏳ S5 |
| 69 | MultiPHP INI Editor | Per-domain php.ini | S5 | ⏳ S5 |
| 70 | PHP Composer | Composer in panel | S14 | ⏳ S14 |
| 71 | PHP PEAR Packages | 🔵 legacy | post-v1 | 🔵 |
| 72 | Ruby Gems | 🔵 legacy | post-v1 | 🔵 |
| 73 | Perl Modules | 🔵 legacy | post-v1 | 🔵 |
| 74 | Python Selector | 🔵 optional module | post-v1 | 🔵 |
| 75 | Application Manager | Node/Python app manager | S14 | ⏳ S14 |

### ⚙️ Advanced

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 76 | Cron Jobs | Scheduled tasks | S5 | ⏳ S5 |
| 77 | Track DNS | DNS trace/debug | S9 | ⏳ S9 |
| 78 | Indexes | Directory listing control | S5 | ⏳ S5 |
| 79 | Error Pages | Custom 404/500 etc. | S5 | ⏳ S5 |
| 80 | MIME Types | Custom MIME | S5 | ⏳ S5 |
| 81 | Apache Handlers | Custom handlers | S5 | ⏳ S5 |
| 82 | Network Tools | Ping/traceroute/lookup | S11 | ⏳ S11 |
| 83 | Terminal | Browser SSH (jailed) | S6 | ⏳ S6 |
| 84 | Hotlink Protection | Image hotlink block | S13 | ⏳ S13 |
| 85 | Site IP Address | Account IP info | S3 | ⏳ S3 |

### 👤 Preferences

| # | cPanel tool | Kya karta hai | Step | Status |
|---|---|---|---|---|
| 86 | Getting Started Wizard | Pehli setup guidance |S2B|🟡 2B-1|
| 87 | Video Tutorials | Help videos | S2B | 🟡 2B |
| 88 | Change Language | Multi-language | S2B | 🟡 2B |
| 89 | Change Style | Theme/colors, dark mode | S2B | 🟡 2B |
| 90 | Change Password | Password update | S2B | ✅ |
| 91 | Contact Information | Email + alerts |S2B|🟡 2B-2|
| 92 | User Manager | Sub-users + roles |S2B|🟡 2B-2|
| 93 | Shortcuts / Favorites | Quick links | S2B | 🟡 2B |

---

## 3. ADMIN + RESELLER PANEL (WHM ke saare tools)

### Server Configuration
| # | WHM tool | Step | Status |
|---|---|---|---|
| 94 | Basic Setup (hostname, contact, nameservers) | S2B |S2B|🟡 2B-1| 95 | Tweak Settings (hundreds of options) | S13 | ⏳ S13 |
| 96 | Change Root Password | S2B | 🟡 2B |
| 97 | Configure cPanel Cron Jobs (panel tasks) | S2B | 🟡 2B |
| 98 | Initial Quota Setup | S1 | ✅ |
| 99 | Server Profile | S15 | ⏳ S15 |
| 100 | Server Time (NTP) | S11 | ⏳ S11 |
| 101 | Statistics Software Configuration | S11 | ⏳ S11 |
| 102 | Terminal (root, audited) | S6 | ⏳ S6 |
| 103 | Update Preferences (panel updates) | S15 | ⏳ S15 |

### Account Functions / Information
| # | WHM tool | Step | Status |
|---|---|---|---|
| 104 | Create a New Account | S3 | ⏳ S3 |
| 105 | List Accounts | S3 | ⏳ S3 |
| 106 | Modify an Account | S3 | ⏳ S3 |
| 107 | Suspend / Unsuspend (Manage Account Suspension) | S3 | ⏳ S3 |
| 108 | Terminate Accounts | S3 | ⏳ S3 |
| 109 | Upgrade / Downgrade an Account | S4 | ⏳ S4 |
| 110 | Quota Modification | S4 | ⏳ S4 |
| 111 | Password Modification + Force Password Change | S3 | ⏳ S3 |
| 112 | Change Site's IP Address | S3 | ⏳ S3 |
| 113 | Rearrange an Account | S15 | ⏳ S15 |
| 114 | Limit/Reset Bandwidth Usage + Unsuspend Bandwidth Exceeders | S11 | ⏳ S11 |
| 115 | Manage Shell Access | S6 | ⏳ S6 |
| 116 | Raw Apache / NGINX Log Download | S11 | ⏳ S11 |
| 117 | Email All Users | S11 | ⏳ S11 |
| 118 | Web Template Editor | S5 | ⏳ S5 |
| 119 | List Parked Domains / List Subdomains | S5 | ⏳ S5 |
| 120 | List Suspended Accounts / Show Accounts Over Quota | S3 | ⏳ S3 |
| 121 | View Bandwidth Usage | S11 | ⏳ S11 |
| 122 | Manage Demo Mode | S15 | ⏳ S15 |

### Packages & Features
| # | WHM tool | Step | Status |
|---|---|---|---|
| 123 | Add / Edit / Delete a Package | S4 | ⏳ S4 |
| 124 | Feature Manager (feature lists) | S4 | ⏳ S4 |
| 125 | Feature Showcase (client panel sections on/off) | S2B | 🟡 2B |
| 126 | Reseller Center + ACLs + Reseller packages | S15 | 🟡 S15 — workspace and account/package ownership scope partial; full ACLs/multi-server pending |
| 127 | Themes / Theme Manager | S2B | 🟡 2B — AlphaCP role palettes in source; full theme manager pending |

### DNS Functions
| # | WHM tool | Step | Status |
|---|---|---|---|
| 128 | DNS Zone Manager | S9 | ⏳ S9 |
| 129 | Add / Delete a DNS Zone | S9 | ⏳ S9 |
| 130 | Add an A Entry for Your Hostname | S9 | ⏳ S9 |
| 131 | Edit Zone Templates | S9 | ⏳ S9 |
| 132 | Email Routing Configuration (global) | S9 | ⏳ S9 |
| 133 | Enable DKIM/SPF Globally | S7 | ⏳ S7 |
| 134 | Nameserver Record Report | S9 | ⏳ S9 |
| 135 | Park a Domain | S9 | ⏳ S9 |
| 136 | Perform a DNS Cleanup | S9 | ⏳ S9 |
| 137 | Set Zone TTL | S9 | ⏳ S9 |
| 138 | Setup/Edit Domain Forwarding | S9 | ⏳ S9 |
| 139 | Synchronize DNS Records | S9 | ⏳ S9 |
| 140 | DNS Cluster | S15 | ⏳ S15 |

### Email (server-wide)
| # | WHM tool | Step | Status |
|---|---|---|---|
| 141 | Mail Queue Manager | S7 | ⏳ S7 |
| 142 | Mail Delivery Reports | S7 | ⏳ S7 |
| 143 | Exim Configuration Manager | S7 | ⏳ S7 |
| 144 | Mailserver Configuration (Dovecot) | S7 | ⏳ S7 |
| 145 | Email Deliverability (server default) | S7 | ⏳ S7 |
| 146 | Email Disk Usage (server view) | S7 | ⏳ S7 |
| 147 | SpamAssassin + Greylisting config | S7 | ⏳ S7 |
| 148 | Address Importer (server) | S7 | ⏳ S7 |

### SQL / Databases (server-wide)
| # | WHM tool | Step | Status |
|---|---|---|---|
| 149 | Manage DB users / reset password | S8 | ⏳ S8 |
| 150 | Repair / optimize DB, upgrade server | S8 | ⏳ S8 |
| 151 | phpMyAdmin config + phpPgAdmin(🔵) | S8 | ⏳ S8 |
| 152 | Remote MySQL (server config) | S8 | ⏳ S8 |

### Security Center
| # | WHM tool | Step | Status |
|---|---|---|---|
| 153 | **cPHulk Brute Force Protection** | S13 | ⏳ S13 |
| 154 | Host Access Control | S13 | ⏳ S13 |
| 155 | Configure Security Policies | S13 | ⏳ S13 |
| 156 | Password Strength Configuration | S13 | ⏳ S13 |
| 157 | Security Advisor | S13 | ⏳ S13 |
| 158 | Security Questions | S13 | ⏳ S13 |
| 159 | Two-Factor Authentication (root/reseller) | S13 | ⏳ S13 |
| 160 | Manage root's SSH Keys | S13 | ⏳ S13 |
| 161 | Manage Wheel Group Users | S13 | ⏳ S13 |
| 162 | ModSecurity Configuration / Tools / Vendors | S13 | ⏳ S13 |
| 163 | Apache mod_userdir Tweak | S13 | ⏳ S13 |
| 164 | SMTP Restrictions | S13 | ⏳ S13 |
| 165 | Compiler Access | S13 | ⏳ S13 |
| 166 | Shell Fork Bomb Protection | S13 | ⏳ S13 |
| 167 | SSH Password Authorization Tweak | S13 | ⏳ S13 |
| 168 | Traceroute Enable/Disable | S13 | ⏳ S13 |
| 169 | Manage External Authentications | S13 | ⏳ S13 |
| 170 | Manage API Tokens | S12 | ⏳ S12 |

### Service Configuration / Restart Services
| # | WHM tool | Step | Status |
|---|---|---|---|
| 171 | Service Manager (start/stop/enable) | S2B |S2B|🟡 2B-2| 172 | Restart: DNS / HTTP / IMAP / Mail / SQL / SSH / PHP-FPM / Mailing List | S2B | 🟡 2B |
| 173 | Exim / FTP Server Selection / Mailserver / Nameserver Selection | S7·S9 | ⏳ |
| 174 | Manage Service SSL Certificates | S5 | ⏳ S5 |
| 175 | cPanel Web Disk & Web Services Configuration | S6 | ⏳ S6 |

### Backup / Clusters / Reboot / Status
| # | WHM tool | Step | Status |
|---|---|---|---|
| 176 | Backup Configuration (schedule, remote, retention) | S10 | ⏳ S10 |
| 177 | Backup Restoration (full/partial/per-account) | S10 | ⏳ S10 |
| 178 | Backup User Selection | S10 | ⏳ S10 |
| 179 | File and Directory Restoration | S10 | ⏳ S10 |
| 180 | Configuration Cluster / DNS Cluster | S15 | ⏳ S15 |
| 181 | Graceful / Forceful Server Reboot | S2B | 🟡 2B |
| 182 | Server Information / Service Status / Apache Status | S11 | ⏳ S11 |
| 183 | Daily Process Log | S11 | ⏳ S11 |
| 184 | **Task Queue Monitor** (hamara paneld queue) | S2B |S2B|🟡 2B-1| # | WHM tool | Step | Status |
|---|---|---|---|
| 185 | Transfer Tool (cPanel→AlphaCP migration) | S10 | ⏳ S10 |
| 186 | Transfer or Restore a cPanel Account | S10 | ⏳ S10 |
| 187 | Convert Addon Domain to Account | S15 | ⏳ S15 |
| 188 | Review Transfers and Restores | S10 | ⏳ S10 |
| 189 | IP Functions (assign/show IPs) | S15 | ⏳ S15 |
| 190 | Change Hostname / Resolver Configuration | S15 | ⏳ S15 |
| 191 | Locales (add/edit/import language) | S2B | 🟡 2B |
| 192 | Support Center / Grant Support Access | S15 | ⏳ S15 |

---

## 4. SYSTEM + BUSINESS side (cPanel me ye bhi hota hai)

| # | cPanel/WHM cheez | Humara equivalent | Step | Status |
|---|---|---|---|---|
| 193 | One-click installer (cPanel ka install script) | `installer/install.sh` + `step2-install.sh` | S1·S2A | ✅ |
| 194 | WHM API 1 (billing integration) | Wahi function naam + JSON shape | S12 | ⏳ S12 |
| 195 | API tokens with permissions | Per-function token scopes | S12 | ⏳ S12 |
| 196 | cPanel license (paid) | **AlphaCP license system** (Ed25519 signed, our own) |S2C|🟡 S2C|
| 197 | cPanel updates (one-click + auto) | One-click update + rollback + `alphacp update` | S15 | ⏳ S15 |
| 198 | Multi-server / link nodes | Central + nodes, per-node tasks | S15 | ⏳ S15 |
| 199 | cPanel backup import (hosting migrations) | `.tar.gz` cPanel backup import | S10 | ⏳ S10 |
| 200 | Root task engine (internal) | **paneld** (allowlist, audit, rollback-safe) | S2A | ✅ |
| 201 | CLI (`/usr/local/cpanel/*` scripts) | `alphacp` CLI (status/doctor/task/agent/logs) | S1·S2A | ✅ |
| 202 | cPHulk/audit trail for everything | `audit_logs` immutable trail | S2A | ✅ |

## 5. cPanel 138 (2026) ke NAYE features — hum bhi lenge

| # | Naya cPanel feature | Humara plan | Status |
|---|---|---|---|
| 203 | Meridian task-based layout | S2B me wahi 6-area layout (Websites/Email/Files/Databases/Security/Performance) + classic grid toggle |S2B|🟡 2B-1| 204 | Guided Setup wizard | Onboarding wizard (domain→site→email) | ⏳ S2B |
| 205 | AI Assistant (panene API key se) | 🔵 post-v1 |
| 206 | Node.js AI Toolkit | Node.js selector + app manager | ⏳ S14 |
| 207 | MCP support (AI agents se cPanel control) | 🔵 AlphaCP MCP server (panel ko AI se chalane ke liye) | 🔵 post-v1 |
| 208 | Nova AI website builder | 🔵 Optional website builder module | 🔵 post-v1 |

---

## 6. Progress meter (auto-updated har step ke baad)

| Hissa | Total items | ✅ Done | 🟡 Building | ⏳ Planned | 🔵 Optional |
|---|---|---|---|---|---|
| Client panel (cPanel) | 93 | 0 | 10 | 77 | 6 |
| Admin/Reseller (WHM) | 97 | 1 | 9 | 88 | 1 |
| System/Business | 10 | **4** | 0 | 6 | 0 |
| cPanel 138 naye features | 5 | 0 | 1 | 2 | 3 |
| **TOTAL** | **205** | **5** | **20** | **173** | **10** |

> Note: ✅ = test hokar live (installer, paneld, queue, CLI, quota, audit). 🟡 = is waqt ban raha hai
> (Step 2B-1 me login + dashboard live ho gaye). Har step ke baad ye table upar jayegi.

> Note: ✅ sirf wahan hai jahan kaam **test hokar live** hai (installer, paneld, queue, CLI, quota, audit).
> Baaki cheezein jo Step 1/2A me ban chuki hain (services stack, DB, security layer, task engine) un tools ke
> **andar** chhupi hui hain — isliye ye numbers neev dikhate hain, poora panel nahi. Har step ke baad ye table upar jayegi.

> 📌 **Niyam:** ye file har step ke baad update hogi. Koi bhi item **hataya nahi jayega**.
> Naya item add karna ho to pehle yahan add hoga, phir code me.
> Jab **TOTAL = ✅** (optional 🔵 chhod kar) ho jaye — tab AlphaCP **100% cPanel-parity** complete hai.

---

## 7. Kya cheez jaan-boojh kar NAHI karenge (aur kyun)

| cPanel ka kaam | Hum kyun nahi kar rahe |
|---|---|
| cPanel/WHM brand, logo, text copy karna | Legal — brand copy karna allowed nahi. **Feature/behaviour same, branding hamari.** |
| cPanel ke andar ka closed-source code | Available nahi hai + legal nahi. Hum har feature **khud** bana rahe hain (behaviour same). |
| LiteSpeed/Imunify jaise paid third-party add-ons | 🔵 Chahein to baad me integrate kar sakte hain (inme alag license lagta hai) — customer ki marzi. |
| Purane mar chuke tools (Analog/Webalizer bina support) | 🔵 Rakh sakte hain compatibility ke liye, par by default Awstats + apna stats engine chalega. |
y ke liye, par by default Awstats + apna stats engine chalega. |
