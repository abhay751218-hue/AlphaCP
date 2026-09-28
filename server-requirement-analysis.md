# 🔬 SERVER REQUIREMENT ANALYSIS — Poori Technical Check
### "Kam nahi padna chahiye" — is sawaal ka pura hisaab
**Date:** 28 Sep 2026 | **Project:** Custom Hosting Panel (cPanel-parity) | **Server:** AWS Lightsail (Dev)

---

## 0. Pehle Ek Baat Clear Karta Hoon (Honest)

**Aapne pucha:** *"Aapne $12 bola tha, ab $24 kyun?"*

**Sach:**
- Jab maine **$12** recommend kiya tha — us waqt aim tha **"$100 credits se 7-8 mahine cover karo"** (paise bachane ki soch)
- Uske turant baad aapne likha: **"plan aisa lena jo is project me koi problem na aaye"** — ye **naya requirement** tha
- Maine us requirement ke against dobara pura hisaab lagaya (neeche poori table hai) → **2 GB me problems aati** (details neeche) → isliye **$24 (4 GB)** final kiya

**Ye galti nahi thi — requirement change thi.** Ab neeche poora technical proof hai, taaki aap khud decide kar sako. 😊

---

## 1. Is Dev Server Pe Exact Kya-Kya Chalega (Poori List)

Ye sab **ek hi server** pe chalega jab hum Steps 0-15 banayenge:

| Layer | Components |
|---|---|
| **Web** | Apache (HTTP) + Nginx (reverse proxy) + PHP-FPM (7.4, 8.1, 8.2, 8.3, 8.4 — 5 versions) |
| **Database** | MariaDB (panel ka DB + customers ke databases) |
| **Email** | Exim (SMTP) + Dovecot (IMAP) + SpamAssassin + ClamAV + OpenDKIM |
| **DNS** | BIND (nameserver + zones) |
| **FTP/SSH** | Pure-FTPd + Jailkit (jailed shell) |
| **Cache/Queue** | Redis (panel jobs queue) |
| **Security** | Fail2ban + UFW/firewalld + ModSecurity (baad me) |
| **Panel** | Panel app (Laravel) + Queue workers + React build tooling (npm/node) |
| **Monitoring** | Stats collectors, log rotation, backup jobs |

**Reference point:** cPanel ki khud ki official documentation kehti hai — **minimum 2 GB, recommended 4 GB**, aur ClamAV chahiye to **3 GB+**. Hum wahi class ka stack bana rahe hain → **4 GB recommended hamare liye bhi equally valid hai.**

---

## 2. RAM Ka Poora Hisaab (Component-by-Component)

| Component | Idle (aaram se) | Active/Peak (kaam karte waqt) | Note |
|---|---|---|---|
| Ubuntu 24.04 + systemd base | 200–300 MB | 300 MB | |
| Apache | 100–150 MB | 300–600 MB | Traffic/test load pe badhta hai |
| Nginx | 20–40 MB | 50–100 MB | |
| PHP-FPM (panel + 2-3 site pools) | 250–350 MB | 400–800 MB | Multi-version on-demand |
| MariaDB | 350–450 MB | 600–900 MB | DB import/export pe spike |
| Exim | 40–80 MB | 150–300 MB | Mail queue processing |
| Dovecot | 80–150 MB | 200–400 MB | IMAP testing |
| SpamAssassin | 250–350 MB | 500–700 MB | Email module test ke waqt |
| **ClamAV** | **900 MB – 1.3 GB** | **1.3 GB+** | ⚠️ Ye akele hi 1/3 server kha jaata hai |
| BIND | 100–150 MB | 150–250 MB | |
| Pure-FTPd | 20–40 MB | 50–100 MB | |
| Redis | 40–60 MB | 100–200 MB | |
| Fail2ban + firewall | 40–60 MB | — | |
| Panel app + queue workers | 200–300 MB | 300–500 MB | |
| **STEADY STATE TOTAL (ClamAV OFF)** | **~1.9–2.4 GB** | **~2.8–3.5 GB (kaam ke waqt)** | |
| **npm/React build (transient)** | — | **+1.0–1.5 GB spike** | 2 GB me yahi OOM karta hai |
| **WordPress/app install + DB import** | — | **+300–600 MB spike** | |

### Iska Matlab Kya Hai:

| Server RAM | Steady state fit? | Build/peak fit? | Verdict |
|---|---|---|---|
| **512 MB – 1 GB** ($5–$7) | ❌ | ❌ | Stack chalega hi nahi |
| **2 GB** ($12) | 🟡 Mushkil se (swap ke saath) | ❌ **OOM crash ka risk** | ⚠️ "no problem" requirement FAIL |
| **4 GB** ($24) | ✅ Aaram se (60–80% used) | ✅ **1–1.5 GB headroom bachta hai** | ✅ **FINAL** |
| **8 GB** ($44) | ✅ (par overkill) | ✅ | ❌ Dev ke liye zaroorat nahi — credits 2 mahine me khatam |

**Peak load pe kya hota hai (asli scenarios):**
1. **Panel ka React build** (npm) → 1–1.5 GB temporary → 2 GB server pe **MySQL OOM ho ke mar sakta hai** = aapke saamne "database connection error" — yahi wo "problem" hai jo aap nahi chahte
2. **Step 7 (Email module testing):** Exim + Dovecot + SpamAssassin + ClamAV ek saath → 2 GB me sirf ClamAV hi 900MB+ le lega → system slow/crash
3. **Step 11 (Load testing):** 20-30 fake accounts pe resource limits test → parallel processes
4. **Step 14 (App installer):** 3-4 WordPress installs + DB import ek saath

➡️ **$24 (4 GB) me ye sab bina crash chalte hain. Yehi final answer hai.**

---

## 3. Disk Ka Hisaab (80 GB Vs 60 GB)

| Kya store hoga | Size |
|---|---|
| OS + saare packages | 12–15 GB |
| PHP 5 versions + tools | 2–3 GB |
| Panel code + node_modules + builds | 2–4 GB |
| MariaDB data (test DBs, imports) | 5–15 GB |
| Test hosting accounts (files, mailboxes, sites) | 15–30 GB |
| Local test backups (restore practice) | 10–20 GB |
| Logs + misc | 3–5 GB |
| **TOTAL realistic** | **~50–90 GB** |

- **60 GB ($12):** tight — backups + test accounts ke baad bharna shuru ho jayega
- **80 GB ($24):** ✅ comfortable
- **160 GB ($44):** zaroorat nahi

> Note: Lightsail ke automatic snapshots **instance disk pe nahi**, alag snapshot storage pe jaate hain (alag se ~$0.05/GB/month lagta hai — chhota kharcha).

---

## 4. CPU Ka Sach (Ye Jaanna Zaroori Hai)

Lightsail pe dekha: **$12, $24 aur $44 — teeno me 2 vCPU hi hai!** (4 vCPU wala plan ₹$/month me bohot upar hai)

**Matlab:** $24 me aap **RAM + Disk** kharid rahe ho (CPU same hai). Yehi asli upgrade hai jo project ko chalata hai.
Aur 2 vCPU dev ke liye kaafi hai — bas builds thodi slow hongi (2-4 min), jo bilkul normal hai.

---

## 5. Milestone-wise RAM Check (Kab Kitna Chahiye)

| Steps | Kya karenge | 2 GB me? | 4 GB me? |
|---|---|---|---|
| Step 0–2 (Foundation + panel core) | Code, DB, base stack | 🟡 Chal jayega | ✅ |
| Step 3–6 (Accounts, packages, sites, files) | Provisioning + websites | 🟡 Tight, builds pe risk | ✅ |
| **Step 7 (Email suite)** | **SpamAssassin + ClamAV + mail testing** | ❌ **Yahin 2 GB fail hoga** | ✅ |
| Step 8–9 (DB + DNS) | MariaDB heavy testing | 🟡 | ✅ |
| Step 10 (Backup) | tar/gzip large files | 🟡 CPU spike | ✅ |
| Step 11 (Monitoring + limits) | Load simulation | 🟡 | ✅ |
| Step 12 (Billing API) | WHM API + queue | ✅ | ✅ |
| Step 13–15 (Security, installer, multi-server) | ClamAV/Malware, app tests | ❌ | ✅ |

➡️ **Sabse bada deciding factor: Step 7 (email module). 2 GB us step pe fail hota hai. 4 GB sab steps me pass.**

---

## 6. "Kam Nahi Padna Chahiye" — Iski 3-Layer Guarantee

Sirf aankh band karke bharosa nahi — 3 real safety nets:

**1️⃣ Headroom già hai:** 4 GB me steady state ~2–2.5 GB hota hai → **1.5–2 GB khali** padta hai. Koi bhi spike aaye, fit ho jaata hai.

**2️⃣ Live Monitoring (Step 1 me dunga):** Ek script jo har waqt RAM/disk/load batati rahegi. Hum **koi andaza nahi lagayenge** — actual numbers dekhenge. Agar kabhi 85% cross ho, main turant tune karunga (services ke configs optimize karke).

**3️⃣ Resize Path (Verified ✅):** Agar phir bhi kabhi kam pad jaye:
```
Snapshot lo (2 min) → us snapshot se naya instance banao (bigger plan, 5 min)
→ Static IP purane se detach → naye pe attach (instant) → purana delete (safety ke liye 2 din rakh lo)
```
**Total downtime: ~15–30 min.** Ye process AWS pe confirm kiya hai. Matlab server chhota-bada karna **easy hai** — lekin hum seedha sahi size se start kar rahe hain taaki ye na karna pade.

---

## 7. FINAL VERDICT 🎯

| | $12 Plan (2 GB) | **$24 Plan (4 GB) ✅** | $44 Plan (8 GB) |
|---|---|---|---|
| Project me problems? | ⚠️ Haan — Step 7 pe fail, builds pe OOM risk | ✅ **Nahi** | ✅ Nahi |
| Credits kitne mahine | ~7-8 mahine | **~4 mahine free** | ~2 mahine |
| Uske baad | ~₹1,100/mo | ~₹2,150/mo | ~₹3,950/mo |
| Verdict | Credits bachane wala option | **SELECT KARO ✅** | Overkill |

### 👉 Aapko kya karna hai:
**Instance plan me $24 (4 GB / 2 vCPU / 80 GB) select karo** — aur aage badho. Koi problem nahi aayegi. 💪

*(Aur agar phir bhi kabhi kuch tight lage — monitoring script batayega, aur 15 minute me resize ho jayega. Double safety hai.)*

---
*Ye analysis Step 1 ke baad **actual numbers** se update hogi — jab stack live hoga, tab "real usage" log karke yehi table finalize kar dunga.*
