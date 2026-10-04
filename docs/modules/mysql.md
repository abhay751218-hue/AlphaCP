# Module: MySQL Databases + Users (S8 real provisioning, panel 0.70.0)

- **Step:** S8   **Status:** built + tested (panel 0.70.0 / agent 0.62.0) — deploy COMMANDS.md se

## Purpose
Turn the "MySQL Databases / Database Wizard / MySQL Users" pages from JSON stubs
into real MariaDB objects: `CREATE DATABASE`, `CREATE USER`, `GRANT ALL
PRIVILEGES`, `DROP DATABASE`, `DROP USER`.

Everything privileged happens in the root agent. The panel books the customer's
intent in its own tables and queues one task per action.

```
panel (cPanel pages)                    paneld (root)
────────────────────                    ────────────────────────────────────────
mysql_databases row                     PathGuard + Linux-user check
mysql_users row (+ grants)              mysql client: --protocol=socket, batch
queue db.create / db.user.*   ────────▶ SQL script on STDIN (never argv)
  destructive drops need _confirm       identifiers validated + backtick-quoted
password: Str::random(20), shown once   literals escaped for MySQL string rules
(new password / new user)                ALTER USER for a reset (password via stdin)
                                        result JSON (created/grants/state)
```

## Why the client binary + stdin
- argv only ever carries fixed flags — no database name, no user name, no
  password can leak into the process table (`ps`, audit logs, core dumps).
- `--protocol=socket` means root authenticates over the unix socket; the agent
  stores **no** MariaDB password at all.
- A MariaDB error can echo the SQL fragment it disliked; those fragments are
  scrubbed out of the message before it reaches the task log.

## Tasks
| type | payload | notes |
|---|---|---|
| `db.create` | username, name | `CREATE DATABASE <user>_<name> CHARACTER SET utf8mb4`; idempotent |
| `db.user.create` | username, user, password, host?, databases[] | creates the user + `GRANT ALL PRIVILEGES` on each listed database; existing user is reported, password untouched |
| `db.user.grant` | username, user, database, host? | "Add User To Database"; duplicate grant is a no-op |
| `db.user.password` | username, user, password, host? | Change Password: `ALTER USER … IDENTIFIED BY …`; the user must exist |
| `db.list` | username | readonly: databases + users + each user's grants (verification) |
| `db.drop` | username, name, `_confirm` | destructive: revokes every account user's privileges, then drops |
| `db.user.drop` | username, user, host?, `_confirm` | destructive: drops every host row of that account user |
| `db.set` | username, databases[] | **deprecated** JSON writer kept for compatibility; the panel no longer uses it |

## Safety rules
1. Only an existing AlphaCP Linux account (`<user>` with the `AlphaCP:` gecos)
   may touch MariaDB — a task for an unknown user is refused.
2. Names are `<account>_<suffix>`; the suffix is `[a-z][a-z0-9_]{0,15}` and the
   whole identifier is capped at 32 characters (cPanel-compatible).
3. Identifiers are backtick-quoted and literals are MySQL-escaped
   (`\` doubled first, then `'` doubled).
4. Passwords: 10–64 printable ASCII characters, no quote, no backslash, no
   control characters (the panel only generates `Str::random(20)`).
5. Grants only work on databases that belong to the same account.
5b. A password travels **inside the task payload** (the only channel the agent has)
   and is never written to `task_logs`; once the task succeeds the agent rewrites
   the stored payload with `***`, so a later DB dump does not expose a live
   MariaDB password. Failed tasks keep it so a retry still works.
6. Drops need `_confirm` (engine-level) and revoke privileges first.
7. Every action writes an audit row and, where relevant, an account event.

## Tests
- `agent/tests/run-tests.php` — six new handler tests (create/drop database,
  user + grants, grant, drop user, list, hostile input, failing client).
- `tools/sim/mysql-sim.sh` — runs the **real** handlers under php-wasm and lints
  every statement they generate (verbs, balanced quoting, backticked prefixes,
  no identifiers/passwords in argv, hostile input produces no SQL).
- `refs/panel-2b-bundle/tests/Feature/MysqlUsersTest.php` + updated
  `MysqlDatabasesTest` / `MysqlWizardTest` — panel wiring, password shown once,
  cross-account 403/404, `_confirm` in the payload.

## Remaining S8 gaps
- Remote MySQL (`db.remote`) still records hosts as JSON — creating `user@host`
  copies for those hosts is the next slice.
- phpMyAdmin auto-login (SSO) and per-database size limits are not implemented.
- `db.set` (JSON) remains registered for backwards compatibility.
