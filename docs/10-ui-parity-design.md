# 10 — UI / Panel Parity Design (cPanel · WHM · Webmail)

> **Kaam:** AlphaCP ke **teen panels** (customer cPanel, admin/reseller WHM, mail Webmail) ko
> cPanel company jaise dikhana hai — sections, layout, colours, icons, responsiveness.
> **Rule jo nahi todenge:** cPanel/WHM ka **brand/logo/text copy nahi** karenge
> (docs/09-cpanel-parity-checklist.md §1). Sections, tool-naam-jaise labels, limits keys aur
> API shape **same** rahenge — colours thode alag (AlphaCP brand).
>
> **Research date:** 7 Oct 2026 · **Base:** cPanel & WHM **138** (build `11.138.0.11`), Jul 2026 release.
> **Live research sources** (sandbox se verify kiye):
> - cPanel brand guide PDF — official palette (Pantone/RGB/HEX).
> - `trycpanel.net` — cPanel 138 **live demo** (Jupiter Tools page + General Information + Statistics + theme list).
> - `trywhm.net` — WHM 138 **live demo** (poori nav category → tool list).
> - Meridian (naya 138 theme) par 5+ independent articles (InMotion, Panellicense, bodHOST, bigcloudy, cPanel Reddit AMA).

---

## 1. cPanel 138 me 2 interface hain (ye samajhna zaroori hai)

| Theme | Status | Structure | Hamara plan |
|---|---|---|---|
| **Jupiter** | Default (customer) | Category-wise Tools page: Email · Files · Databases · Domains · Metrics · Security · Software · Advanced · Preferences + right column (General Information, Statistics) + top search "Find functions quickly…" | **P-UI-1/2/3 me yahi banaya** — kyunki customer ise pehchanta hai |
| **Meridian** | **Naya, opt-in** (cPanel 138, Jul 2026) | **6 goal-based hubs**: Websites & Apps · Email · Files · Databases · Security · Performance + **Dashboard** (required actions) + **Guided Setup** + **Command Palette** + **Ask AI** + MCP | **P-UI-4/6 me optional "Meridian-style" theme** (badge: AlphaCP) |

Whm 138 ka WHM side bhi wahi Jupiter branding use karta hai (left nav tree + Favorites + Statistics).

---

## 2. Design tokens (official cPanel brand guide se, RGB/HEX as published)

| Token | Hex | Source | Kahan use hota hai |
|---|---|---|---|
| cPanel Orange | `#FF6C2C` | cPanel brand guide (Pantone 021 U) | accent, primary buttons, active nav item, logo tile |
| cPanel Navy | `#293A4A` | cPanel brand guide (Pantone 548 U) | top bar, sidebar, headings |
| Navy (dark) | `#22303F` | same family (WHM dark bar) | sidebar hover/active, WHM top bar |
| Accent blue | `#179BD7` | brand guide accent | meters, links-in-panel, info badges |
| Accent green | `#83B655` | brand guide accent | success/ok |
| Dark orange | `#D03F00` | brand guide accent | button hover/danger-ish |
| Background | `#EEF1F4` | Jupiter content bg (paper-white look) | page background |
| Card | `#FFFFFF` + border `#D9DEE3` | Jupiter card style | cards, tiles, panels |
| Text | `#35414E`, muted `#6D7B89` | Jupiter type colours | body text, help text |

AlphaCP apna **brand** rakhta hai (logo "A", naam AlphaCP) — sirf **design language** match karte hain.

---

## 3. Customer cPanel — exact structure (trycpanel.net demo se, 7 Oct 2026)

**Top bar:** brand + `Find functions quickly…` search + user chip.
**Left sidebar:** collapsible sections → tools.
**Right column (har page par):** *General Information* + *Statistics*.
**Theme switcher:** Preferences → Change Style (Jupiter ↔ Meridian). AlphaCP: sirf apne allowed styles.

### 3.1 Sections aur tools (cPanel 138 Jupiter ka demo order)

| Section | Tools (demo me jo mile) | AlphaCP me |
|---|---|---|
| **Email** | Email Accounts · Forwarders · Email Routing · Autoresponders · Default Address · Mailing Lists · Track Delivery · Global Email Filters · Email Filters · Email Deliverability · Address Importer · Spam Filters · Encryption · BoxTrapper · Calendars and Contacts (Config/Sharing/Management) · Email Disk Usage | ✅ sab live (`email`, `forwarders`, `email-routing`, `autoresponders`, `default-address`, `mailing-lists`, `track-delivery`, `global-filters`, `email-filters`, `deliverability`, `address-importer`, `spam-filters`, `encryption`, `boxtrapper`, `calendar`, `email-disk`, `webmail`) |
| **Files** | File Manager · Images · Directory Privacy · Disk Usage · Web Disk · Backup · Backup Wizard · Git Version Control · File and Directory Restoration | ✅ `files`, `images`, `privacy`, `disk`, `webdisk`, `backup`, `backup-wizard`, `git`, `file-restoration`; Trash extra |
| **Databases** | phpMyAdmin · Manage My Databases · Database Wizard · Remote Database Access | ✅ `phpmyadmin`, `mysql`, `mysql-wizard`, `remote-mysql`, `mysql-users` |
| **Domains** | WordPress Management · Sitejet Builder · Social Media Management · Domains · Redirects · Zone Editor · Dynamic DNS | 🟡 `domains`, `domain-forward`, `zone-editor`, `dynamic-dns` live; WP/Sitejet/Social = S14/post-v1 |
| **Metrics** | Visitors · Site Quality Monitoring · Errors · Bandwidth · Raw Access · Awstats · Analog · Webalizer · Metrics Editor | 🟡 Visitors/Bandwidth/Resource live; Errors/Raw/Awstats = S11 baaki |
| **Security** | SSH Access · IP Blocker · SSL/TLS Certificates · Manage API Tokens · Hotlink Protection · Leech Protection | ✅ sab live |
| **Software** | PHP PEAR · Perl Modules · Optimize Website · MultiPHP Manager · MultiPHP INI Editor (+ WP Toolkit, Node, Composer full installs me) | ✅ MultiPHP/INI/Optimize/AppInstaller live; PEAR/Perl = post-v1 |
| **Advanced** | Cron Jobs · Track DNS · Indexes · Error Pages · Apache Handlers · MIME Types (+ SSH/Terminal) | ✅ sab live |
| **Preferences** | Account Preferences · Password & Security · Change Language · Contact Information · User Manager | 🟡 Password/2FA/User Manager live; Change Style **ab live** (P-UI-1); Language/Contact = P-UI-5 |

### 3.2 Right column fields (demo se, bilkul yahi)

**General Information:** Current User · Primary Domain · SSL Certificate (state + "Add SSL") · Site Quality
Monitoring · Shared/Dedicated IP Address · Home Directory · Last Login IP Address · User Analytics ID ·
**Theme** (dropdown) · Server Information.
**Statistics (used / limit):** Alias Domains · Addon Domains · Disk Usage · Database Disk Usage ·
Bandwidth · Subdomains · Email Accounts · Mailing Lists · Autoresponders · Forwarders · Email Filters ·
Databases.

> AlphaCP me ye dono columns **live** hain (Phase P-UI-1): `partials/general-info.blade.php` +
> `partials/statistics.blade.php` — data `Panel::generalInfo()` / `Panel::statistics()` se (package
> limits ke cPanel-compatible keys: MAXPOP, MAXFWD, MAXSQL, MAXSUB, MAXADDON, MAXPARK, BWLIMIT, QUOTA).

---

## 4. WHM — exact structure (trywhm.net demo 138 se)

WHM ka nav (categories, jaisa demo me hai): Server Configuration · Support · Networking Setup ·
Security Center · Server Contacts · Resellers · Service Configuration · Locales · Backup · Clusters ·
System Reboot · **Server Status** · **Account Information** · **Account Functions** · Multi Account
Functions · Transfers · Themes · **Packages** · **DNS Functions** · Database Services · IP Functions ·
**Software** · Email · cPanel · SSL/TLS · Market · Development.

AlphaCP ke WHM sections (nav + home tiles) inhi naam se bane hain:

| WHM category | AlphaCP me live tools |
|---|---|
| **Account Functions** | Create Account · List Accounts · Modify an Account · Upgrade/Downgrade · Manage Account Suspension · Terminate Accounts · Quota Modification · Force Password Change |
| **Account Information** | List Accounts · Show Accounts Over Quota · View Bandwidth Usage · List Parked Domains · List Subdomains |
| **Packages** | Add/Edit/Delete a Package · Feature Manager |
| **DNS Functions** | DNS Zone Manager · Add a DNS Zone · A Entry for Hostname · Edit Zone Templates · Email Routing Configuration · NS Record Report · Park a Domain · DNS Cleanup · Set Zone TTL · Domain Forwarding · Synchronize DNS Records · Nameserver Selection |
| **Email** | Email Deliverability · Email Routing · Track Delivery · Global Email Filters · Mail Queue Manager · Mail Statistics |
| **Backup** | Backup Configuration · Destinations · Restoration · User Selection · File and Directory Restoration · Backup Wizard |
| **Transfers** | Transfer Tool · Transfer or Restore a cPanel Account · Review Transfers and Restores |
| **Security Center** | ModSecurity Config · IP Blocker · 2FA · Manage API Tokens · Manage Shell Access · Audit Log · Wheel Group · Host Access Control |
| **Server Status** | Server Information · Service Status · Task Queue Monitor · Daily Process Log · Ports/Entry Points |
| **Resellers** | Reseller Center · Show Reseller Accounts · Change Ownership |
| **Clusters** | DNS Cluster (Configuration Cluster = planned) |
| **License & Updates** | License & Trial · License Server · Updates (planned) |

**WHM home:** Favorites (quick tiles) + Statistics (Memory/Disk/Load/Queue) + Service Status + audit — live.

---

## 5. Webmail (mail-only user)

cPanel: Webmail **alag app** hai (2095/2096), login email+password, andar Roundcube/Horde.
AlphaCP (abhi): `mail` role ko **alag Webmail shell** milta hai (white bar + orange accent, sirf Email +
Security sections) — Phase **P-UI-4** me Roundcube SSO + alag login (port opt-in) aayega.

---

## 6. Theme engine (Phase P-UI-1 — ✅ shipped in panel 0.77.0)

```
app/Support/Theme.php          → user+role se theme resolve (jupiter|whm|webmail), tokens, search index
app/Support/NavIcon.php        → section → icon glyph (offline, koi CDN nahi)
app/Support/ModuleCatalog.php  → cPanel order + WHM categories (audience: cpanel|whm|both)
resources/views/layouts/panel.blade.php  → ek shell, teen looks (body class + CSS tokens)
resources/views/partials/general-info.blade.php + statistics.blade.php
public/assets/panel.css        → design system (tokens + saare purane classes restyled)
public/assets/panel.js         → sidebar toggle + "Find functions quickly…" (client-side, JSON index)
config/acp.php `ui`            → ACP_UI_THEME (force), ACP_BRAND_NAME, ACP_UI_ALLOW_SWITCH
POST /preferences/style        → Change Style (session me; DB column P-UI-5 me)
```

Theme sirf **look** badalta hai — data/routes/permissions same. Isliye 100+ module pages bina code
badle naye design me aa jaate hain.

---

## 7. Phases

| Phase | Kaam | Status |
|---|---|---|
| **P-UI-1** | Theme engine + 3 looks + tokens + General Info/Statistics + search + Change Style | ✅ panel **0.77.0** |
| **P-UI-2** | cPanel Tools page polish (drag-reorder sections, per-tool descriptions, icons SVG set) | ⏳ |
| **P-UI-3** | WHM nav-tree polish (favorites user-pinned, per-category search, statistics graphs) | ⏳ |
| **P-UI-4** | Webmail alag app (Roundcube SSO) + alag login page (2095/2096 opt-in) | ⏳ |
| **P-UI-5** | Responsive polish + white-label (logo/colour settings table) + Language/Contact prefs | ⏳ |
| **P-UI-6** | Har module page ko cPanel ke page-layout se match (forms/tables/empty states) | ⏳ |
| **P-UI-7** | (Optional) **Meridian-style** theme: 6 hubs + Dashboard + Command Palette | 🔵 backlog |

---

## 8. Niyam (yaad rakho)

1. Naam/labels cPanel jaise — brand/logo sirf AlphaCP ka.
2. Naya module banate waqt: `ModuleCatalog` me tile add karo, `audience` set karo, phir dono panels me
   automatically aata hai (sidebar + Tools grid + search).
3. Har page ko **right column** ke saath render hona chahiye (layout karta hai) — page code me
   General Information dobara mat likho.
4. Icons sirf `NavIcon` se — ek jagah badlo, sab jagah badle.
5. Test contract: `tests/Feature/ThemeShellTest.php` + `DashboardShellTest.php` — UI wording badloge to
   tests bhi update karo (warna CI red).
