# 07 — Decision Log (Architecture Decision Records)

> **Hindi summary:** Koi bhi bada technical decision — "ye kyun aise kiya" — yahan likha jayega.
> Isse aap (ya koi bhi AI) baad me confuse nahi hoga ki "ye kyun aise hai".

**Format:** `ADR-NNNN | Title | Status | Date | Context | Decision | Consequences`

---

## ADR-0001 — Panel stack: Laravel 11 + React/Inertia (PHP + TS)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Solo founder + AI-assisted development; billing ecosystem (WHMCS/Blesta) is PHP;
need fast iteration with strong security defaults and huge hiring pool in India.

**Decision:** Backend PHP 8.3 + Laravel 11; frontend React 18 + TypeScript + Inertia.js (single
repo, server-driven routing, no separate API glue); Tailwind styling.

**Consequences:** Fast development; large talent pool; matches billing ecosystem. Inertia limits
some SPA-only patterns (acceptable). Alternative (Node/Go backend) rejected: slower for this
team profile and weaker shared-hosting ecosystem fit.

---

## ADR-0002 — Privileged operations via root agent + task queue (never root web app)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Panel must create users, write configs, restart services (root). Running web code as
root is an industry-known catastrophe vector (see every panel CVE ever).

**Decision:** Minimal root daemon (`paneld`) polling a `tasks` table; allowlisted task types;
array-form exec; JSON-schema validated payloads; per-task logs; safety classes with confirm for
destructive ops.

**Consequences:** +1 component to build and monitor; strong blast-radius control; auditability;
easy AI-review ("is this a new task type? is it allowlisted?").

---

## ADR-0003 — MariaDB (InnoDB) for panel DB
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Need relational integrity for billing-grade data + JSON columns for flexible
payloads; customers also use MySQL/MariaDB.

**Decision:** MariaDB 10.11+ for the panel DB, utf8mb4, InnoDB everywhere. JSON columns for
flexible payloads (task payloads, limits extras, license payloads).

**Consequences:** Familiar tooling (phpMyAdmin), easy backups, mature. Postgres-only features
(partial indexes, etc.) avoided by design.

---

## ADR-0004 — OS targets: Ubuntu 24.04 primary; AlmaLinux 9 secondary; x86_64 production
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Dev server is Ubuntu 24.04 (AWS Lightsail). cPanel itself now supports Ubuntu 24.04.
Some hosting tools are x86_64-only.

**Decision:** Primary support Ubuntu 22.04/24.04 and AlmaLinux 9; ARM64 supported best-effort
(dev OK), production recommended x86_64.

**Consequences:** One apt/dnf abstraction layer needed in installer (§Phase 1). No reliance on
commercially-restricted tools (LiteSpeed/CloudLinux) for core features.

---

## ADR-0005 — License model: Ed25519 signed payloads + heartbeat + grace; never hurt customers
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Panel is sold commercially (cPanel-style). Aggressive license checks destroy vendor
reputation; offline servers must keep working.

**Decision:** Offline-verifiable signed license payload; daily heartbeat; configurable grace;
failure degrades the **panel only** (no new accounts/read-only), never customer websites/email.

**Consequences:** Slight piracy exposure (acceptable trade-off), huge trust win; license server
must be built as its own app (`license-server/`), with transfer/revocation flows.

---

## ADR-0006 — Atomic releases + signed updates (one-click upgrade with rollback)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** "One-click upgrade like cPanel" is a product requirement; hosting servers cannot
afford broken updates.

**Decision:** Versioned release dirs + `current` symlink swap; signed tarballs + manifest with
sha256; DB snapshot pre-update; health check post-update; automatic rollback on failure;
`alphacp update/rollback` CLI + admin UI page.

**Consequences:** Build pipeline must produce manifests/signatures (later automation);
update history table; keeps last 3 releases on disk (disk cost ~negligible).

---

## ADR-0007 — WHM API 1 compatibility is a first-class contract
**Status:** Accepted · **Date:** 2026-09-28

**Context:** The owner's custom billing (and later WHMCS/Blesta) must provision accounts with
zero code changes. Portability is a selling point.

**Decision:** Implement WHM API 1 core functions with **exact** cPanel JSON shape, ports
2086/2087, token auth with per-function permissions; extensions live in a separate native
REST API (`/api/v1/*`) — never bolted onto WHM responses.

**Consequences:** Response-shape tests become contract tests; compatibility doc must stay
updated; UAPI subset implemented client-side later (S12/S13).

---

## ADR-0008 — Module blueprint pattern (mandatory)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Owner requirement: *any AI/developer can pick up upgrades/fixes easily at any time.*

**Decision:** Every feature = one module folder with identical 12-part shape (Actions, Data,
Controllers, Requests, Models, Policies, Tasks, routes, pages, tests, docs, manifest).
Enforced in review; documented in `docs/08-module-blueprint.md`.

**Consequences:** Slight boilerplate per module; massive maintainability/AI-friendliness win;
new contributors are productive in hours.

---

## ADR-0009 — Documentation-as-code (AI_CONTEXT.md + module docs + CHANGELOG discipline)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** AI sessions have no memory between chats. Owner wants "kisi bhi AI se" work karwana.

**Decision:** `AI_CONTEXT.md` is the canonical session-entry doc (status + rules + map);
per-module docs required; every behavior change updates docs + CHANGELOG; decision log for
architecture changes.

**Consequences:** Discipline overhead accepted; any AI session becomes productive in minutes
using the handoff template (AI_CONTEXT §12).

---

## ADR-0010 — Update/upgrade & license UX must be end-user simple (one click)
**Status:** Accepted · **Date:** 2026-09-28

**Context:** Sold to other hosting companies — they expect cPanel-grade operational simplicity.

**Decision:** Install = one bash command; Update = one UI click or one CLI command; status and
doctor commands for self-diagnosis; support-bundle command for escalations.

**Consequences:** Installer/updater is a first-class subsystem from Step 1, not an afterthought.
