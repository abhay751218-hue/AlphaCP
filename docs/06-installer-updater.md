# 06 — One-Click Installer & One-Click Updater

> **Hindi summary:** cPanel jaisa installation experience: **ek command** se pura server ready,
> aur **ek click** se panel update. Tumhari requirement thi — isliye ye design pehle din se hai.

**Status:** ✅ v1 design
**Components:** `installer/` (bash bootstrap + PHP payload) + `alphacp` CLI + admin UI

---

## 1. Installer — User Experience

```bash
# Fresh server pe (root):
curl -sSL https://get.alphacp.com | bash
# ya download karke:
bash alphacp-installer.sh --auto --hostname=srv1.example.com --email=admin@example.com
```

Total time target: **< 60 minutes** (fresh Ubuntu 24.04, 4 GB RAM), one command, ~5 prompts.

## 2. Installer Architecture

```
get.alphacp.com/install.sh             ← tiny bootstrap (bash), ~200 lines
   │  detects OS/arch, checks resources
   ▼
alphacp-installer (payload)            ← downloaded bundle, versioned + signed
   │
   ├─ Phase 0: Preflight
   │    OS/arch/ram/disk/ports/ipv4, existing panel?, root?
   ├─ Phase 1: Base packages
   │    apt/dnf: nginx apache2 php8.3-fpm mariadb exim4 dovecot bind9 pure-ftpd redis
   │    (multi-PHP via deb.sury.org / remi), unzip tar zstd jq curl
   ├─ Phase 2: Services configuration
   │    templates → /etc/... (vhost skeleton, exim, dovecot, bind, php pools)
   │    firewall (ufw/firewalld), fail2ban, quotas ON, cgroups v2
   ├─ Phase 3: Panel deploy
   │    /usr/local/alphacp/{releases,current,etc,var,logs}
   │    composer install --no-dev, .env generate, APP_KEY, DB create+migrate
   │    systemd units: alphacp-web, alphacp-worker, alphacp-scheduler, paneld
   ├─ Phase 4: Admin setup
   │    admin username/email/password (or --auto flags), 2FA prompt
   │    hostname check, nameserver prompt (ns1/ns2), license activation (key or trial)
   ├─ Phase 5: Verify + report
   │    self-test suite (services up, panel reachable, agent round-trip, mail loopback)
   │    prints: URLs, credentials location, next steps, log path
   └─ done: /var/log/alphacp-install.log, state file for resumable re-run
```

### Flags
| Flag | Purpose |
|---|---|
| `--auto` | Unattended (needs `--hostname --email --password` or env vars) |
| `--dry-run` | Print actions, change nothing |
| `--node --central-url --join-token` | Install as a **node** (multi-server) |
| `--no-mail` / `--no-dns` | Skip modules (still installable later) |
| `--channel=stable|beta` | Release channel |
| `--force` | Re-install over existing (dangerous, requires typed confirm) |

### Guarantees
1. **Idempotent & resumable:** re-running continues from last completed phase (state file
   `/usr/local/alphacp/var/install.state`).
2. **Logged:** every command logged (`/var/log/alphacp-install.log`).
3. **Non-destructive to existing sites:** if Apache/Nginx has vhosts, installer keeps them and
   asks before touching configs.
4. **Verify before success:** Phase 5 must pass or it reports exactly what failed.

## 3. Server Filesystem Layout (installed)

```
/usr/local/alphacp/
├── releases/               # 1.0.0/, 1.0.1/ … (immutable)
├── current -> releases/1.0.1     # atomic symlink (web + CLI use this)
├── etc/                    # panel.env, license.json, config overrides
├── var/                    # panel state: install.state, locks, temp
└── logs/                   # panel + agent logs
/home/<account>/            # customer home dirs (websites, mail, etc.)
/var/log/alphacp-*/         # service logs
```

## 4. Updater — User Experience

**Admin UI:** Dashboard → *"Update available: v1.0.2"* → **[Update now]** → live progress
(streams `task_logs`) → done message with changelog link. One click.

**CLI:**
```bash
alphacp update --check          # is there a new version?
alphacp update                  # update to latest stable
alphacp update --to=1.0.2 --channel=beta
alphacp rollback                # back to previous release
alphacp version
```

## 5. Updater Architecture (atomic + safe)

```
Release artifact (built & signed by vendor):
  releases.alphacp.com/stable/1.0.2/
    ├── alphacp-1.0.2.tar.gz      # full app (panel, agent, installer, CLI)
    ├── alphacp-1.0.2.tar.gz.sig  # Ed25519 detached signature
    └── manifest.json             # {files+sha256, migrations:[], min_version,
                                  #  php_req, migrations_rollback:[], released_at, notes}

Update flow:
  1. preflight        disk space, RAM, php version, current version compat (min_version)
  2. license check    update permission per license tier/channel (grace: warn only)
  3. download + verify  signature + sha256 of every file (manifest)
  4. snapshot         panel DB dump + copy of /usr/local/alphacp/etc → var/backups/update-<ts>/
  5. extract          → releases/1.0.2/
  6. maintenance      panel maintenance mode ON (customer services unaffected!)
  7. migrate          php artisan migrate --force (from inside new release)
  8. swap             symlink current → releases/1.0.2  (atomic)
  9. restart          alphacp-web worker scheduler paneld (+ reload services if templates changed)
 10. health check     script suite: HTTP 200, DB ok, agent round-trip, queue works, license ok
     ├─ PASS → success (record in updates_history), maintenance OFF
     └─ FAIL → auto-rollback: symlink back, migrate rollback (if safe), restart, maintenance OFF,
               alert admin with logs (status=rolled_back)
```

### Rollback rules
- Rollback allowed to previous release **and** its DB migration state (down migrations must exist;
  if a migration is marked `irreversible`, rollback stops and asks admin — never silent).
- `alphacp rollback --to=1.0.0` possible for last 3 releases kept on disk.

### Update channels
| Channel | Meaning |
|---|---|
| `stable` (default) | Tested releases |
| `beta` | Early access, opt-in in settings |
| `lts` | (future) security-only for conservative hosts |

### Admin UI extra screens (Step 15)
- Updates: history (`updates_history`), channel selector, auto-update toggle (off by default),
  "update all nodes" (multi-server, rolling with health gates).

## 6. `alphacp` CLI — Command Surface (v1)

| Command | Purpose |
|---|---|
| `alphacp install` | (delegates to installer) |
| `alphacp version` | Panel + component versions |
| `alphacp status` | Services + agent + license + queue health |
| `alphacp update` / `rollback` | Self-update (see §4) |
| `alphacp doctor` | Diagnose + attempt safe fixes (perms, services, queue stalls, disk) |
| `alphacp license activate <key>` / `license status` | License ops |
| `alphacp node join --central=URL --token=X` | Join node to central |
| `alphacp account list|suspend|unsuspend` | Emergency CLI ops (audited) |
| `alphacp backup now [--account=user]` | Trigger backup |
| `alphacp support-bundle` | Collect diagnostics (redacted logs) into a tarball for support |

## 7. Distribution & Signing

- Artifacts built by a CI pipeline (later), signed with Ed25519 **release key**
  (different from license key). Public key shipped in each release + bootstrap.
- `install.sh` bootstrap pinned to verify its payload signature before running.
- Optional mirror/CDN; checksums published.

## 8. Testing Plan (per release)

| Test | Method |
|---|---|
| Fresh install (Ubuntu 24.04 / 22.04 / Alma 9) | VM snapshots; expect < 60 min, green self-test |
| Node install + join | 2 VMs; central sees node; account create on node works |
| Update N→N+1 | With real accounts; verify zero customer-site downtime |
| Forced failure injection | Break migration / kill service mid-update → auto-rollback works |
| Interrupted install resume | Kill installer mid-phase 3 → re-run completes |
| Node update (rolling) | Update central, then nodes one by one with health gates |

## 9. Roadmap Mapping

| Step | Installer/Updater work |
|---|---|
| S1 | Installer skeleton + Phase 1-2 (stack) + `alphacp status/doctor` basics |
| S2 | Phase 3-4 (panel deploy, admin) + systemd units + agent install |
| S15 | Full updater (UI + CLI), rollback, node join, rolling updates, support-bundle |
| every release | artifacts + signatures + changelog plumbing |
