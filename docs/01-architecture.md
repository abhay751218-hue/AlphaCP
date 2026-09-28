# 01 — System Architecture

> **Hindi summary:** Ye document batata hai ki pura system kaise kaam karega — panels,
> root agent, services, license server, updater. Code likhne se pehle ye "naksha" hai.

**Status:** ✅ v1 design (Step 0)
**Related:** `02-database-schema.sql`, `03-security-matrix.md`, `05-license-system.md`, `06-installer-updater.md`

---

## 1. High-Level Diagram

```
                           ┌──────────────────────────────────────────────┐
   Billing software ─────► │  API LAYER                                   │
   (WHMCS/custom)          │  • WHM API 1 compat  :2086/:2087             │
                           │  • Native REST v1    /api/v1/*               │
                           └───────────────┬──────────────────────────────┘
                                           │
   Browsers (admin/     ┌──────────────────▼───────────────────┐
   reseller/client) ───►│  PANEL WEB APP (Laravel + Inertia)   │
   :2082/:2083/:2086/87 │  • Auth/RBAC/2FA  • Modules          │
                        │  • Policies       • Queue dispatch   │
                        └───────┬───────────────────┬──────────┘
                                │                   │ enqueue tasks
                          (panel DB)          (Redis queue)
                                │                   │
                        ┌───────▼──────┐    ┌───────▼────────────────────┐
                        │  MariaDB     │◄───│  paneld (root task agent)  │
                        │  panel DB    │    │  • polls `tasks` table     │
                        └──────────────┘    │  • allowlisted handlers    │
                                            │  • array-exec, no shell    │
                                            │  • writes task_logs        │
                                            └───────┬────────────────────┘
                                                    │ applies changes
        ┌──────────┬──────────┬──────────┬──────────┼──────────┬───────────┐
     Apache/Nginx  PHP-FPM   MariaDB    Exim/      Dovecot    BIND9      Pure-FTPd
     (vhosts)     (multi)   (customer   Postfix   (IMAP)     (DNS)      (+ Jailkit)
                             DBs)        (SMTP)
                                                    │
                                    ┌───────────────▼─────────────────┐
                                    │ Resource layer                  │
                                    │ • Linux user quotas (disk)      │
                                    │ • cgroups v2 (CPU/RAM/IO/NPROC) │
                                    │ • per-mailbox quota (Dovecot)   │
                                    └─────────────────────────────────┘

   ┌──────────────────┐   HTTPS    ┌───────────────────────────────┐
   │  Panel (local)   │───────────►│  LICENSE SERVER (central app) │
   │  license client  │  activate  │  • issues Ed25519 licenses    │
   └──────────────────┘  heartbeat │  • activations / heartbeat    │
                                  │  • customers, orders, reseller│
                                  └───────────────────────────────┘

   ┌──────────────────┐   HTTPS    ┌───────────────────────────────┐
   │  alphacp CLI     │───────────►│  RELEASES (CDN/storage)       │
   │  update/rollback │  signed    │  • signed tarballs + manifest │
   └──────────────────┘  pulls     └───────────────────────────────┘
```

## 2. Process Model (on a panel server)

| Process | User | Purpose | systemd unit |
|---|---|---|---|
| Web (php-fpm pool `alphacp`) | `alphacp` | Panel UI + APIs | `alphacp-web` (nginx vhost) |
| Queue worker | `alphacp` | Async jobs (emails, syncs, long ops) | `alphacp-worker` |
| Scheduler | `alphacp` | Cron-like: usage sync, license heartbeat, backups trigger | `alphacp-scheduler` |
| **paneld** (task agent) | `root` | Executes allowlisted privileged tasks | `paneld` |
| Node agent (on nodes) | `root` | Same agent, pulls tasks for its node from central DB | `paneld-node` |

**Isolation rule:** panel code never has root; agent code is minimal, audited, and has no web
interface (only queue + local socket for health).

## 3. Multi-Server (Node) Topology — Step 15

```
        Central Panel (with License)                 Nodes
   ┌──────────────────────────────┐        ┌────────────────────────┐
   │  accounts.meta: server_id    │        │  paneld-node           │
   │  tasks (routed per node)     │───────►│  executes + reports    │
   │  server_metrics aggregation  │        │  local services        │
   └──────────────────────────────┘        └────────────────────────┘
```

- Central panel owns the DB; nodes authenticate with per-node tokens (`servers.agent_token_hash`).
- Nodes poll for tasks addressed to them (long-poll over HTTPS with mTLS or signed tokens).
- DNS can be clustered (`dns_cluster_members`) so zones live on ≥2 servers.

## 4. Privileged Agent Design (critical)

**Problem:** panel needs to create Linux users, write configs, restart services — all root work.
**Solution:** a minimal root worker + strict task contract.

### 4.1 Task lifecycle
```
Panel service            DB `tasks`                 paneld (root)                Services
─────────────           ───────────                ─────────────                ────────
validate input  ──────► INSERT(task)  ──poll──────► claim (FOR UPDATE SKIP LOCKED)
                                                    validate payload (schema)
                                                    run handler (allowlist)
                                                    log lines ──────────► task_logs
control plane stores    UPDATE status  ◄─────────── result/error
```

### 4.2 Hard rules
1. Handlers are **allowlisted by task type** in `agent/config/tasks.php` with a safety class:
   `readonly` | `mutating` | `destructive`.
2. All external commands use **array-form exec** (`['/usr/sbin/useradd', '-M', $user]`),
   never a shell string. No pipes/redirections inside commands.
3. Every path argument is validated against a canonical allowlist
   (`/home/<user>/...`, `/etc/...` templates, `/usr/local/alphacp/...`), with
   `realpath` + prefix checks and symlink protection.
4. Payloads validated against a per-task JSON schema before execution.
5. Every task is idempotent where possible; destructive tasks require explicit
   `confirm: true` + audit entry with the requesting admin id.
6. Timeouts per task; on timeout → mark failed, never leave partial state silently
   (handlers implement compensating actions / rollback lists).
7. Agent never talks to the internet except: license heartbeat (panel does that) and
   update pulls (CLI). No inbound ports for the agent.

### 4.3 Example task types (v1)
`account.create`, `account.suspend`, `account.unsuspend`, `account.terminate`, `account.setQuota`,
`domain.create`, `domain.delete`, `ssl.issue`, `ssl.renew`, `mail.createMailbox`, `mail.deleteMailbox`,
`mail.setQuota`, `db.create`, `db.delete`, `dns.addZone`, `dns.addRecord`, `dns.deleteRecord`,
`backup.run`, `backup.restore`, `service.reload`, `service.status`, `usage.sync`, `ftp.create`,
`cron.set`, `php.setVersion`, `files.*` (scoped ops), `security.scan`, `license.nodeEnroll`.

## 5. Data Flows (canonical examples)

### 5.1 Account create (from billing or admin UI)
1. API/UI validates → creates `accounts` row (`status=pending`) → enqueues `account.create` task(s).
2. Agent steps (each logged, rollback-aware):
   `useradd` → home skeleton → disk quota → cgroups limits → vhost (web) → DNS zone →
   mail domain + DKIM → default FTP → SSL order (ACME) → welcome email (queue).
3. On success: `status=active`, `setup_completed_at` set, webhook `account.created` fired.
4. On failure at step N: compensating actions undo steps 1..N-1; account stays `pending` with
   error; support notified. **Never a half-created silent account.**

### 5.2 Suspend / Unsuspend
- Suspend: web vhost → suspension page, mail delivery paused (queue), cron disabled, FTP off.
  Files/DB untouched. `account_events` + audit row.
- Unsuspend: reverse. Both idempotent.

### 5.3 Usage sync (feeds billing `showbw`, quotas, alerts)
- Scheduler every 15 min: agent `usage.sync` collects per-account disk, inodes, mailbox sizes,
  bandwidth counters → `account_usage_daily`, `bandwidth_daily`, `mailbox_usage`.
- Threshold engine: 80/90/100% → notification + optional auto-suspend policy.

### 5.4 Backup
- Scheduler → `backup.run` task → tar/zstd streams (files, DBs, mail config) → destination
  (local + remote S3/SFTP) → `backups` row + checksum. Restore = `backup.restore` with
  per-item selection, staged into a temp area before swap, with pre-restore safety snapshot.

## 6. Security Zones

| Zone | Trust | Runs as | Can touch |
|---|---|---|---|
| Panel web | untrusted input | `alphacp` | panel DB, queue, local files of panel only |
| Queue worker | medium | `alphacp` | panel DB + agent tasks |
| Agent | high | `root` | privileged ops via handlers only |
| Customer sites | untrusted | per-account user | own home dir only (jailed) |
| Node agent | high | `root` | same as agent, node-scoped |

Details and matrices: `docs/03-security-matrix.md`.

## 7. License Integration Points (summary — full design in doc 05)

| Point | Where | Behavior |
|---|---|---|
| Activate | First run / admin UI / CLI | POST license → verify signature → store |
| Heartbeat | Scheduler daily | POST heartbeat; refresh payload |
| Grace | If heartbeat fails | Keep working `grace_days`; then degrade panel (no new accounts, admin banner) |
| Feature flags | License payload | Gate optional modules per tier |
| Node licenses | Multi-server | Central license + per-node license check |
| Never | — | Never stop customer websites/email due to license |

## 8. Updater Architecture (summary — full design in doc 06)

- Releases: `releases/{channel}/{version}/alphacp-{version}.tar.gz` + `.sig` + `manifest.json`.
- Install layout: `/usr/local/alphacp/{releases/<v>, current→symlink, etc, var, logs}`.
- Update: verify signature → preflight (disk/RAM/version) → backup panel DB → extract new
  release → run migrations → atomic symlink swap → restart services → health check →
  success (or auto-rollback to previous release).
- Admin UI one-click = queue job that calls the same CLI (`alphacp update`) and streams
  `task_logs` to the browser.

## 9. Key Design Decisions (see `07-decision-log.md`)

1. PHP/Laravel + React/Inertia (fast solo+AI development, WHMCS ecosystem familiarity).
2. Root agent with task allowlist (not a root web app).
3. MariaDB for panel DB; customer DBs on same engine (dev) / separate instance (prod option).
4. WHM API 1 compatibility as a first-class contract.
5. Ed25519 signed licenses + heartbeat + grace; degrade panel, never customer services.
6. Atomic releases + signed updates for one-click upgrade with rollback.
7. Module blueprint pattern enforced for AI-maintainability.
