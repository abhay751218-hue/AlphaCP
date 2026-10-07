# `agent/` — paneld privileged task agent

**Runtime:** PHP 8.3+ CLI · **Runs as:** `root` under systemd · **Interface:** database task queue only
**Current source version:** `0.83.0` (`src/Bootstrap.php`), synchronized from the redacted deployed snapshot.
**Tests:** `php agent/tests/run-tests.php` — 212 no-database tests; latest php-wasm run: **212 passed, 0 failed**.

## Role in AlphaCP

`paneld` is the only component allowed to perform privileged operations such as provisioning Linux accounts, writing server configuration, manipulating hosting files, and reloading services. The web panel only enqueues allowlisted tasks; `paneld` validates and runs them, then records results in `tasks` and `task_logs`.

The active allowlist in `config/tasks.php` covers task families for diagnostics, accounts, websites/domains, files, PHP, mail, databases, DNS, backups/restores, security and server services. The exact task types, JSON schemas, safety classes, paths and timeouts in that file are the contract; do not infer permission from a task's presence in the registry.

## Security invariants

- Task type must appear in `config/tasks.php`; unknown types fail closed.
- Every payload is validated against its JSON schema before execution.
- Safety classes are `readonly`, `mutating` and `destructive`; destructive tasks require explicit confirmation as defined by the task schema.
- Use array-form process execution through the command allowlist; never interpolate untrusted input into a shell string.
- Paths are canonicalized and restricted to configured allowlisted roots by `PathGuard`.
- Handlers should be idempotent; multi-step changes must provide rollback/compensation or fail safely.
- The agent has no network listener or web UI.

## Layout

```
agent/
├── bin/paneld                  # daemon/once/status/selftest/run/tasks entry point
├── config/tasks.php            # task allowlist, schemas, safety classes, timeout and path policy
├── src/                        # bootstrap, DB, CLI, daemon, runner, validators and task handlers
├── tests/run-tests.php         # 212 no-DB security/handler tests
├── tests/FakeCommandExecutor.php
└── systemd/paneld.service
```

## Local development

```bash
php agent/bin/paneld --selftest
php agent/bin/paneld --status
php agent/bin/paneld --once
php agent/bin/paneld --run system.info
php agent/tests/run-tests.php
```

On a live server, use the installed `alphacp` CLI. Do not run privileged tasks directly from a web request.

## Snapshot alignment note

The agent source here was synchronized from the redacted deployed snapshot so local panel tests use the same task registry as the v0.75.0 panel. The v0.76.0 UI work did not change agent behavior. Full agent tests passed in the sandbox with PHP 8.5 php-wasm; run them under the supported server PHP before any production agent release.
