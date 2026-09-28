# Runbook — Step 2A: paneld agent + task queue + CLI v0.2

**Script:** `installer/step2-install.sh` · **Version:** v0.2.0 · **Target:** Ubuntu 22.04 / 24.04 (x86_64)
**Tested:** end-to-end on a fresh Debian 13 container (install → migrate → agent → queue → result) ✔  
**Verified on dev-srv1:** 28 Sep 2026 — install complete, `agent.ping` queue test **success in 2 ms** ✔
**Time:** ~2–3 minutes

---

## 1. Ek line me

Ye step server par **root task agent (`paneld`)** aur **task queue** khada karta hai. Aaj se panel kabhi khud root kaam nahi karega — wo sirf queue me kaam daalega, aur `paneld` use allowlist ke hisaab se chalayega. Yahi cPanel-jaise system ki **security ki neev** hai.

## 2. Kaise chalao

```bash
curl -sSL https://paste.rs/cSG7g -o /tmp/alphacp-step2.sh
```
```bash
sudo nohup bash /tmp/alphacp-step2.sh --yes --server-name=dev-srv1 > /tmp/alphacp-step2.log 2>&1 & sleep 3; tail -5 /tmp/alphacp-step2.log
```

`--server-name=dev-srv1` se panel me server ka naam theek dikhega (Lightsail hostname technical hota hai).

**Note:** ye script **apne aap sudo** le leti hai, lekin background (`nohup`) ke liye `sudo` + `--yes` dono lagana behtar hai (prompts background me kaam nahi karte).

### Flags

| Flag | Kaam |
|---|---|
| `--dry-run` | sirf plan dikhao, kuch na badlo |
| `--yes` | koi prompt nahi (background-friendly) |
| `--force` | jo ho chuka use dobara karo (upgrade/reinstall) |
| `--server-name=NAME` | servers table me naam |
| `--allow-unsupported` | non-Ubuntu Debian-family par test ke liye |

## 3. Script kya karti hai (4 phases)

| Phase | Kaam |
|---|---|
| **0 Preflight** | root, OS (Ubuntu 22.04/24.04), MariaDB reachable, PHP + pdo_mysql |
| **1 Database** | `alphacp` DB + `alphacp` user (random 28-char password) → `etc/database.env` + `etc/my.cnf` (dono **0600**) |
| **2 Migrations** | `db/migrations/*.sql` apply, `schema_migrations` me track (dobara chalane par skip) |
| **3 Code** | agent → `/usr/local/alphacp/agent`, CLI → `/usr/local/bin/alphacp`, server row register, systemd unit `paneld.service` enable+start |
| **4 Verify** | `paneld --selftest` + **asli queue test** (`agent.ping` queue karke jawab ka intezar) + report file |

## 4. Payload = self-contained

Script ke andar agent ka poora code base64 tar me embedded hai (22 files, sha256-verified). Repo se rebuild karna ho:

```bash
python3 tools/build-step2-installer.py     # agent/, cli/, db/ badalne ke baad
```

Isliye: **repo = source of truth**, jo server par jata hai wo generated + checksum-protected hai.

## 5. Run ke baad ye paste karo

```bash
sudo alphacp status
sudo alphacp doctor
sudo alphacp task types
sudo alphacp task run system.info
sudo alphacp task run service.status
```

## 6. CLI cheat-sheet (v0.2.0)

| Command | Kaam |
|---|---|
| `sudo alphacp status` | services + queue + resources ek nazar me |
| `sudo alphacp doctor [--fix]` | 16 checks (+auto-fix paneld/stale tasks) |
| `sudo alphacp task types` | allowlisted tasks (type · safety · description) |
| `sudo alphacp task run TYPE [--payload='{...}']` | task queue me daalo aur result dekho |
| `sudo alphacp task list [--status=success] [--limit=20]` | recent tasks |
| `sudo alphacp task show ID` | task ka poora record + live logs |
| `sudo alphacp agent [status\|selftest\|restart\|recover]` | agent ke andar ki baat |
| `sudo alphacp logs paneld` | agent log tail |

## 7. Agent ke rules (Step 2A me lagu)

1. **Allowlist** — `agent/config/tasks.php` me jo task nahi, wo kabhi nahi chalega.
2. **Safety classes** — `readonly` · `mutating` · `destructive` (destructive ke liye payload me `_confirm` zaroori).
3. **JSON-schema validation** — kharab payload = task reject (retry nahi).
4. **Array-exec only** — shell string kabhi nahi; binary bhi allowlist me hona chahiye (`/bin/sh` blocked).
5. **PathGuard** — har path allowlisted root ke andar, `..` escapes blocked.
6. **Audit** — har task `audit_logs` me, reject hone par `security.agent.rejected` (critical).
7. **Crash-safe** — 20 min se zyada `running` atka task apne aap requeue/fail hota hai.

## 8. Troubleshooting

| Problem | Fix |
|---|---|
| `queue test FAILED` | `sudo alphacp logs paneld` + `sudo systemctl status paneld` — output paste karo |
| `paneld service active` nahi dikha | `sudo systemctl restart paneld && sudo journalctl -u paneld -n 20` |
| task `queued` me atka rah gaya | `sudo alphacp agent recover` phir `sudo alphacp task run <type>` |
| `unknown task type` | `sudo alphacp task types` se sahi naam lo |
| DB password bhool gaye | `sudo cat /usr/local/alphacp/etc/database.env` (root-only) |
| Migration fail | DB waisa hi rehta hai; `--force` se dobara chalao, error paste karo |

## 9. AI handoff note

- Naya task: `agent/config/tasks.php` me entry (handler + safety + schema + timeout) aur `agent/src/Tasks/Xxx.php` (implements `TaskInterface`, `handle(array $payload, TaskContext $ctx): array`).
- Naya command: `agent/src/Cli.php` me `match` branch + `usage()`.
- Naya CLI subcommand: `cli/alphacp` me `cmd_*` + `main()` ke case.
- DB badla to: naya `db/migrations/000X_*.sql` (purane file ko **kabhi edit na karo**).
- Code badalne ke baad **`python3 tools/build-step2-installer.py`** chalana zaroori hai, phir `agent/tests/run-tests.php`.
