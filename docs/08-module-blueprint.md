# 08 — Module Blueprint (How Every Module Is Built)

> **Hindi summary:** Har feature (accounts, email, dns...) ek hi shape me banta hai. Isse koi
> bhi AI/dev naya module add kar sakta hai ya purana fix kar sakta hai — bina poora project
> samjhe. Ye "recipe" hai.

**Status:** ✅ v1 — mandatory for all modules.

---

## 1. The 12 Parts of a Module

Every module (e.g. `Accounts`, `Email`, `Dns`) consists of exactly these parts:

| # | Part | Location | Purpose |
|---|---|---|---|
| 1 | **Manifest** | `panel/app/Modules/<M>/module.php` | name, permissions, routes, nav, feature flag, tasks |
| 2 | **Migrations** | `panel/database/migrations/` (timestamped, prefixed by module in comment) | schema |
| 3 | **Models** | `panel/app/Modules/<M>/Models/` | Eloquent models + relations + casts |
| 4 | **DTOs** | `panel/app/Modules/<M>/Data/` | readonly payload objects between layers |
| 5 | **Actions** | `panel/app/Modules/<M>/Actions/` | one class per use-case (`CreateAccount`) |
| 6 | **Requests** | `panel/app/Modules/<M>/Http/Requests/` | validation rules |
| 7 | **Controllers** | `panel/app/Modules/<M>/Http/Controllers/` | thin: request → action → response |
| 8 | **Policies** | `panel/app/Modules/<M>/Policies/` | authorization per model |
| 9 | **Agent Task Builders** | `panel/app/Modules/<M>/Tasks/` | build validated task payloads |
| 10 | **Routes** | `panel/routes/modules/<m>.php` (admin + client sections) | URL surface |
| 11 | **Tests** | `panel/tests/Feature/<M>/`, `agent/tests/Tasks/` | endpoint + handler tests |
| 12 | **Docs** | `docs/modules/<m>.md` | module doc (see template §4) |

**Rule:** If a module needs something outside this list, add it to this doc first (and to the
decision log if it's architectural). No snowflake modules.

---

## 2. Canonical Flow (thru the layers)

```
HTTP Request
   → routes/modules/<m>.php
   → Controller (thin)
        → FormRequest (validate)
        → Policy (authorize)
        → Action (business logic, DB transaction)
             → [if privileged] Task Builder → tasks table → paneld executes
             → Model(s) persist
             → Events dispatched (audit, webhook, notification)
   → Response (Inertia page | JSON | redirect)
```

### Rules
- Controllers never contain business logic. Actions never touch HTTP.
- One Action per use-case; class name = use-case (`SuspendAccount`, `CreateMailbox`).
- Every Action that changes state → writes audit event (`AuditLogger::log(...)`).
- Every privileged step → a named agent task type; never inline shell/root logic.
- Long operations (create, backup, scan) → queue job + live progress via `task_logs`.

---

## 3. Module Manifest (`module.php`) — the source of truth

```php
<?php declare(strict_types=1);

return [
    'name'        => 'accounts',
    'feature'     => 'core',                    // license feature flag gate
    'permissions' => [
        'accounts.create'   => ['desc' => 'Create hosting accounts', 'privileged' => true],
        'accounts.suspend'  => ['desc' => 'Suspend accounts',        'privileged' => true],
        'accounts.terminate'=> ['desc' => 'Terminate accounts',      'privileged' => true, 'destructive' => true],
        'accounts.list'     => ['desc' => 'List/view accounts'],
    ],
    'routes'      => [
        'admin'  => __DIR__.'/routes/admin.php',
        'client' => __DIR__.'/routes/client.php',
    ],
    'nav'         => [
        'admin'  => ['group' => 'Account Functions', 'icon' => 'users', 'order' => 10],
        'client' => null,   // accounts module is admin-side; client has its own pages
    ],
    'tasks'       => [                          // agent task types owned by this module
        'account.create'    => 'mutating',
        'account.suspend'   => 'mutating',
        'account.unsuspend' => 'mutating',
        'account.terminate' => 'destructive',
        'account.setQuota'  => 'mutating',
    ],
];
```

A command (`alphacp:sync-modules`) loads all manifests → syncs permissions to DB + verifies
agent allowlist coverage. **CI fails if a task type in a manifest is missing from
`agent/config/tasks.php`.**

---

## 4. Module Doc Template (`docs/modules/<module>.md`)

```md
# Module: Accounts
- **Step:** S3   **Feature flag:** core   **Owner:** (name)
- **Status:** planned | in-progress | done

## Purpose
(2-3 lines: what problem it solves)

## Tables
| Table | Notes |
|---|---|

## Permissions
| slug | description | privileged | destructive |

## Agent Tasks
| type | safety | payload schema (fields) | rollback behavior |

## Routes / Pages
| method+path | page | permission |

## API (if exposed)
| WHM function | native endpoint | notes |

## Error Codes
ACP-ACCT-001 … (table)

## Test Checklist
- [ ] feature tests …
- [ ] agent handler tests …

## Known Limits / TODO(step-SX)
```

---

## 5. Example: adding ONE use-case end-to-end (checklist)

Example: *"Suspend outgoing email for a mailbox."*

1. **Permission**: add `email.suspendOutgoingMail` to manifest + security matrix doc.
2. **Route**: `POST admin/email/mailboxes/{id}/suspend-outgoing` (name `admin.email.mailbox.suspendOutgoing`).
3. **FormRequest**: `SuspendOutgoingMailRequest` (boolean `suspend`, optional `reason`).
4. **Policy**: `MailboxPolicy::suspendOutgoing` (account scope + permission).
5. **Action**: `SuspendOutgoingMail` → updates `mailboxes.suspended_outgoing` + audit +
   enqueues agent task `mail.suspendOutgoing`.
6. **Agent task**: handler in `agent/src/Tasks/MailSuspendOutgoingHandler.php`; registered in
   `agent/config/tasks.php` with class `mutating`; payload schema
   `{mailbox_email: string}`; effect = Exim routing flag + `exim -qff` reload.
7. **Panel-side builder**: `Tasks/SuspendOutgoingMailTask::build($mailbox)`.
8. **Tests**: feature test (permission, validation, happy path), handler test (array-exec shape,
   path validation, idempotency).
9. **Docs**: module doc row added; CHANGELOG `feat(email): suspend outgoing mail per mailbox`.
10. **Optional**: WHM API equivalent if cPanel has one (`suspend_outgoing_email`) — add to compat map.

That's the loop every change follows. If a request can't fit this loop, **stop and raise an ADR.**

---

## 6. Naming Quick Reference

| Thing | Pattern | Example |
|---|---|---|
| Module dir | `PascalCase` | `app/Modules/FileManager` |
| Action | `VerbNoun` | `CreateAccount`, `DeleteMailbox` |
| Task type | `module.camelAction` | `mail.createMailbox` |
| Permission | `module.action` | `files.delete` |
| Route name | `<area>.<module>.<action>` | `client.files.delete` |
| Test | behavior sentence | `it('blocks path traversal in delete')` |
| Error code | `ACP-<MOD>-<NNN>` | `ACP-FILES-021` |

---

## 7. Definition of Done (per module, before moving on)

- [ ] All 12 parts exist; manifest synced; CI green
- [ ] Feature tests (happy/denied/validation) + agent handler tests pass
- [ ] Audit events emitted; task safety classes correct (destructive → confirm flow)
- [ ] i18n keys added (en + hi); no hardcoded strings
- [ ] Module doc complete; `AI_CONTEXT.md` map updated if new area
- [ ] CHANGELOG entry; roadmap status updated
- [ ] Manual test checklist executed on dev server (documented results)
