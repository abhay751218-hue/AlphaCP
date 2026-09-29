# 03 — Security Matrix (Roles, Permissions, Agent Task Safety)

> **Hindi summary:** Kaun kya kar sakta hai, aur kaunse kaam "khatarnak" hain (jinke liye
> extra confirmation + audit chahiye). Ye security ka rulebook hai.

**Status:** ✅ v1
**Related:** `01-architecture.md` §4, `02-database-schema.sql` (permissions/audit tables)

---

## 1. Roles

| Role slug | Name | Level | Scope |
|---|---|---|---|
| `superadmin` | Platform Owner | 10 | Everything incl. license, panel updates, multi-server |
| `admin` | Server Admin | 20 | Full server management except license/panel-core |
| `support` | Support Staff | 30 | Read + limited actions (no terminate, no root tasks) |
| `reseller` | Reseller | 40 | Own packages + own accounts + own branding |
| `user` | Hosting Customer | 90 | Own account only (client panel) |

Rules:
- Higher level number = fewer powers. A role can only manage roles with **higher** level numbers.
- `reseller` sees only rows where `accounts.reseller_id = own reseller id`.
- `user` sees only accounts linked via `account_users`.
- Superadmin-only actions: license activation/transfer, panel update/rollback, server add/remove,
  API token creation for other admins, audit log purge (which is never allowed — see §6).

## 2. Permission Naming & Registry

Format: `module.action` (e.g. `accounts.create`, `email.createMailbox`, `dns.editRecord`).
Registry lives in code: `panel/config/permissions.php` → synced to `permissions` table by a
command (`alphacp:sync-permissions`). Docs matrix below is the human-readable mirror.

## 3. Permission Matrix (v1)

Legend: ✅ full · 🟡 own scope only · ⛔ none

| Permission group | superadmin | admin | support | reseller | user |
|---|---|---|---|---|---|
| accounts.create | ✅ | ✅ | ⛔ | 🟡 | ⛔ |
| accounts.suspend / unsuspend | ✅ | ✅ | 🟡 | 🟡 | ⛔ |
| accounts.terminate | ✅ | ✅ | ⛔ | 🟡 | ⛔ |
| accounts.modify (limits/quota) | ✅ | ✅ | ⛔ | 🟡 | ⛔ |
| accounts.loginAs (support login) | ✅ | ✅ | 🟡 | 🟡 | ⛔ |
| packages.manage | ✅ | ✅ | ⛔ | 🟡 (own only) | ⛔ |
| domains.* (addon/sub/parked/redirect) | ✅ | ✅ | ⛔ | 🟡 | 🟡 (own acct) |
| dns.zones.* | ✅ | ✅ | 🟡 (read) | 🟡 own accts | 🟡 own accts |
| email.* (mailboxes/fwd/auto/filters) | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| email.globalFilters | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| databases.* | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| files.* (file manager ops) | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| ftp.* | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| ssh.keys.* | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| cron.* | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| ssl.issue / install / renew | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| backup.run / restore | ✅ | ✅ | ⛔ | 🟡 | 🟡 (own) |
| backup.serverWide | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| apps.install (one-click installer) | ✅ | ✅ | ⛔ | 🟡 | 🟡 |
| security.scan / quarantine | ✅ | ✅ | 🟡 (read) | 🟡 own accts | 🟡 own acct |
| security.waf.manage | ✅ | ✅ | ⛔ | ⛔ | 🟡 (own domain toggle) |
| security.ipBlocker | ✅ | ✅ | ⛔ | 🟡 own accts | 🟡 own acct |
| server.services.restart | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| server.metrics / health | ✅ | ✅ | ✅ | 🟡 own accts usage | 🟡 own usage |
| server.ipPool.manage | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| servers.add / remove (multi-server) | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| resellers.manage | ✅ | ✅ | ⛔ | 🟡 own subs | ⛔ |
| api.tokens.manage | ✅ | ✅ (own) | ⛔ | 🟡 (own) | 🟡 (own) |
| license.view | ✅ | ✅ (read) | ⛔ | ⛔ | ⛔ |
| license.manage (activate / transfer) | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| panel.update / rollback | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| audit.view | ✅ | ✅ | 🟡 limited | 🟡 own scope | ⛔ |
| users.manage (panel logins) | ✅ | ✅ | ⛔ | 🟡 own reseller users | 🟡 own profile |

**Feature lists** (`feature_lists`) can only *hide* UI tools; they never grant permissions.
Permission is always checked server-side (defense in depth).

## 4. Agent Task Safety Classes (CRITICAL)

Every agent task type is registered in `agent/config/tasks.php` with a class:

| Class | Meaning | Examples | Controls required |
|---|---|---|---|
| 🟢 `readonly` | No state change | `service.status`, `usage.sync`, `files.list` | permission check only |
| 🟡 `mutating` | Changes state, reversible | `account.suspend`, `mail.createMailbox`, `dns.addRecord` | permission + audit row |
| 🔴 `destructive` | Deletes/overwrites irreversibly | `account.terminate`, `files.delete`, `backup.restore`, `db.delete` | permission + **re-confirm (typed domain/username)** + audit + optional 2FA re-auth |

Additional hard rules:
1. Payload validated against JSON Schema **before** execution (agent side, not just panel).
2. All task handlers must be **idempotent** or declare `non_idempotent: true` with reason.
3. Timeout per task; timeout = failure, partial states must be rolled back by the handler.
4. Destructive tasks must log the **pre-state** (what will be deleted) into `task_logs`.
5. No task may touch paths outside allowlisted roots (`/home/<acct>`, `/etc/<service>.d/`,
   `/usr/local/alphacp/...`) — enforced by a shared path-validation helper.
6. Agent refuses tasks with unknown type, unknown safety class, or missing schema → logs
   `security.agent.rejected` audit event.

## 5. API Token Permission Mapping (cPanel-compatible names)

WHMCS/Blesta send cPanel's token permission names. We map them 1:1 to our permissions:

| cPanel token permission | Our permission(s) |
|---|---|
| `create-acct` | accounts.create |
| `suspend-acct` | accounts.suspend |
| `kill-acct` | accounts.terminate |
| `upgrade-account` | packages.manage + accounts.modify |
| `passwd` | accounts.modify |
| `list-accts` | accounts.list |
| `acct-summary` | accounts.list |
| `show-bandwidth` | server.metrics (account scope) |
| `list-pkgs` | packages.manage (read) |
| `cpanel-api` | uapi.* (delegates to account policy) |
| `create-user-session` | accounts.loginAs |
| `basic-whm-functions` | server.metrics + server.services.status |
| `basic-system-info` | server.metrics |

Unmapped tokens = denied (fail closed). Mapping file: `panel/config/whm_token_permissions.php`.

## 6. Audit Requirements

| Event happens | audit_logs row | Fields |
|---|---|---|
| Any state-changing panel action | ✅ | actor, action, target, ip, meta(with diff) |
| Any agent task execution | ✅ (also task_logs) | action=`agent.task.<type>` |
| Login success/failure | ✅ | action=`auth.login` severity by outcome |
| Permission denied (any) | ✅ | severity=warning |
| License state change | ✅ | action=`license.*` |
| Settings changed | ✅ | with before/after values |
| API request (billing) | `api_request_logs` + audit for mutations | function, token, ip, duration |

- `audit_logs` is **append-only**: no UPDATE/DELETE from application code (DB user has no
  DELETE grant on this table).
- Retention: ≥ 12 months, then archived (never silently dropped).
- Sensitive values (passwords, tokens, private keys) are **redacted** before logging:
  meta stores `"password":"***"`, never raw.

## 7. Secret Handling

| Secret | Storage |
|---|---|
| Panel user passwords | bcrypt/argon2id |
| Customer mailbox passwords | Dovecot-compatible hash (SSHA512) + encrypted copy for display-on-request only if enabled |
| MySQL user passwords | encrypted (AES-256-GCM, key in `.env` outside DB) |
| API tokens | sha256 hash only (raw shown once at creation) |
| License key | encrypted at rest + signed payload (offline verifiable) |
| Node agent tokens | hashed (sha256), per-node, revocable |
| Webhook secrets | encrypted, used as HMAC-SHA256 signing key |
| Panel `.env` | 0640, owned by `alphacp`, never in Git |

## 8. Security Invariants (testable — every one needs a test)

1. Client panel user can never access another account's files/DB/mail — even by ID tampering.
2. Reseller can never see/modify accounts outside own tree.
3. Feature-list changes never grant permissions.
4. Agent rejects any payload failing schema validation (test with malformed payloads).
5. Path traversal attempts (`../../etc/passwd`) fail at the shared path validator.
6. API token with `list-accts` cannot call `createacct` (per-function enforcement).
7. Suspended account: web shows suspension page; mail delivery paused; cron disabled;
   files unchanged.
8. Destructive task without `confirm:true` in payload is rejected and audited.
9. License failure never disables customer services (only panel degradation).
10. Rate limits: login (per IP+user), API (per token), mail (per account/hour).
