# Runbook — Step 2B-1: Panel UI (login + dashboard) on port 8090

**Script:** `installer/panel-install.sh` · **Version:** v0.3.0 · **Download:** https://paste.rs/p4iDj (script sha256 `47bbd854f34b2b6c…`) · **Target:** Ubuntu 22.04 / 24.04 (x86_64)
**App:** Laravel 11 (`panel/`) · runs as **www-data**, never root (ADR-0002)
**Tested:** full 7-phase run on a Debian 13 container ✔ (deploy → migrate → admin → nginx on :8090 →
login POST 302 → dashboard HTTP 200 with live paneld data; audit + login_attempts rows verified)
**Time:** ~3–5 minutes (composer Laravel download)

---

## 1. Ek line me

Ye step **asli panel** ko zinda karta hai — `https://<server>:8090` par login page aur dashboard,
jisme server ki health **paneld agent se live** aati hai. Customer websites (Apache, port 80/443)
ko ye chhoota bhi nahi.

## 2. Kaise chalao

```bash
curl -sSL https://paste.rs/p4iDj -o /tmp/alphacp-panel.sh
```
```bash
sudo nohup bash /tmp/alphacp-panel.sh --yes > /tmp/alphacp-panel.log 2>&1 & sleep 5; tail -8 /tmp/alphacp-panel.log
```

Ek line me chalane ke liye:
```bash
curl -sSL https://paste.rs/p4iDj -o /tmp/alphacp-panel.sh && sudo nohup bash /tmp/alphacp-panel.sh --yes > /tmp/alphacp-panel.log 2>&1 & sleep 5; tail -8 /tmp/alphacp-panel.log
```

Khatam hone par script **username + password** print karega (password `var/panel-admin.txt` me bhi
save hota hai, root-only file).

### Flags

| Flag | Kaam |
|---|---|
| `--dry-run` | sirf plan, kuch na badlo |
| `--yes` | koi prompt nahi |
| `--force` | jo ho chuka use dobara karo (code update ke baad) |
| `--only=PHASES` | `preflight,packages,deploy,migrate,admin,serve,verify` |
| `--port=8090` | panel port badalna ho to |
| `--admin-user=` / `--admin-password=` | pehla admin khud set karo |
| `--allow-unsupported` | non-Ubuntu Debian family (dev tests) |

## 3. 6 phases

| Phase | Kaam |
|---|---|
| **0 Preflight** | root, OS, MariaDB, composer, PHP extensions, agent prerequisites |
| **1 Packages** | nginx + PHP extensions (mbstring, xml, curl, zip, intl, bcmath, gd, mysql) |
| **2 Deploy** | code → `/usr/local/alphacp/panel`, `composer install --no-dev`, `.env` + APP_KEY, permissions (root:www-data, storage writable), `database.env` → 0640 root:www-data |
| **3 Migrations** | `db/migrations/*.sql` (0001 core + 0002 panel core: users/roles/permissions/login_attempts) |
| **4 Admin** | `php artisan alphacp:create-admin` → random password (ya aapka diya hua) |
| **5 Serve** | self-signed TLS cert, nginx vhost on **8090**, **default nginx site hata diya** (port 80 Apache ka hai), php-fpm restart |
| **6 Verify** | `https://127.0.0.1:8090/login` → HTTP 200 check + Apache abhi bhi sites serve kar raha hai |

## 4. Bug reporting (koi bhi problem ho to)

```bash
sudo tail -30 /tmp/alphacp-panel.log
sudo tail -20 /var/log/alphacp-panel-install.log
sudo alphacp status
```

## 5. Panel me ab kya hai (Step 2B-1)

- **Login page** (cPanel-style card) + session auth (Laravel guard) + CSRF
- **Brute-force throttle** — 15 min me 5 galat try = 15 min block (cPHulk jaisa), har try `login_attempts` me
- **Dashboard** — memory, disk, CPU load, task queue, services table, module tiles, recent audit activity
- **Live data** — dashboard `tasks` table me `system.info` + `service.status` daalta hai → `paneld` (root) chalata hai → result wapas panel me
- **Audit trail** — login success/failure/logout sab `audit_logs` me (immutable)
- **Security** — CSP + X-Frame-Options + nosniff headers, `.env` 0640, code 0750, storage alag
- **cPanel layout** — 9 sections (Files, Email, Domains, Databases, Metrics, Security, Software, Advanced, Preferences) ke saare tiles, har tile par uska roadmap step

## 6. Abhi kya baaki hai (Step 2B-2, agla kaam)

| Kaam | Kya |
|---|---|
| **2FA (TOTP)** | QR code setup + recovery codes + login par code |
| **User Manager** | users banao (reseller/staff), role assign, suspend |
| **RBAC enforcement** | permission map har route par (abhi sirf login + role model ready hai) |
| **Change Password / Contact Info** | preferences pages |
| **Services control** | start/stop/restart buttons (paneld ke mutating tasks ke saath) |
| **Real TLS** | Let's Encrypt (self-signed ki jagah) |

## 7. AI handoff note

- Panel app: `panel/` (Laravel 11). Views `panel/resources/views/`, controllers `panel/app/Http/Controllers/`.
- **Credential rule:** panel kabhi apni `.env` me DB password nahi rakhta — `panel/app/Support/AcpEnv.php`
  `/usr/local/alphacp/etc/database.env` padhta hai (wahi file jo paneld padhta hai).
- **Root rule:** panel me kabhi `exec/shell_exec` nahi. Kaam chahiye? `app/Services/AgentQueue.php` se
  task queue karo — paneld chalayega (allowlist `agent/config/tasks.php`).
- Code badla → `python3 tools/build-panel-installer.py` → naya `panel-install.sh`.
- Naya migration = naya file `db/migrations/000X_*.sql` (purani file **kabhi edit na karo**).
