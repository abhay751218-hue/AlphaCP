# AlphaCP — Custom Hosting Control Panel

> **Naya AI/developer?** Sabse pehle [`START-HERE.md`](START-HERE.md) padho; server ki deployed state `server-snapshot/STATE.md` me hai.
>
> **Working title:** AlphaCP (final brand name owner decide karega)
> **Status:** 🟡 Deployed source snapshot v0.75.0; reseller-workspace foundation is being prepared as v0.76.0 on this branch. **Not deployed.**
> **Type:** Commercial hosting control panel, targeting cPanel/WHM feature parity without copying cPanel branding.

AlphaCP is a from-scratch hosting platform for hosting providers. The active Laravel panel has WHM-like server administration, reseller and customer workspaces, website/email/database/DNS/backup tools, a privileged `paneld` task agent, a license client, and a partial WHM API 1 compatibility layer. Feature completeness and production acceptance are tracked in the parity checklist; the presence of a route does not mean full cPanel parity.

## Quick facts

| Item | Value |
|---|---|
| Active panel source | `refs/panel-2b-bundle/` — Laravel 13.33.0 / PHP 8.3+ |
| Deployed panel baseline | `server-snapshot/` — v0.75.0 (latest canonical snapshot) |
| Current branch build | v0.76.0 — source artifact only; no deployment authorized |
| Frontend | Blade and the mode-aware AlphaCP shell; React/Inertia is a future direction |
| Panel database | MariaDB; separate from hosted customer databases |
| Privileged operations | `paneld` allowlisted tasks; the web panel never runs as root |
| Target OS | Ubuntu 24.04 LTS (primary), AlmaLinux 9 (secondary) |
| Compatibility target | WHM API 1 (2086/2087), selected cPanel UAPI (2082/2083), webmail (2095/2096) |
| Business model | Commercial license tiers plus reseller/OEM and multi-server plans |

## Document map

| Document | Purpose |
|---|---|
| [`START-HERE.md`](START-HERE.md) | Current repository, test, and safe-operation guide |
| [`AGENTS.md`](AGENTS.md) | Rules for AI assistants and contributors |
| [`AI_CONTEXT.md`](AI_CONTEXT.md) | Architecture and current development context |
| [`cpanel-jaisa-custom-panel-master-plan.md`](cpanel-jaisa-custom-panel-master-plan.md) | Original master plan; `docs/MASTER-PLAN.md` is not the plan path |
| [`docs/09-cpanel-parity-checklist.md`](docs/09-cpanel-parity-checklist.md) | Feature parity contract; do not remove checklist rows |
| [`docs/modules/panel-experience.md`](docs/modules/panel-experience.md) | WHM/reseller/customer workspaces and the current UI scope |
| [`docs/03-security-matrix.md`](docs/03-security-matrix.md) | Role permissions and task safety classes |
| [`ROADMAP.md`](ROADMAP.md) | 16-step delivery plan and current partial-completion state |
| [`CHANGELOG.md`](CHANGELOG.md) | Version and implementation history |
| [`project-status.md`](project-status.md) | Project/server history and status |

The remaining architecture, security, coding, licensing, installer and module documents are indexed in `docs/` and `AI_CONTEXT.md`.

## Current phase

- The deployed v0.75.0 panel in `server-snapshot/` remains the canonical baseline.
- This branch prepares a v0.76.0 source update: distinct WHM, reseller and customer visual workspaces, a reseller dashboard, and server-side reseller ownership checks for accounts and packages.
- Full 77-file panel test matrix: **481 pass, 0 fail, 6 wasm-skip** via php-wasm; browser/responsive acceptance and the remaining reseller/API security review are still pending. See `START-HERE.md` for details.
- **Do not deploy** this branch or the generated artifact without the owner's explicit authorization.

## Non-negotiable project rules

1. This is a commercial product. License enforcement must never disrupt customer websites, email, DNS or backups.
2. **Security first:** the web panel never runs as root. Privileged work goes through allowlisted `paneld` tasks.
3. WHM API 1 response formats are a compatibility contract; the API is currently only a partial implementation.
4. Preserve AlphaCP's own name, logo, iconography and visual identity. cPanel/WHM may inform feature organization, but their protected branding and assets must not be copied.
5. Every behavior change needs tests, permission/security review, documentation and a changelog entry. Keep every parity checklist row.
