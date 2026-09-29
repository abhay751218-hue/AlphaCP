# Runbook — Step 1: Base hosting stack install (dev-srv1)

**Script:** `installer/install.sh` · **Version:** v0.1.1 · **Target:** Ubuntu 22.04 / 24.04 (x86_64)
**Owner:** user (runs it) + AI (fixes issues) · **Time:** ~8–15 min (mostly package downloads)

---

## 1. Ye script kya karti hai (one line)

`dev-srv1` par hosting stack ka **foundation** khada karti hai — taaki Step 2 se aage har module (panel, mail, DNS, DB) isi base par bane. **Koi bhi customer-facing service abhi start nahi hoti** (security by default).

## 2. Kaise chalao (ek line)

```bash
curl -sSL https://paste.rs/5zVYf -o /tmp/alphacp-step1.sh && sudo bash /tmp/alphacp-step1.sh
```

> `sudo` zaroori hai. Root check script ke andar bhi hai — galat user se chalane par error dega.

### 2.1 ⚠️ Mobile / Lightsail browser-terminal hai? → NOHUP pattern use karo

Lightsail ka browser terminal beech me toot jata hai (*"Unable to connect … CLIENT_TIMEOUT"* — phone lock, weak network, ya idle timeout). Us waqt foreground me chalta script **SIGHUP** se mar jata hai. Isliye long install hamesha background me chalao:

```bash
# 1) download (agar pehle se nahi hai)
curl -sSL https://paste.rs/5zVYf -o /tmp/alphacp-step1.sh

# 2) background me start — browser band ho jaye to bhi chalta rahega
sudo nohup bash /tmp/alphacp-step1.sh --yes > /tmp/alphacp-console.log 2>&1 &

# 3) live dekho
sleep 3 && tail -5 /tmp/alphacp-console.log
```

- **Live watch:** `tail -f /tmp/alphacp-console.log` — `Ctrl+C` sirf watch band karta hai, install **nahi**.
- **Beech me progress check** (browser baar-baar toote to bhi ye kaam karega):
  ```bash
  echo "=== STATUS ==="; pgrep -f alphacp-step1 >/dev/null && echo "RUNNING" || echo "STOPPED"; echo "=== LOG ==="; tail -15 /tmp/alphacp-console.log
  ```
  (Dhyan: `run()` command output `/var/log/alphacp-install.log` me jata hai — console log me status lines `[i] [OK] ==> Phase` dikhte hain, wo kaafi hain.)
- **Khatam hone ka signal:** log me `==> Result` + `PASS: n  FAIL: n  WARN: n` line aa jaye.
- **Khatam hua?** `pgrep -f alphacp-step1 || echo DONE`

**Agar install ke BEECH connection toota** (aapke saath abhi yahi hua):

```bash
# a) Script abhi bhi chal raha hai ya nahi?
pgrep -af alphacp-step1 || echo "STOPPED"

# b) STOPPED aaye to — apt adha ruk gaya ho to theek karo, phir nohup se dobara start
sudo dpkg --configure -a
sudo nohup bash /tmp/alphacp-step1.sh --yes > /tmp/alphacp-console.log 2>&1 &
sleep 3 && tail -5 /tmp/alphacp-console.log
```

> Script **idempotent** hai: jo ho chuka use skip kar deti hai, jo adhura hai use poora karti hai. Aadha adhura instal dobara chalane se kuch tootta nahi. (`--yes` prompts hata deta hai — background me prompts kaam nahi karte.)

## 3. Pehle kya hota hai — 4 phases

| Phase | Kaam |
|---|---|
| `preflight` | root / OS / arch / network / RAM / disk check, `/usr/local/alphacp` layout, state file |
| `packages` | Apache + PHP 8.3-FPM (+7.4/8.1/8.2/8.4 best-effort), Nginx, MariaDB, Redis, BIND9, mail pkgs, Pure-FTPd, fail2ban, UFW, quota tools, Composer, Node 20 |
| `services` | Apache (mpm_event → PHP-FPM), MariaDB hardening (127.0.0.1 only), Redis local-only, BIND9 resolver-switch (auto-rollback on DNS fail), mail/FTP **installed-but-stopped**, UFW port map, quotas (fstab), 2 GB swap (agar nahi hai), fail2ban SSH jail, `alphacp` CLI |
| `verify` | 26 self-checks → report file + PASS/FAIL/WARN summary |

## 4. Flags (agar zaroorat pade)

```bash
sudo bash /tmp/alphacp-step1.sh --dry-run            # kuch install na karo, sirf dikhao (safe rehearse)
sudo bash /tmp/alphacp-step1.sh --only=services      # sirf ek phase dohrao (packages ja chuke hain to)
sudo bash /tmp/alphacp-step1.sh --profile=prod       # ClamAV daemon bhi (Lightsail par skip karte hain)
sudo bash /tmp/alphacp-step1.sh --yes                # sab prompts haan (non-interactive)
```

Script **idempotent** hai — dobara chalane se kuch toot-ta nahi; jo ho chuka wo skip ho jata hai.

## 5. Run ke baad — mujhe ye paste karo

```bash
# 1) summary
cat /usr/local/alphacp/logs/verify-report-*.txt

# 2) agar kuch FAIL ho to tail
tail -40 /var/log/alphacp-install.log

# 3) quick glance
alphacp status
```

## 6. Paths (yaad rakhne wale)

| Kya | Path |
|---|---|
| Install log | `/var/log/alphacp-install.log` |
| State file (resume) | `/usr/local/alphacp/var/install.state` |
| Verify report | `/usr/local/alphacp/logs/verify-report-*.txt` |
| Panel layout root | `/usr/local/alphacp/{releases,current,etc,var,logs}` |
| Default site (aata hai http://IP par) | `/var/www/alphacp-default/` |
| CLI | `alphacp status` · `alphacp doctor [--fix]` · `alphacp version` |

## 7. Known Lightsail notes

- **Port 25 outbound blocked** (AWS default) → mail module (Step 7) dev me 587/relay par chalega. Ye Step 1 ko affect nahi karta (mail services abhi stopped hain).
- **Snapshot** leta raho: deploy/steps ke beech me Lightsail snapshot manual bhi accha habit hai.
- DNS resolver switch (systemd-resolved → static + BIND9) — agar verify me public DNS fail ho, script **khud purana resolv.conf wapas** rakh deti hai.

## 8. Common problems

| Problem | Fix |
|---|---|
| `apt lock` (dpkg frontend lock) | koi doosra apt/unattended-upgrade chal raha hai → 2-3 min ruk kar phir se chalao |
| `BIND9 start fail` — port 53 busy | script pehle resolved ko disable karta hai; fir bhi fail ho to `tail` report bhejo |
| Memory tight (4 GB) | expected in dev; `--profile=dev` ClamAV skip karta hai. Step 7 ke time tune karenge |
| `Verify: some critical checks failed` | ghabrao nahi — FAIL list + report paste karo, main fix deta hoon, phir `--only=verify` dobara |
| `CLIENT_TIMEOUT` / "Unable to connect" (browser terminal) | install foreground me tha → pura ruk gaya hoga. Section 2.1 ka nohup pattern use karo |
| Re-run par `Could not get lock /var/lib/dpkg/lock` | `sudo dpkg --configure -a` chalao, phir dobara start |
| **Apache FAIL** + "site can't be reached / connection refused" | Nginx ne :80 pehle le liya tha (v0.1.0 bug) → fix: `sudo systemctl stop nginx && sudo systemctl disable nginx && sudo systemctl restart apache2` — v0.1.1 me installer khud ye order fix karta hai |
| `quota enforcement on /` WARN | v0.1.0 me fstab options galat the (ext4 ke liye XFS wale). v0.1.1 sahi karta hai; enforce hone ke liye ek **reboot** chahiye |
| Kisi bhi service ka FAIL | `sudo bash /tmp/alphacp-step1.sh --only=services,verify --force --yes` — services phase dobara configure + restart karta hai |

## 9. Safety guarantees (Step 1 ke liye)

- Customer sites/mail/DB **koi bhi running service** nahi — sirf base + Apache default page.
- MariaDB/Redis sirf `127.0.0.1` par bind (bahar se band).
- UFW enable hone se pehle SSH (22) allow kiya jata hai — session nahi tootega.
- Kuch bhi galat ho to: `alphacp doctor` + report, ya AI se `installer/install.sh` ka phase dobara chalwao.

## 10. AI handoff note

Agar ye runbook kisi naye AI ko diya jaye: installer ka har phase alag function hai (`phase_preflight`, `phase_packages`, `phase_services`, `phase_verify`). Common edits:
- **Naya package** → `phase_packages` me `apt_install <pkg>` (zaroori) ya `apt_install_try <pkg>` (best-effort) line add karo.
- **Naya port** → `phase_services` ke `# 2.9 FIREWALL (UFW)` block me `run ufw allow <port>/tcp comment '<name>'`.
- **Naya check** → `phase_verify` me `vcheck "naam" "$(... && echo 1 || echo 0)" "hint" <0|1 critical>`.
- **Naya config file** → `write_conf <path>` helper use karo (wo dry-run + backup dono handle karta hai).

Details: `docs/06-installer-updater.md`, `AGENTS.md`, `AI_CONTEXT.md`.
