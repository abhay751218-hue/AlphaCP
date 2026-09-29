# AI_CONTEXT.md — Read This First

> 👉 **Naya AI/developer? Sabse pehle [`START-HERE.md`](START-HERE.md) padho** — server ki live state `server-snapshot/STATE.md` me hai.

> **Purpose:** This file gives ANY AI assistant (or new developer) complete context to work on
> this project safely. Keep it updated whenever architecture, conventions, or status change.
> **Last updated:** 2026-09-28 (Step 0 complete)

---

## 1. What This Project Is

**AlphaCP** — a commercial hosting control panel (cPanel + WHM parity target) built from
scratch. It manages Linux hosting servers: user accounts, websites, email, databases, DNS,
FTP, SSL, backups, and resource limits. It also ships a **license system** so the panel itself
can be sold (like cPanel licenses) and a **WHM API 1 compatible API layer** so any billing
software (WHMCS, Blesta, Clientexec, or custom) can provision accounts) without code changes.

**Owner:** Abhay Kumar (solo founder + AI-assisted development).
**Business goal:** Start an Indian hosting company, then sell the panel commercially (licenses,
reseller/OEM deals).

---

## 2. Current Status (keep this section current!)

| Item | State |
|---|---|
| Phase | **Step 5 — Error Pages** 🟡 (panel 0.11.0 / agent 0.8.0; INI deployed 0.10.0) |
| Next task | Deploy 0.11.0 to dev-srv1; then Indexes/MIME/handlers or S6 |
| Dev server | AWS Lightsail `dev-srv1` · Ubuntu 24.04 · 4 GB/2 vCPU/80 GB · Mumbai · IP `13.207.123.177` |
| Code written so far | paneld agent + Laravel 13 panel bundle + Step 2B installer/doctor + license client/trial |
| Blocking issues | PHP/Laravel test execution still needs a PHP 8.3+ build environment; server acceptance of new S2C bundle pending |

Progress tracker: `project-status.md` · Roadmap: `ROADMAP.md`

---

## 3. Golden Rules (NEVER break these)

**0. Parity contract:** `docs/09-cpanel-parity-checklist.md` — cPanel/WHM ki 208 tools ki checklist.
   Ye batati hai project "kaam kab khatam" maana jayega. Kaam ke baad apni row ka status update karo
   (✅ karna allowed, hatana nahi). Naya feature? Pehle us file me row add karo.

1. **The web panel NEVER runs as root.** All privileged operations go through the `paneld`
   task agent (`agent/`), which executes only allowlisted task types with array-form exec
   (no shell string concatenation). See `docs/01-architecture.md` §4.
2. **Never disable customer services because of a license issue.** License failure degrades the
   *panel* (no new accounts, admin warnings), never customer websites/email.
3. **All user input is hostile.** Validate on input, escape on output, never interpolate into
   shell, SQL, file paths, or config templates.
4. **Every privileged action is audited** (`audit_logs` table) and every agent task is
   traceable (`tasks` + `task_logs`).
5. **WHM API 1 response format is a contract.** Don't "improve" it — match cPanel exactly
   (metadata/data wrappers). Extensions go in separate native API endpoints (`/api/v1/...`).
6. **One module = one folder, same shape everywhere.** Follow `docs/08-module-blueprint.md`.
   No snowflake modules.
7. **Every behavior change updates docs + CHANGELOG + tests.** AI-generated code without a
   test for a privileged task is rejected.

---

## 4. Stack & Versions (locked — see `docs/07-decision-log.md`)

| Layer | Choice |
|---|---|
| Language (panel) | PHP 8.3, `declare(strict_types=1)` everywhere |
| Framework | Laravel 13.33.0 (active `refs/panel-2b-bundle`; old `panel/` Laravel 11 tree is obsolete) |
| Frontend | Blade/Tailwind panel shell in the active bundle; React/Inertia remains a future UI direction |
| Panel DB | MariaDB 10.11+ (InnoDB, utf8mb4) |
| Queue/Cache | Redis |
| Privileged agent | PHP CLI daemon (`paneld`) run by systemd as root, polls `tasks` table, allowlisted handlers |
| Panel init | systemd services: `alphacp-web`, `alphacp-worker`, `alphacp-scheduler`, `paneld` |
| Web services managed by panel | Nginx (front) + Apache (or Nginx-FPM only mode), PHP-FPM multi-version |
| Mail | Exim (or Postfix) + Dovecot + SpamAssassin + ClamAV + OpenDKIM |
| DNS | BIND9 |
| FTP | Pure-FTPd + Jailkit |
| SSL | ACME (Let's Encrypt) via a cert client |
| Installer/Updater | Bash bootstrap + signed tarball releases + atomic symlink swap |
| License | Ed25519-signed license payloads + heartbeat + grace period |

---

## 5. Repository Layout

```
/                          ← workspace root
├── README.md              ← project overview
├── AI_CONTEXT.md          ← THIS FILE (context for AI/devs)
├── AGENTS.md              ← rules for AI assistants
├── ROADMAP.md             ← 16-step plan + status
├── CHANGELOG.md
├── project-status.md
├── docs/                  ← all design docs (numbered)
│   ├── 00-requirements-freeze.md
│   ├── 01-architecture.md
│   ├── 02-database-schema.sql
│   ├── 03-security-matrix.md
│   ├── 04-coding-standards.md
│   ├── 05-license-system.md
│   ├── 06-installer-updater.md
│   ├── 07-decision-log.md
│   ├── 08-module-blueprint.md
│   └── modules/           ← per-module docs (grows with code)
├── panel/                 ← Laravel + React application (Step 2+)
├── agent/                 ← paneld root task worker (Step 2+)
├── installer/             ← one-click install/update system (Step 1+)
└── license-server/        ← central license server app (separate deploy)
```

---

## 6. Where Things Live (once code exists)

| Concern | Location (planned) |
|---|---|
| HTTP routes (admin panel) | `panel/routes/admin.php` |
| HTTP routes (client panel) | `panel/routes/client.php` |
| WHM API 1 endpoints | `panel/routes/api/whm.php` |
| Native REST API | `panel/routes/api/v1.php` |
| Modules (business logic) | `panel/app/Modules/<Module>/` |
| Agent task handlers | `agent/src/Tasks/<TaskName>Handler.php` |
| Permissions registry | `panel/config/permissions.php` |
| Agent task allowlist | `agent/config/tasks.php` |
| Error codes | `docs/error-codes.md` + `panel/app/Support/ErrorCodes.php` |
| Translations | `panel/lang/{en,hi}/...` |
| Tests | `panel/tests/Feature/<Module>/`, `agent/tests/` |

---

## 7. Module Pattern (summary — full spec in `docs/08-module-blueprint.md`)

Every feature module (accounts, domains, email, databases, dns, ssl, backup, ...) has the
same 12 parts: migrations, models, service class, controller (admin+client), policies,
permissions, agent task handlers, routes, Inertia pages, tests (feature + agent), module docs.
**When adding a module, copy the blueprint — do not invent new structure.**

---

## 8. How To Extend (checklists)

**Add a new panel feature:**
1. Read `docs/08-module-blueprint.md` → create module folder
2. Add migration(s) → update `docs/02-database-schema.sql` (design doc) + module doc
3. Add permissions to registry, update `docs/03-security-matrix.md`
4. Implement service + controller + policy (+ agent task if privileged)
5. Add Inertia page(s) + translation keys (en + hi)
6. Add tests (feature + agent handler test)
7. Update `CHANGELOG.md`, `ROADMAP.md` if needed, and `AI_CONTEXT.md` status section

**Add a WHM API 1 function:** implement in `panel/app/Modules/Api/Whm/Functions/`, add to
compatibility map (`docs/api/whm-compat.md`), test exact JSON shape, never alter existing keys.

**Add an agent task:** create handler in `agent/src/Tasks/`, register in allowlist with a
**safety class** (read-only / mutating / destructive — see `docs/03-security-matrix.md` §4),
write test with a fake root environment.

---

## 9. Don't-Do List

- ❌ No `shell_exec`/`exec` with string interpolation anywhere. Array-form only, after validation.
- ❌ No direct writes to service configs from the web layer — only via agent task handlers using templates.
- ❌ No root credentials stored in panel DB or `.env` beyond the agent's own bootstrap.
- ❌ No breaking changes to WHM API 1 responses.
- ❌ No new frontend dependency without noting it in `docs/07-decision-log.md`.
- ❌ No feature without: test, permission entry, audit event, docs update, CHANGELOG line.
- ❌ Never remove/rename DB columns in existing migrations — add new migrations instead.

---

## 10. Glossary

| Term | Meaning |
|---|---|
| **Account** | A hosting account (Linux user + home + website + mail + DBs). cPanel account equivalent. |
| **Package** | A plan with limits (disk, bandwidth, mailboxes, DBs...). cPanel package equivalent. |
| **Agent / paneld** | Root-privileged task worker that performs OS-level operations. |
| **Task** | A queued privileged operation (`tasks` table) executed by the agent. |
| **Node** | A server managed by the central panel (multi-server mode). |
| **Central panel** | The main panel server that also hosts the admin UI + license. |
| **Feature list** | Which UI tools are visible for a package (cPanel feature list equivalent). |
| **License server** | Separate central app that issues/validates licenses (commercial). |
| **Fingerprint** | Hashed hardware/host identity used to bind a license to a server. |
| **WHM API 1** | cPanel's admin API (port 2087). We implement a compatible layer. |
| **UAPI** | cPanel's user API (port 2083). We implement a compatible subset. |
| **Grace period** | Days a panel keeps working offline after a license check fails. |

---

## 11. Document Index

| File | One-liner |
|---|---|
| `docs/00-requirements-freeze.md` | v1 scope, personas, interfaces, compat promises |
| `docs/01-architecture.md` | diagrams, process model, agent design, data flows |
| `docs/02-database-schema.sql` | full table design (phase-tagged) |
| `docs/03-security-matrix.md` | RBAC matrix, task safety classes, audit rules |
| `docs/04-coding-standards.md` | code conventions + AI rules |
| `docs/05-license-system.md` | tiers, keys, activation, grace, license server |
| `docs/06-installer-updater.md` | install/update/rollback/join flows |
| `docs/07-decision-log.md` | ADRs (why we chose what we chose) |
| `docs/08-module-blueprint.md` | module skeleton + extension guide |
| `docs/modules/*.md` | per-module documentation (grows with code) |

---

## 12. Session Handoff Template (for AI sessions)

When starting a new AI session, paste:

> "Read AI_CONTEXT.md, docs/04-coding-standards.md and docs/08-module-blueprint.md first.
> Current step: <STEP>. Task: <WHAT YOU WANT>. Constraints: follow golden rules in AI_CONTEXT §3."

This keeps any assistant (any model) productive within minutes. Keep `AI_CONTEXT.md` §2
(status) updated after every work session.
