# AlphaCP — Custom Hosting Control Panel

> 👉 **Naya AI/developer? Sabse pehle [`START-HERE.md`](START-HERE.md) padho** — server ki live state `server-snapshot/STATE.md` me hai.

> **Working title:** "AlphaCP" (final brand name aap decide karoge)
> **Status:** 🟢 Step 0 (Blueprint) — design phase
> **Type:** Commercial hosting control panel (cPanel/WHM parity target)

A from-scratch, cPanel-compatible hosting control panel written for real hosting businesses.
Includes: WHM-style admin, cPanel-style client panel, reseller panel, webmail, billing API
(WHM API 1 compatible), built-in **license system** for commercial resale, and one-click
install/upgrade tooling.

---

## 📌 Quick Facts

| Item | Value |
|---|---|
| Backend | PHP 8.3 + Laravel 11 (strict types) |
| Frontend | React 18 + TypeScript + Inertia.js + Tailwind |
| Panel DB | MariaDB (separate from customer DBs) |
| Privileged layer | `paneld` — root task worker (allowlist-based, never a root web app) |
| Target OS | Ubuntu 24.04 LTS (primary), AlmaLinux 9 (secondary) |
| Target arch | x86_64 (ARM64 dev supported) |
| Compatibility | WHM API 1 (2086/2087), cPanel UAPI subset (2082/2083), Webmail (2095/2096) |
| Business model | Panel sold via license keys (tiers: Trial/Starter/Business/Unlimited/OEM) + Multi-server packs |
| Dev server | AWS Lightsail, Mumbai — `dev-srv1` (4 GB / 2 vCPU / 80 GB) |
| Repos | `panel/`, `agent/`, `installer/`, `license-server/` (monorepo dirs for now) |

---

## 🗺️ Document Map (START HERE)

| Doc | What it covers |
|---|---|
| **`AI_CONTEXT.md`** | ⭐ **Read this first** — full context for any developer or AI assistant |
| `AGENTS.md` | Rules for AI assistants working on this repo |
| `docs/00-requirements-freeze.md` | What v1 includes/excludes (scope lock) |
| `docs/01-architecture.md` | System architecture, process model, data flows |
| `docs/02-database-schema.sql` | Complete database design (all tables) |
| `docs/03-security-matrix.md` | Roles, permissions, agent task safety classes |
| `docs/04-coding-standards.md` | Code conventions (AI-friendly rules) |
| `docs/05-license-system.md` | Commercial license system design |
| `docs/06-installer-updater.md` | One-click install + one-click upgrade design |
| `docs/07-decision-log.md` | Architecture Decision Records (ADRs) |
| `docs/08-module-blueprint.md` | How every module is structured + how to add one |
| `docs/09-cpanel-parity-checklist.md` | ⭐ **Parity contract** — cPanel/WHM ki ek-ek tool (208 items) + step + status |
| `ROADMAP.md` | 16-step plan with current status |
| `CHANGELOG.md` | Version history |
| `project-status.md` | Live progress tracker (server setup + steps) |

Planning docs from the pre-design phase (kept for reference):
`cpanel-jaisa-custom-panel-master-plan.md`, `vps-server-guide-hosting-business.md`,
`server-requirement-analysis.md`, `aws-lightsail-setup-guide.md`, `kaam-ka-batwara-aur-server-playbook.md`.

---

## 🚦 Current Phase

**Step 0 — Blueprint** ✅ (this repo state)
**Next → Step 1 — Server base stack + one-click installer**

See `ROADMAP.md` for the full 16-step plan.

---

## ⚖️ Project Rules (non-negotiable)

1. This is a **commercial product**. Code is proprietary. License enforcement exists but
   **never disrupts end-customer websites/email** (see `docs/05-license-system.md`).
2. **Security first:** the web panel never runs as root; all privileged work goes through the
   `paneld` agent with an allowlist of task types.
3. **Billing compatibility is a product feature:** WHM API 1 responses must match cPanel's
   format exactly (so WHMCS/Blesta/Clientexec/custom billing work unchanged).
4. **AI-maintainability is a product feature:** every module must be self-documented and
   follow `docs/08-module-blueprint.md`, so any developer/AI can extend it safely.
