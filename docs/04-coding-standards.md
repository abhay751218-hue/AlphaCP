# 04 — Coding Standards (AI-Friendly Rules)

> **Hindi summary:** Code likhne ke rules — isse koi bhi developer ya AI kabhi bhi code padh ke
> samajh sakta hai aur safe change kar sakta hai. In rules ko todna allowed nahi hai.

**Status:** ✅ v1 — binding for all code in `panel/`, `agent/`, `installer/`, `license-server/`.

---

## 0. Top-5 principles (why this exists)

1. **Predictable structure** > clever code. Any AI/developer should find things without asking.
2. **Explicit types everywhere.** No guessing what a value is.
3. **Small, single-purpose functions** (target ≤ 40 lines; hard limit 80).
4. **Every privileged action is a named task** — never inline root logic in web code.
5. **Docs + tests travel with code** — code without them is incomplete.

---

## 1. PHP (panel + agent + license server)

- PHP 8.3+ · `declare(strict_types=1);` at top of every file.
- Follow Laravel 11 conventions; formatting enforced by **Laravel Pint** (`pint.json` at root).
- Static analysis: **PHPStan level 8** target (`phpstan.neon`), baseline only for legacy.
- Class/file naming: `PascalCase` classes, one class per file, file name = class name.
- Methods: `camelCase`. Variables: `camelCase`. Constants: `UPPER_SNAKE`.
- **Full type hints + return types on everything**, incl. `array<int, string>` style docblocks.
- Prefer **readonly DTOs** / value objects over arrays for payloads passed between layers
  (e.g. `CreateAccountData`). Arrays only for JSON columns and config.
- Exceptions: one exception class per error family, in `app/Exceptions/`, each carrying an
  **error code** from `app/Support/ErrorCodes.php` (format `ACP-<MODULE>-<NNN>`).
  Never throw bare `\Exception`. Never swallow exceptions silently (log or rethrow).
- Enums (backed) for every fixed set of values (statuses, types) — mirror DB enums.
- No `dd()`, `var_dump()`, `dump()` in committed code. Use structured logging.
- No business logic in controllers — controllers validate (FormRequest) → call Service → respond.
- Queries: Eloquent + scopes; raw SQL only for reports/analytics, always parameterized, and
  placed in `app/Modules/<Module>/Queries/`.
- Transactions: wrap multi-step writes in `DB::transaction()`. Agent tasks use explicit
  compensating actions instead (they span OS state).

### Banned patterns (hard fail in review)
```php
// ❌ never
shell_exec("userdel $user");
exec('rm -rf ' . $path);
// ✅ always (agent side)
Process::run(['/usr/sbin/userdel', '-r', $validatedUser]);
```

## 2. TypeScript / React (frontend)

- TypeScript **strict** mode. No `any` (use `unknown` + narrowing).
- Components: `PascalCase.tsx`; hooks `useThing.ts`; utils `camelCase.ts`.
- Folder shape: `resources/js/pages/<module>/<Page>.tsx` mirroring backend routes,
  `resources/js/components/<domain>/…`.
- **No business logic in components** — data comes from Inertia props; mutations go through
  typed helpers in `resources/js/lib/api.ts`.
- Every user-visible string goes through i18n: `t('accounts.create.title')`
  (files: `lang/en.json`, `lang/hi.json`). No hardcoded English in JSX.
- Forms: one schema (Zod) per form, shared error display component.
- Tailwind only; no inline styles; no new UI library without a decision-log entry.

## 3. Database

- **Migrations only** — never manual schema edits in production. Design reference:
  `docs/02-database-schema.sql` (keep in sync when schema changes).
- Naming: tables plural snake_case, columns snake_case, FKs `<table_singular>_id`,
  indexes `idx_<table>_<cols>`, unique `uq_<table>_<cols>`.
- Every table has `created_at`, `updated_at` (Laravel timestamps).
- Soft deletes (`deleted_at`) only on: `users`, `accounts`, `packages`, `domains`.
- Enum changes = new migration altering the enum (documented in module doc).
- Never destructive migration without a documented, tested down-path.

## 4. Module Blueprint (mandatory shape)

Full spec: `docs/08-module-blueprint.md`. Summary — each module folder contains:

```
panel/app/Modules/Accounts/
├── Actions/            # one class per use-case (CreateAccount, SuspendAccount…)
├── Data/               # DTOs (readonly classes)
├── Http/Controllers/   # Admin + Client controllers
├── Http/Requests/      # FormRequests (validation)
├── Models/             # Eloquent models
├── Policies/           # Authorization
├── Queries/            # complex read queries (optional)
├── Tasks/              # agent task payload builders (panel side)
└── module.php          # module manifest: permissions, routes, nav, feature flags
```

## 5. Naming Conventions (cross-layer consistency)

| Concept | Convention | Example |
|---|---|---|
| Agent task type | `module.action` lowercase camelAction | `account.create`, `mail.createMailbox` |
| Permission slug | `module.action` | `email.createMailbox` |
| Error code | `ACP-<MODULE>-<NNN>` | `ACP-ACCT-014` |
| Audit action | `module.action` | `account.suspend` |
| Webhook event | `module.action` past tense | `account.created` |
| Route name | `admin.<module>.<action>` / `client.<module>.<action>` | `admin.accounts.create` |
| Inertia page | `modules/<module>/<Page>` | `Modules/Accounts/Create` |
| Translation key | `<module>.<page>.<element>` | `accounts.create.submit` |
| DB table | plural snake_case | `mail_forwarders` |

## 6. Testing

- Framework: **Pest** (PHP) + **Vitest** (TS where non-trivial).
- Required per feature:
  - Feature test for each HTTP endpoint (happy path + permission-denied + validation fail).
  - **Agent handler test** for every task type: payload validation, path allowlist, command
    array shape (mock `Process`), rollback path for mutating tasks.
  - License-path tests: activation, heartbeat fail → grace, expiry → degradation.
- Naming: `it('rejects createacct without permission')` — behavior-focused sentences.
- Factory for every model. No test hits the real OS/network — use fakes/fixtures.
- CI gate (when Git hosting is set up): Pint + PHPStan + Pest must pass.

## 7. Git & Change Discipline

- Trunk-based: short-lived branches `feat/<module>-<slug>`, `fix/<slug>`.
- **Conventional commits**: `feat(accounts): add quota edit endpoint`
  (types: feat|fix|docs|refactor|perf|test|chore|security).
- One logical change per commit; migrations and their model changes in the same commit.
- Every user-visible change adds a `CHANGELOG.md` line under `Unreleased`.

## 8. Documentation Discipline (the AI-friendliness core)

| When you… | You must update |
|---|---|
| Add a module | `docs/modules/<module>.md` (from template) + `AI_CONTEXT.md` §6 map |
| Add permission | `config/permissions.php` + `docs/03-security-matrix.md` §3 |
| Add agent task | `agent/config/tasks.php` + module doc (task table) + safety class |
| Add DB table/column | `docs/02-database-schema.sql` + module doc |
| Add API endpoint | `docs/api/README.md` (+WHM compat map if cPanel-shaped) |
| Change behavior | `CHANGELOG.md` + affected docs |
| Make an architecture decision | new ADR in `docs/07-decision-log.md` |

## 9. AI Collaboration Rules (explicit)

1. **Always** start by reading `AI_CONTEXT.md`, this file, and the module doc you're touching.
2. **Never** invent a new pattern when the blueprint has one — copy the closest module.
3. Keep functions small, names explicit, zero "magic". If a reviewer must "figure out" code,
   rewrite it.
4. Mark future work as `// TODO(step-SX): …` — never silent gaps.
5. When generating a privileged path, include the test that proves it can't escape the
   allowlist.
6. In any PR/summary output: list assumptions, files changed, tests run, docs updated.
7. If requirements conflict with the golden rules (AI_CONTEXT §3) — **stop and ask**.

## 10. Logging & Error Surfaces

- Structured logs (JSON) via Laravel channels: `panel`, `agent`, `api`, `task`.
- Log levels: debug (dev), info (lifecycle), warning (recoverable), error (needs attention),
  critical (page owner).
- User-facing errors: friendly message + error code (e.g. *"ACP-ACCT-014: Domain already exists"*);
  technical detail goes to logs only.
- Never log secrets (see `03-security-matrix.md` §7 redaction rules).
