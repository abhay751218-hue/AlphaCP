# Feature-Depth Audit — "andar ka bhi 100% cPanel jaisa" (09 Oct 2026)

> Sawal tha: *kya har tool ke ANDAR ke options/features/style bhi 100% real cPanel/WHM
> jaise ho sakte hain — choti se choti cheez tak?*
>
> **Jawab: HAAN — 100% possible hai.** UI, options, fields, flows, behavior sab clone ho
> sakta hai. Sirf ek cheez legally copy NAHI hoti: cPanel ka **naam/logo/trademark**
> (isliye brand "AlphaCP" rehta hai, look & feature-set unka).

## 1. Audit ka tareeka

Live server snapshot (`server-snapshot/files/.../panel`, panel 0.75.0) ke har tool view ka
size + forms + input fields check kiya, aur real cPanel (Jupiter) ke usi tool ke
option-list se milaya.

## 2. Verdict ek line me

| Layer | Status |
|---|---|
| **Theme/shell** (colors, sidebar, topbar, icons, login) | ✅ **DONE** — theme-fix v1.2 ke baad cPanel-grade |
| **Breadth** (tool exist karta hai, basic kaam karta hai) | ✅ ~100 tools live |
| **Depth** (tool ke ANDAR har option real cPanel jitna) | 🟡 **~30–40%** — yahi agla kaam hai |

## 3. Depth gaps — top tools (choti-choti cheez ke level pe)

### 3.1 Email Accounts (abhi: list + create + delete)
Real cPanel me jo hai, humme jo missing:

| Option (real cPanel) | AlphaCP | 
|---|---|
| Create: password generator + strength meter | ❌ |
| Create: storage quota set (MB/unlimited radio) | 🟡 create pe fixed |
| Create: "Send welcome email" checkbox | ❌ |
| Create: plus-addressing folders auto-create | ❌ |
| Manage: quota EDIT (baad me badalna) | ❌ |
| Manage: password change | ❌ |
| Manage: restrictions — suspend incoming / suspend login | ❌ |
| Per-box disk usage + % bar | ❌ |
| Connect Devices (mail client auto-config: IMAP/SMTP settings card) | ❌ |
| Check Email (webmail one-click) | 🟡 webmail route hai |
| Search/filter + pagination | ❌ |
| Default/system account section | ❌ |

### 3.2 Domains (abhi: add + redirect)
| Option | AlphaCP |
|---|---|
| Document root dikhana/set karna | ❌ |
| Force HTTPS toggle per domain | ❌ |
| Subdomain create with doc-root picker | 🟡 type field hai |
| Remove + "share document root" warning flow | ❌ |

### 3.3 WHM Create Account (abhi: 6 fields)
Real WHM me ~25 fields hote hain:
| Block | Missing fields |
|---|---|
| Package override ("Select Options Manually") | quota, bw, max ftp/sql/email/addon/parked/sub limits | 
| Settings | CGI access, shell access, dedicated IP | 
| DNS | nameserver override, DKIM on/off, SPF on/off |
| Mail | mail routing (local/backup/remote) |
| Misc | locale, theme select, reseller ownership checkbox |

### 3.4 MySQL Databases (abhi: sirf create DB)
| Option | AlphaCP |
|---|---|
| Create user (separate section) | 🟡 alag page pe |
| Add user to DB → **privileges checkbox grid** (ALL + 14 granular) | ❌ |
| Check DB / Repair DB buttons | ❌ |
| Rename/delete flows with confirm | 🟡 delete hai |
| Prefixing note (user_dbname) | ❌ |

### 3.5 Cron Jobs (abhi: basic add)
| Option | AlphaCP |
|---|---|
| Common Settings presets dropdown (once per minute/5 min/hour/day…) | ❌ |
| Har field (min/hour/day/month/weekday) ka apna preset dropdown | ❌ |
| Cron email set/update section | ❌ |

### 3.6 SSL/TLS, Backup, File Manager, Zone Editor
- SSL: CSR generate + self-signed hai; **AutoSSL status card, cert upload/paste install, per-domain status table** missing
- Backup: full/partial hai; **download homedir/mysql/email filters alag-alag buttons** missing
- Files: File Manager basic; **right-click context actions, permissions dialog, extract/compress** missing
- Zone Editor: records CRUD hai; **per-type quick-add buttons (A/CNAME/MX), DNSSEC section** missing

## 4. Process — ab aage ye chalega (Depth Parity Waves)

Har wave = kuch tools ko **option-by-option 100%** tak le jana, phir commit-pinned
`panel-update` release (COMMANDS.md me nayi row, sha256 ke saath), live deploy, verify.

| Wave | Tools | Kyun pehle |
|---|---|---|
| **D1** | Email Accounts + Forwarders + Webmail connect | sabse zyada use hone wala panel section |
| **D2** | Domains + Subdomains + Zone Editor quick-add | hosting ka core |
| **D3** | MySQL (privileges grid, check/repair) + phpMyAdmin SSO | developer need |
| **D4** | WHM Create/Modify Account full form + Packages full limits | reseller/company need |
| **D5** | Cron presets, SSL/AutoSSL cards, Backup granular downloads, File Manager context menu | polish till 100% |

**Har wave ke Definition-of-Done:**
1. Tool ka har option real cPanel screenshot ke against checked (field-by-field)
2. `docs/09-cpanel-parity-checklist.md` row 🟡 → ✅
3. Agent task + migration (jahan zaroorat) + permission checks
4. theme-check/sims green, phir commit-pinned release row COMMANDS.md me

## 5. Theme/shell side jo bacha (chhota)

| Item | Status |
|---|---|
| Style switcher (Basic/Glass/**Dark mode**) — Jupiter jaisa | ⏳ D5 ke saath |
| WHM "Customization" (logo/colors/favicon upload — resellers apna brand laga sakein) | ⏳ D4 ke saath |
| Repo-side parity checklist ko live panel (0.75.0) se sync karna | ⏳ agla ghar ka kaam |
| PR #9 merge (theme + WHM/reseller + demo sab isme hai) | 🟢 ready, approval pe merge |

## 6. Ek honest note

"100% sam" me 2 cheezein hamesha alag rahengi:
1. **Brand**: cPanel/WHM naam, logo, trademark — legally copy nahi ho sakte (look-alike OK, naam-alike NAHI)
2. **Closed-source internals**: unka exact backend code nahi — hum same BEHAVIOR apne
   agent se dete hain (user ko farak nahi dikhta)

Baaki — layout, colors, icons, options, flows, defaults, error messages tak — sab 100%
match kiya ja sakta hai, aur upar ke waves me wahi ho raha hai.
