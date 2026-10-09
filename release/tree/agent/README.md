# agent/ — paneld (Privileged Task Agent)

**Language:** PHP 8.3 CLI (long-running via systemd) · **Runs as:** `root` · **Interface:** none (DB queue only)
**Status:** ✅ **v0.2.0 (Step 3)** — readonly health tasks plus account create/suspend/unsuspend/terminate/setQuota
with PathGuard + compensating rollback. Install/update via `panel-update.sh` (agent tarball) on an existing server.

## What it is
The ONLY component allowed to perform privileged operations (useradd, config writes, service
reloads, quota, file ops). It polls the `tasks` table, validates payloads, executes allowlisted
handlers, and writes `task_logs`.

## Hard rules (see ../docs/03-security-matrix.md §4)
- Allowlist in `config/tasks.php` with safety class per task
  (`readonly` | `mutating` | `destructive`)
- **Array-form exec only** — never shell strings, never interpolation
- Every path validated by shared `PathGuard` (canonical + prefix allowlist)
- JSON-schema validation of every payload BEFORE execution
- Idempotent handlers; compensating rollback for multi-step operations
- No network listeners; no web interface

## Actual layout (as built)
```
agent/
├── bin/paneld                  # entry point (--daemon/--once/--status/--selftest/--run/--tasks)
├── config/tasks.php            # ALLOWLIST: handler + safety + schema + timeout + services
├── src/
│   ├── Bootstrap.php  Db.php  Cli.php
│   ├── Daemon.php              # poll loop (FOR UPDATE SKIP LOCKED) + heartbeat + stale recovery
│   ├── TaskRunner.php          # validate → execute → success/retry/fail + audit
│   ├── JsonSchema.php          # hand-rolled validator (fails closed), no deps
│   ├── PathGuard.php           # canonicalised roots, escapes blocked
│   ├── CommandRunner.php       # array-exec + binary allowlist + SIGTERM→SIGKILL timeouts
│   ├── TaskLogger.php  TaskRejectedException.php
│   └── Tasks/                  # TaskInterface, TaskContext, AgentPing, SystemInfo, ServiceStatus
├── tests/run-tests.php         # 16 unit tests, no DB needed: php agent/tests/run-tests.php
└── systemd/paneld.service
```

## Run (dev)
```bash
php agent/bin/paneld --selftest        # env/db/registry/guards — no queue writes
php agent/bin/paneld --status          # JSON snapshot (queue counts, task list)
php agent/bin/paneld --once            # process one task and exit
php agent/bin/paneld --run system.info # run a task immediately, verbose
php agent/bin/paneld --daemon          # daemon mode (systemd runs this)
```
On a live server use the CLI instead: `alphacp agent selftest` · `alphacp task run system.info`.
