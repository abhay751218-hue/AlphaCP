# ✅ AWS Lightsail — Dev Server Setup Guide
### (Aapke $100 credits ke saath — kaunsa plan, kaise setup karna hai)
**Date:** 28 Sep 2026 | **Account:** ABHAY KUMAR (002493750047) | **Credits:** $100 · 182 din

---

## 🎯 FINAL DECISION (UPDATED 28 Sep): **$24/month wala plan lo** ✅

**Specs:** 4 GB RAM · 2 vCPU · 80 GB SSD · 2 TB transfer · Dual-stack (IPv4 + IPv6)

> Aapki requirement: **"aisa plan jisme project me koi problem na aaye"** — isi liye ab $12 se upgrade karke **$24 FINAL** kar raha hoon. Neeche poori wajah + paise ka hisaab hai.

### Kyun $24 — aur $12 me kya problems aati:

| Plan | Specs | Verdict |
|---|---|---|
| $5 / $7 (512MB–1GB) | ❌ | Stack chalega hi nahi |
| $12 (2 GB) | ⚠️ Kaam kar jaata, **PAR:** swap lagana padta • npm/composer build pe **OOM (memory full) crash ka risk** • ClamAV/SpamAssassin test nahi kar paate • sab kuch slow • chhoti-chhoti problems aati rehti |
| **$24 (4 GB)** | ✅ **FINAL** — pura stack (web + mail + DNS + DB + panel + Redis + SpamAssassin) + React/npm builds — sab aaram se. **RAM ka koi tension nahi** |
| $44 (8 GB) | ❌ Dev ke liye overkill — credits sirf ~2 mahine chalenge |

**Technical wajah (honest):** pura hosting stack ~1.5–2 GB RAM khaata hai. Uske **upar** ye aata hai:
- Panel ka React/frontend **npm build** → transient 1–1.5 GB (2 GB me yahi OOM ho jaata hai)
- Email module testing → Exim + Dovecot + SpamAssassin (ClamAV akele ~1 GB khaata hai)
- Composer install, database imports, multiple test websites

4 GB me ye sab smooth chalte hain — matlab **aapko "server hang / error" wali problems nahi aayengi**. Beginner ke liye yahi sabse important hai.

### 💰 Paise Ka Math ($100 credits ke saath)

| Item | Kharcha |
|---|---|
| $24 plan × ~4 mahine (122 din) | **~$96** |
| Snapshots (~80 GB, daily) | ~$4–8 |
| Static IP (instance pe attached) | **$0 (free)** |
| **Credits cover** | **~4 mahine free** ✅ |
| Uske baad | ~$24/mo (~₹2,150/mo) — aapne khud kaha tha: "khatam hone pe pay karke active rakh lunga" |

**Aapka plan theek hai:** 4 mahine free, phir jab tak project khatam na ho ~₹2,150/mo. 6–8 mahine ka project = sirf ~₹4,300–8,600 ka total kharcha — ek dafa ka chhota sa kharcha, aur badle me dev environment **bina kisi problem ke** chalega.

> 💡 Agar aap kahein "bilkul paise nahi lagane, credits se hi pura cover karo" → to $12 bhi chal jaayega (2–4 GB swap + npm build tuning + ClamAV dev me skip). **Lekin** slow + chhoti problems ka risk rahega. Aapki "no problem" requirement ke hisaab se meri final sifarish: **$24**. ✅

---

## 📍 2. Region Change Karo — **Mumbai (ap-south-1)** Recommend

Screenshot me aapka region **Europe (Stockholm)** dikh raha hai. Mumbai better hai kyunki:
- ✅ Aap Gurugram me ho → panel/SSH **fast** feel hoga (Stockholm me ~120-150ms ping)
- ✅ Aapke India customers ke liye latency kam (10-40ms) — baad me beta testing me kaam aayega
- ✅ Credits sab regions me same chalte hain

**Kaise:** Lightsail Console → upar **region dropdown** (jahan "Europe (Stockholm)" likha hai) → **Asia Pacific (Mumbai) ap-south-1** select karo.

*(Agar Mumbai me instance unavailable ho, to Singapore ya Stockholm bhi chalega — dev ke liye koi problem nahi.)*

---

## 🐧 3. Operating System: **Ubuntu 24.04 LTS** ✅ (FINAL — 28 Sep, screenshot dekh ke confirm)

**Lightsail "OS Only" blueprint list me ye select karo: `Ubuntu 24.04 LTS`**

**Kyun (updated reasoning):**
1. **cPanel khud ab Ubuntu 24.04 LTS ko officially support karta hai** (v110+) — matlab industry me ye hosting ke liye first-class OS hai
2. Lightsail pe **Ubuntu image sabse achhi maintained** hai — AWS ka default recommendation, kam surprises
3. **Hamara installer isi pe primary banega + test hoga** (AlmaLinux 9 secondary support) — apt ecosystem me sab packages easily milte hain
4. AlmaLinux bhi list me hai, lekin uska version (9 vs 10) confirm karna padega — 10 naya hai, tools abhi settle ho rahe hain

**Baaki options kyun nahi:**
| Blueprint | Verdict |
|---|---|
| Amazon Linux 2023 | ❌ AWS-specific quirks — hosting stack me friction |
| Ubuntu 22.04 LTS | ⚠️ Chalega par purana — 24.04 better |
| Debian 13 / 12 | ❌ cPanel unsupported, hamara target nahi |
| FreeBSD 15.1 / 14.4 | ❌ Hosting panels ke liye unsupported |
| openSUSE 16.0 | ❌ Niche, panel ecosystem nahi |
| AlmaLinux | ⏸️ Backup option — sirf agar 9.x ho to (version confirm karna padega) |

- ❌ **"App + OS" blueprints (WordPress, Plesk, cPanel, NodeJS) bilkul NAHI chunna** — hum apna pura stack khud install karenge. Sirf **"OS Only"** chuno.
- 🔑 **SSH username Ubuntu me `ubuntu` hota hai** (AlmaLinux me `ec2-user`)

---

## 🚀 4. Instance Banane Ka Process (EXACT — form me ek-ek karke ye karo)

### Screen: "Choose your instance plan"
| Field | Kya karna | Value |
|---|---|---|
| Select a plan type | As is chhod do | ✅ **General purpose** (already selected) |
| Select a network type | As is chhod do | ✅ **Dual-stack** (already selected — IPv4+IPv6 dono) |
| **Select a size** | Neeche scroll karo | ✅ **$24 wala select karo** (4 GB Memory · 2 vCPU · 80 GB SSD) |

### Screen: "Configure your instance"
| Field | Kya karna | Value |
|---|---|---|
| **Instance name** | `Ubuntu-1` ko mitao, ye likho | ✅ **`dev-srv1`** |
| **Automatic snapshots** | Checkbox pe **TICK karo** | ✅ **Enable Automatic Snapshots — ON** |
| Tagging options | Skip kar do (optional hai) | — |
| Advanced settings | **Kuch mat chhedo** (SSH keys/launch script baad me) | Default hi theek hai |
| **Create instance** | Orange button dabao | 2-3 min me ready ✅ |

> 📌 **Mumbai ka note:** AWS info box kehta hai Mumbai region me data transfer allowance kam hota hai (dusre regions se). **Dev/testing ke liye isse koi farak nahi padta** — 1.5-2 TB bhi bahut zyada hai. Production me hum alag provider/server pe jayenge anyway.

### Instance banne ke turant baad — 3 zaroori kaam:

**A) STATIC IP attach karo (SABSE PEHLE!)**
Lightsail → Networking → **Static IPs → Create static IP** → instance `dev-srv1` se attach karo.
> Kyun? Warna reboot pe IP badal jayega aur panel setup + DNS testing sab break ho jayega. Static IP instance se attached hote hue **free** hai. Ye kaam **koi bhi software install karne se PEHLE** karna.

**B) Firewall ports kholo**
Instance → **Networking → IPv4 Firewall → Add rule** — ye ports add karo:

| Port | Protocol | Kis liye | Kab |
|---|---|---|---|
| 22 | TCP | SSH | ✅ Ab (default open hoga) |
| 80, 443 | TCP | HTTP/HTTPS (websites + panel) | ✅ Ab |
| 2082, 2083 | TCP | Customer Panel | ✅ Ab |
| 2086, 2087 | TCP | Admin Panel + Billing API | ✅ Ab |
| 2095, 2096 | TCP | Webmail | Step 7 |
| 587, 465 | TCP | Email sending (submission) | Step 7 |
| 25 | TCP | Email receive (SMTP) | Step 7 |
| 143, 993 | TCP | IMAP | Step 7 |
| 110, 995 | TCP | POP3 | Step 7 |
| 21, 49152-65535 | TCP | FTP + passive | Step 6 |
| 53 | TCP + UDP | DNS | Step 9 |
| 3306 | TCP | ❌ MySQL — **KABHI PUBLIC NA KHOLEIN** | Never |

*(Lightsail me "Add rule" se port range `2082-2083` style likh sakte ho; comma wale separate rules daal do.)*

---

## 📱 4.5 MOBILE PE KARNA HAI (Tap-by-Tap) — 28 Sep ka Next Checklist

> ⚠️ **Public IP ke aage warning icon = temporary IP hai.** Reboot pe badal jayega → isliye STATIC IP sabse pehle karna hai.

### ✅ TASK 1: Static IP attach karo
1. Instance `dev-srv1` page → upar tabs **Connect / Metrics / Snapshots** → side me swipe karke **Networking** tab kholo
2. **Static IP** section → **"Create static IP"** / **"Attach static IP"** dabao
3. Form:
   - Region: **Mumbai** (auto)
   - Static IP name: **`dev-srv1-ip`**
   - **Attach to an instance: `dev-srv1` select karo** ← ye important
4. **Create** → IP ab permanent ✅ (same 3.109.132.244, bas ⚠️ hat jayega)

### ✅ TASK 2: Firewall ports kholo
1. Wahi instance → **Networking** tab → **IPv4 Firewall** → **Add rule**
2. Har rule: **Application = Custom** · **Protocol = TCP** · Port likho ↓
   - `80` (HTTP), `443` (HTTPS), `2082` , `2083`, `2086`, `2087`
   - (2082–2087 panel ke liye — abhi add kar lo, aage kaam aayega)
3. SSH `22` **already open hai — usse chhedna nahi**
4. **IPv6 Firewall** section ko abhi chhod do

### ✅ TASK 3: Automatic Snapshots check
1. Instance → **Snapshots** tab
2. "Automatic snapshots" section me agar **Enable/Turn on** button dikhe → dabao ✅
   (already enabled dikhe to done hai)

### ✅ TASK 4: SSH se verify karo
1. Instance → **Connect** tab → orange **"Connect using SSH"** button
2. Black terminal khulega (10–20 sec lag sakte hain)
3. Ye ek line type/paste karo:
```
uname -m; free -h; df -h /; curl -s ifconfig.me; echo
```
4. Output mujhe bhejo ✅

### ✅ TASK 5: Billing budget alert (baad me bhi kar sakte ho, desktop pe aasan)
AWS Console → search **"Budgets"** → Create budget → Monthly cost budget → **$1** → apna email → Create

---

## ⚠️ 5. Port 25 — AWS Ka Sabse Bada Catch (Ye Pata Hona Zaroori Hai!)

Maine verify kiya: **AWS Lightsail outbound port 25 default se BLOCK karta hai** (spam rokne ke liye). Iska matlab:

| Cheez | Status |
|---|---|
| **Email receive** (aapka server mail leta hai) | ✅ Firewall me port 25 kholne pe chalega |
| **Email send** (aapka server bahar mail bhejta hai) | ❌ **Blocked** — AWS support se removal form bharni padegi (approve hona guarantee nahi, ~48 hrs) |
| **Development me asar?** | ❌ **Koi nahi** — hum dev testing me port 587 / internal relay use karenge |

**Hamara plan (Step 7 me):**
- Dev/test: email sending ke liye **port 587 + relay** se test karenge (ya Amazon SES — AWS ka apna email service, port 25 ki zaroorat hi nahi)
- Production me 3 options: (1) AWS se port 25 unblock request, (2) SES/3rd-party relay, (3) production server kisi aur provider pe (Hetzner/GigaNodes) jahan port 25 allowed hai
- **Aapke panel me ye flexibility built-in rakhunga** — SMTP relay option built-in hoga (ye standard practice hai)

---

## 💳 6. Credits Bachane Ke Rules (Ye Zaroor Follow Karo!)

1. **Billing Alert lagao:** AWS Console → Billing → **Budgets** → Create budget → $1 (ya $5) pe alert → email pe notification aayega
2. **Sirf Lightsail use karo** — ye cheezein **galti se bhi na launch karo** (credit khatam ho jayenge):
   - ❌ EC2 instances (Lightsail alag cheez hai, EC2 bahut mehnga)
   - ❌ RDS / Managed Databases (Lightsail me alag $15/mo)
   - ❌ Load Balancer ($18/mo), CDN extra
3. **Snapshots limit me rakho:** Automatic snapshots ON hai to ~7 daily rakhta hai — theek hai. Manual snapshots har din mat banao (storage charge $0.05/GB-month)
4. **Har hafte Credits check karo:** Billing → **Credits** page — dekho consumption covered hai
5. Static IP **instance pe attached rahe** — unattached static IP ka charge lagta hai

---

## 🔧 7. 4 GB RAM Me Sab Kuch Smooth (Technical Plan)

4 GB me pura stack bina kisi jugad ke chalega — yehi $24 plan ka asli fayda hai:

1. **Full stack normal config me:** MariaDB + Exim + Dovecot + BIND + Apache/Nginx + PHP-FPM + Redis + Panel
2. **SpamAssassin bhi chalega** — email module (Step 7) ka poora testing ho payega
3. **React/npm builds** aaram se — 2 GB me yahi sabse badi problem hoti thi
4. **2 GB Swap** fir bhi laga denge (safety net — build spikes ke liye)
5. **Monitoring script** main dunga — RAM / disk / load live dekhne ke liye
6. ClamAV (antivirus) dev me sirf tab chalayenge jab specifically test karna ho (kyunki wo ~1 GB khaata hai) — production me optimized config me chalega

---

## 📦 8. Oracle Cloud Ka Kya Karein?

**Delete bilkul nahi karna!** Uska role ab ye hai:
- 🥈 **Spare/second node** — Step 15 (multi-server) me kaam aayega: 2 servers ke beech account create karke test karenge
- 🔬 **DNS second node testing** — apna nameserver cluster test karne ke liye
- 💾 **Backup testing machine** — kuch cheezein isolate karke test karne ke liye

Aur agar Oracle kabhi instance reclaim kar bhi le, to koi nuksaan nahi — kyunki hamara primary dev server ab **Lightsail** hoga. ✅

> **Summary:** Lightsail ($12, x86_64, reliable) = **Primary dev server ab se**. Oracle (ARM, free) = spare/testing node.

---

## 📤 9. Aapka KYC — Aaj Ye 5 Kaam Karo

| # | Kaam | Bhejna kya hai |
|---|---|---|
| 1 | Lightsail region **Mumbai** karo → **$24 plan (4 GB)** + **Ubuntu 24.04 LTS** + **Dual-stack** network se instance banao | "Done ✅" |
| 2 | **Static IP** create karke instance se attach karo | "Done ✅" |
| 3 | **Automatic snapshots ON** karo + **Billing Budget alert ($1)** lagao | "Done ✅" |
| 4 | Instance → **"Connect using SSH"** (browser wala button — sabse easy!) kholo aur ye chalao:<br>`cat /etc/os-release \| head -3`<br>`uname -m`<br>`nproc`<br>`free -h`<br>`df -h /` | **Output paste karo** |
| 5 | Firewall me abhi ke ports kholo: **80, 443, 2082-2083, 2086-2087** | "Done ✅" |

**Jab ye ho jaye** → bolo **"Step 0 chalu karo"** — main requirements freeze, database schema, project structure, security matrix aur server hardening shuru kar dunga. 🚀

---
*Note: SSH user name — Ubuntu me `ubuntu`, AlmaLinux me `ec2-user` hota hai (agar login issue aaye to batao, main fix bata dunga). Local laptop se SSH karna ho to main key setup guide bhi de dunga.*
