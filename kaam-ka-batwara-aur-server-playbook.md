# 🤝 KAAM KA BATWARA + SERVER PLAYBOOK
### (Aap aur Main — 100% Custom Panel Project)
**Date:** 28 Sep 2026 | **Decision:** 100% apna custom panel, koi time limit nahi | **Customers:** India + Global

---

# 📖 PART 1: KAUN KYA KAREGA (Roles & Responsibilities)

## 👨‍💻 MAIN (AI) — Meri 9 Zimmedariyan

| # | Kaam | Details |
|---|---|---|
| 1 | **Pura code likhna** | Panel ka backend (PHP/Laravel), frontend (React), APIs — saari files |
| 2 | **Server configuration** | Nginx, Apache, PHP-FPM, MariaDB, Exim, Dovecot, BIND, Pure-FTPd, Redis — sab ki config files aur templates |
| 3 | **One-Click Installer** | Ek script jo fresh server pe sab kuch automatic install kare (cPanel ka `sh latest` type) |
| 4 | **Runbook (Commands)** | Har step ke liye exact commands jo aap copy-paste karoge — saath me "ye kyun kar rahe hain" ka explanation |
| 5 | **Test Checklist** | Har step ke baad kya-kya test karna hai, kaise verify karna hai (pass/fail criteria) |
| 6 | **Debugging** | Aap error/output paste karoge → main root cause dhundh ke fix dunga (chahe 10 baar karna pade) |
| 7 | **Security Review** | Har module pe OWASP-level checks, hardening, penetration test checklist |
| 8 | **Documentation** | Admin manual, API docs, customer knowledgebase (Hindi + English) |
| 9 | **Planning** | Steps ka order, priorities, kya baad me karna — poori project management |

### ⚠️ Meri Limitations (honest disclosure)
- ❌ Main aapke server pe **direct login nahi kar sakta** — commands aap chalayoge, main outputs padh ke guide karunga
- ❌ Main aapke **passwords/API keys/card details** nahi dekh sakta (kabhi chat me bhi nahi bhejna — security!)
- ❌ Main **server kharid nahi sakta** — payment aap karoge
- ❌ Main **24/7 live** nahi hoon — aap message karoge to main aage badhaunga
- ✅ **Lekin:** code, config, docs, planning, debugging — ye 100% meri zimmedari hai. Aapko kabhi "kya karna hai samajh nahi aa raha" feel nahi hone dunga.

---

## 🙋 AAP — Aapki Zimmedariyan

### Technical (main step-by-step sikhaunga — pehle se aana zaroori nahi!)
| # | Kaam | Kitna mushkil? |
|---|---|---|
| 1 | Meri di hui commands **SSH pe copy-paste** karna + output mujhe bhejna | 😊 Aasan (main exact commands dunga) |
| 2 | Test checklist **follow karna** (button dabana, website kholna) | 😊 Aasan |
| 3 | Error aaye to **output/screenshot bhejna** — "kaam nahi kar raha" se kaam nahi chalega 😄 | 😊 Aasan |
| 4 | Server ka basic khayal: monthly reboot, backup check, alert dekhna | 😐 Medium (main scripts dunga) |
| 5 | Panel ka admin account, API tokens, passwords **sambhal ke rakhna** | 😐 Medium |
| 6 | Bugs milne pe **patience** rakhna — har software me bugs hote hain, hum fix karte jayenge | 💪 Attitude |

### Business (ye main nahi kar sakta — aapki zimmedari)
| # | Kaam | Kab |
|---|---|---|
| 7 | **Server kharidna** (Oracle already hai ✅; paid server baad me — plan neeche hai) | Stage 1 pe |
| 8 | **Domain + DNS + Branding** finalize (panel ka naam, logo) | Step 0 me |
| 9 | **Legal:** Company/LLP registration, GST number, Razorpay/Stripe payment gateway, Terms of Service + Privacy Policy | Launch se pehle |
| 10 | **Pricing plans** decide (kitne me kya bechna hai — server cost ke hisaab se) | Step 4 se pehle |
| 11 | **Customers laana + support dena** (main docs + ticket templates dunga, par call aap uthaoge 😄) | Launch ke baad |

---

## 🔄 Kaam Karne Ka Cycle (Har Step Pe Yahi Hoga)

```
        🙋 AAP: "Step X chalu karo"
                   ↓
        👨‍💻 MAIN: Files + Runbook (commands) + Test Checklist deta hoon
                   ↓
        🙋 AAP: Commands chalate ho (~30-60 min) + Output bhejte ho
                   ↓
        🙋 AAP: Test checklist ke hisaab se test karte ho
                   ↓
        🙋 AAP: "Sab chal gaya ✅" ya "ye error aaya ❌ + output"
                   ↓
        👨‍💻 MAIN: Error fix karta hoon ya next step deta hoon
                   ↓
        🔁 Repeat — 16 steps (MVP ~6-8 mahine)
```

**Har step pe aapko ye milega:** Code files + Install/Run commands + Test checklist + Kya seekha (short explanation)
**Aapke paas kuch bhi nahi khoyega:** Saara code is workspace me save hota hai — aap download karke rakh sakte ho. Step 0 me hum **Git repository** bhi setup karenge.

---

# ☁️ PART 2: SCENARIO A — Oracle Cloud 4 OCPU / 24 GB se Start (ABHI)

> 📌 **UPDATE (28 Sep 2026):** Aapne AWS account bhi activate kar liya ($100 credits / 182 din). **Final decision:**
> - 🥇 **Primary dev server = AWS Lightsail $24/mo plan** (4 GB RAM · 2 vCPU · 80 GB · x86_64 · Ubuntu 24.04 LTS) — "koi problem na aaye" wali requirement ke hisaab se 4 GB final. Credits se ~4 mahine free, uske baad ~$24/mo (aapne pay karne ki haan kahi hai). 2 GB wale $12 plan me npm builds pe OOM + slow chalti — isliye skip.
> - 🥈 **Oracle 4/24 = spare/second node** banega (multi-server + DNS cluster testing ke liye, Step 15) — delete nahi karna
> - 📄 Lightsail ka pura setup guide: **`aws-lightsail-setup-guide.md`** (plan, region, OS, firewall, credits bachane ke rules — sab kuch)
>
> Neeche Oracle wala section ab bhi valid hai (spare node ke liye).

## 2.1 Aapke Oracle Server Ka Sach (Important!)

| Point | Detail | Matlab |
|---|---|---|
| Aapka server | Ampere A1 — **ARM (aarch64)** | ✅ Hamara pura stack ARM pe chalega — koi dikkat nahi |
| RAM/CPU | 4 OCPU / 24 GB | Dev + testing ke liye **bahut badiya** hai |
| ⚠️ June 2026 ka change | Oracle ne free limit **2 OCPU/12 GB** kar di hai. Aapka 4/24 "purana/existing instance" hai | Agar ye instance kabhi terminate/delete **ho gaya** → 4/24 **wapas nahi milega**, sirf 2/12 |
| ⚠️ Idle Reclamation | Agar 7 din tak CPU + network + memory <20% rahe to Oracle instance **band kar sakta hai** | Hum roz kaam karenge to risk kam hai. Neeche protection ke 2 options diye hain |
| 📍 Region | Aapka instance kisi OCI region me hai (Mumbai = best for India) | Dev ke liye region matter nahi karta |
| 🎯 Iska role | **Sirf development + testing.** Production/business iske pe nahi hoga | Production ke liye paid server (Part 3) |

### Idle Reclamation se bachne ke 2 tarike:
1. **PAYG Upgrade (recommended option):** Oracle account ko "Pay As You Go" me upgrade karo (card add karna padta hai). Community + support ke mutabiq PAYG accounts pe idle-reclamation nahi lagta aur 4/24 milta hai. **Condition:** Always Free limits ke andar raho → bill ₹0. Lekin **billing alerts zaroor set karo** (₹1,000 limit pe alert) taaki galti se charge na lage. *Final confirmation Oracle support se le lena.*
2. **Light activity trick (temporary):** Har 10 minute ek chhoti CPU activity (cron job) — community me popular hai, lekin **guarantee nahi** hai kyunki Oracle 3 cheezein check karta hai (CPU + network + memory). Isliye isse sirf temporary protection samjho.

**Sabse important:** Maan ke chalo ki ye server "disposable" hai — jo bhi banao, **Git + daily backup** rakho (hum Step 0 me hi kar denge). Toh Oracle kabhi bhi kuch kare, aapka kaam safe rahega. ✅

## 2.2 Oracle Setup — Step-by-Step (Aaj hi kar sakte ho)

### STEP A1: Server ki details check karo (SSH me ye commands chalao)
```bash
# OS aur architecture
cat /etc/os-release | head -3
uname -m                    # aarch64 aayega (ARM hai to)

# Resources
nproc                       # CPU cores
free -h                     # RAM
df -h /                     # Disk space

# Network
curl -s ifconfig.me; echo   # Public IP
ip a | grep inet            # Private IP

# Firewall
sudo systemctl is-active firewalld
sudo iptables -L -n | head -10
```
➡️ **Ye output mujhe bhejo** — main aapke exact OS ke hisaab se aage ke commands tune karunga.

### STEP A2: OCI Console me Ports kholo (Security List)
**Kaise:** OCI Console → Networking → Virtual Cloud Networks → apna VCN → Security Lists → "Default Security List" → **Add Ingress Rules**

| Port(s) | Protocol | Kis liye | Kab kholna |
|---|---|---|---|
| 22 | TCP | SSH (aapka access) | ✅ Ab (already open hoga) |
| 80, 443 | TCP | HTTP/HTTPS websites | ✅ Ab |
| 2082, 2083 | TCP | Customer Panel (cPanel-jaisa) | ✅ Ab |
| 2086, 2087 | TCP | Admin Panel + Billing API | ✅ Ab |
| 2095, 2096 | TCP | Webmail | Step 7 pe |
| 25 | TCP | Email receive (SMTP) | Step 7 pe |
| 587, 465 | TCP | Email sending (submission) | Step 7 pe |
| 143, 993 | TCP | IMAP (email reading) | Step 7 pe |
| 110, 995 | TCP | POP3 | Step 7 pe |
| 21, 49152-65535 | TCP | FTP + passive ports | Step 6 pe |
| 53 | TCP + UDP | DNS (apna nameserver) | Step 9 pe |
| 3306 | TCP | MySQL remote access — ❌ **PUBLIC ME KABHI NA KHOLEIN** | Kabhi nahi |
| ICMP | All | Ping | Optional |

**Source CIDR:** `0.0.0.0/0` (sab ke liye) — kyunki ye public hosting server hai.
**Tip:** Ek hi rule me multiple ports likh sakte ho: `80,443,2082-2083,2086-2087`

### STEP A3: Oracle ka Special Problem — Port 25 (Email) 🚨
Oracle Cloud **outbound port 25 default se BLOCK** karta hai (spam rokne ke liye). Iska matlab:
- **Incoming email** (receive) → security list me 25 kholne pe kaam karega
- **Outgoing email** (bhejna) → block hai! Removal request karni padegi (Oracle support → Service Limit Request, "SMTP port 25 removal") — 1-2 din lagte hain, kuch cases me PAYG account chahiye
- **Abhi dev testing ke liye:** Problem nahi — hum mailing test ke liye port 587/relay use karenge. Ye cheez Step 7 (Email module) pe resolve karenge.

### STEP A4: Dev Environment Ready Karo (Step 1 me hoga — main script dunga)
Jab aap "Step 1 chalu karo" bologe, main ek **setup script** dunga jo ye sab install karega:
- Apache + Nginx + PHP-FPM (multi-version) + MariaDB + Exim + Dovecot + BIND + Pure-FTPd + Redis
- Firewall config (firewalld) + fail2ban
- Panel ka dev environment + Git repo
- Test pages + verification report

**Kaam ka time:** Script chalna ~30-60 min, aapko sirf command paste karna hai. ✅

### STEP A5: OCI pe Backup Habit (aaj se hi)
OCI Console → Compute → Instance → **Boot Volume → Create Backup** — ye manual snapshot hai. Har badi change se pehle ek backup le lena. (Free tier me limited backups milte hain.)

## 2.3 Oracle pe Hum Kya Banayenge (Dev Roadmap)
| Kya | Kab |
|---|---|
| Pura panel code + database | Steps 0-12 |
| Test accounts (apne dummy domains pe) | Steps 3 se |
| Mail/DNS testing (internal test domains) | Steps 7-9 |
| Speed/resource testing (kitne accounts chalte hain) | Step 11 |
| **Beta testing** (2-3 friendly users) | Step 12 ke baad |

---

# 🖥️ PART 3: SCENARIO B — Paid VPS / Dedicated Server (BAAD ME, Production ke liye)

## 3.1 Kab Kharidna Hai? (Abhi NAHI!)
❌ Abhi mat kharido. **Jab ye 3 cheezein ho jayein:**
1. ✅ MVP ke Steps 0-11 complete (panel chal raha ho)
2. ✅ Step 12 (billing API) bhi ready — kyunki pehla customer usi se aayega
3. ✅ Beta testing Oracle pe ho chuki ho (kuch bugs fix ho chuke)

**Timeline:** ~6-8 mahine me ye stage aayega. Tab tak Oracle pe kaam chalta rahega (₹0 kharcha).

## 3.2 Kahan Se Kharidna Hai? (Aapke case ke liye — India + Global customers)

| Priority | Provider | Plan | Price/mo | Kyun |
|---|---|---|---|---|
| 🥇 **India primary** | **GigaNodes** | Cloud S — 4 vCPU · 8 GB · 60 GB NVMe | **₹1,584** | Yotta DC Noida, UPI, GST invoice, EPYC, DDoS free. (Naya provider — reviews check kar lena) |
| 🥈 India alt | **Hostinger VPS** | KVM 2 — 2 vCPU · 4-8 GB | **₹599+** | Bada brand, India DC option |
| 🥉 India premium | **E2E Networks** | 4-8 GB cloud | **₹1,200-3,500** | Enterprise-grade, Delhi/Mumbai, GST |
| 🌍 **Global/EU** (2nd server baad me) | **Hetzner** | CPX31 — 4 vCPU · 8 GB · 160 GB NVMe | **~₹1,400** (€15) | Best price/performance. Free snapshots + DDoS |
| 💰 Sasta start (dev/test prod) | **Contabo** | Cloud VPS 10 — 8 GB RAM | **~₹450-630** | Sabse sasta RAM. Trade-off: IO/support slow |
| 🇺🇸 US customers (baad me) | **Vultr / Linode / DO** | 4 GB VPS | **~₹450-1,000** | Premium network + US locations |
| 🖥️ Dedicated (150+ customers) | **Hetzner Auction / AX41** | Ryzen · 64 GB · NVMe | **₹2,700-3,900** | Sabse smart scale-up path |

**Aapka first paid server (meri final recommendation):**
👉 **GigaNodes Cloud S (₹1,584/mo)** — kyunki aapke zyada customers India me honge (latency 10-40ms), UPI/GST payment, aur 8 GB RAM pe 30-60 accounts aaram se chalenge. Global customers baad me Hetzner EU pe 2nd node (hamara panel multi-server support karega — Step 15).

## 3.3 ORDER SE PEHLE — 12-Point Checklist ✅
Provider se ye 12 cheezein confirm karo (warna paisa barbaad):

| # | Check | Kyun zaroori | Kaise verify |
|---|---|---|---|
| 1 | **KVM virtualization** (OpenVZ/LXC NAHI) | Custom kernel features + quotas ke liye zaroori | Provider se puchho / plan page pe likha hota hai |
| 2 | **x86_64 architecture** | Production me full tool support | Plan page |
| 3 | **Root SSH access** | Panel ko root-level kaam karne honge | "Full root access" likha ho |
| 4 | 🔴 **Port 25 allowed?** | **Email hosting ke liye must!** | Support se puchho: *"Do you allow outbound port 25?"* |
| 5 | 🔴 **rDNS/PTR editable?** | Email deliverability (spam me na jaaye) | Support se puchho: *"Can I set custom rDNS/PTR?"* |
| 6 | **OS options me AlmaLinux 9 / Rocky 9** | Hamara standard stack | Order page pe OS list dekho |
| 7 | **Dedicated IPv4** (shared nahi) | SSL + email + panel ke liye | Plan me "1 IPv4" ho |
| 8 | **Snapshots/Backup option** | Disaster recovery | Weekly backup option ho |
| 9 | **DDoS protection** | Hosting business = target | Basic protection included ho |
| 10 | **Bandwidth (TB/month)** | Customer websites ka traffic | 5-20 TB minimum |
| 11 | **Renewal price** (intro offer nahi!) | Bahut providers pehle saal sasta, phir mehnga | Renewal price check karo |
| 12 | **Payment:** UPI/GST (India) ya card (foreign) | Business expense + ITC | Provider site |

## 3.4 VPS Order Karne Ka Process (Step-by-Step)
```
1. Provider ki site pe account banao
   → India me: PAN/GST/address verification (KYC)
   → Foreign: international card (forex markup ~2-3%)

2. Plan select karo (8 GB RAM se kam nahi!)
3. OS select karo: AlmaLinux 9 (ya Rocky Linux 9)
4. SSH Key add karo  ← main key banane ka tarika bata dunga, ya password method use karo (kam safe)
5. Hostname set karo: srv1.aapkapanel.com
6. Deploy! (2-5 minute me ready)
7. IP address + root password/key milega
8. SSH se login karo:
   ssh root@AAPKA_IP
9. Mera installer chalao (Step 1 me dunga):
   curl -sSL [installer-url] | bash
10. 30-60 min me pura hosting stack ready 🎉
```

## 3.5 Dedicated Server (Jab 150+ customers ho)
Same process, plus:
- Order karte waqt "**AlmaLinux 9 pre-installed**" request karo
- **RAID 1** (mirror) confirm karo — disk fail ho to data safe
- **IPMI/iDRAC access** maango (remote KVM — server boot level pe control)
- Provisioning time: 2-24 hours (VPS me minute hai)
- Setup fee ho sakti hai (Hetzner pe ~€39-50)

## 3.6 Oracle → Paid Server Migration (Jab Time Aayega)
1. Panel code: **Git se pull** (already hai)
2. Accounts ka data: hamara **export tool** (Step 10 me banega) — files, DBs, emails, DNS sab
3. Naya server: installer chalao (same one-click)
4. Data import tool se restore karo
5. DNS switch (nameservers/IP badlo)
6. **Downtime: 1-2 ghante** (raat me karo)

---

# ✅ PART 4: AAJ HI KYA KARNA HAI (Aapke 4 Action Items)

| # | Kaam | Time | Kya bhejna hai |
|---|---|---|---|
| **1** | Oracle instance pe SSH karke **Part 2.2 → STEP A1 ki commands** chalao | 10 min | Output copy karke mujhe bhejo |
| **2** | OCI Console me **ports kholo** (STEP A2 table — abhi sirf: 80, 443, 2082-2083, 2086-2087) | 10 min | "Ho gaya ✅" |
| **3** | Confirm karo: **Region kya hai?** (Mumbai/other), **Image kya hai?** (Oracle Linux 9? Ubuntu?), **Boot volume kitna GB?** | 5 min | Details bhejo |
| **4** | Decide: PAYG upgrade karna hai ya nahi (idle-reclaim protection ke liye — optional hai) | — | Haan/Nahi/Baad me |

## Phir kya? 
Jab ye 4 kaam ho jayein (ya saath-saath bhi kar sakte ho), aap bolo:

> **"Step 0 chalu karo"**

Main turant shuru kar dunga:
1. 📋 Requirements freeze document
2. 🗄️ Complete Database Schema (saare tables, relationships ka diagram)
3. 📁 Project folder structure + Git repo setup
4. 🔐 Security rules + permission matrix (kaun kya kar sakta hai)
5. ☁️ Oracle VPS hardening guide (aapke actual output ke hisaab se tuned)

Aur uske baad Step 1 (server stack + installer) — jisme aapko pehla **chalta hua result** milega. 🚀

---
*Note: Ye document progressive hai — jab hum paid server pe jayenge, main isme exact provider, plan aur command sequence update kar dunga (aapke final budget + customer location ke hisaab se).*
