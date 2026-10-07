# AlphaCP — Master plan / continuation entry point

**Updated:** 2026-10-07 · **Language:** Hinglish · **Status:** source restored; palette prototype tested locally; deployment blocked by full-suite review

> Har naya session yahan se shuru kare. Purane roadmap ke S3 ko blindly repeat mat kare.
> Target cPanel/WHM-compatible behaviour hai, cPanel branding ya closed-source code copy nahi.
> `docs/09-cpanel-parity-checklist.md` parity contract hai; route/tile milna completion proof nahi.

## 1. Abhi ka verified repo baseline

| Evidence | Value / meaning |
|---|---|
| `server-snapshot/LAST-SYNC.md` | 2026-10-07 14:59:14 UTC (captured state, not a fresh live probe) |
| Snapshot panel `MANIFEST.json` | **0.75.0**, 500 captured files |
| Snapshot `.env` version labels in `STATE.md` | `ACP_VERSION` / `AGENT_VERSION` **0.83.0**; these do not prove panel code release 0.83.0 |
| Editable `refs/panel-2b-bundle/MANIFEST.json` | **0.3.2**, 95 files; stale release source |
| Old `panel/` | Legacy scaffold; do not implement the new UI here |
| Snapshot vs release source | 405 additional files, 44 changed shared files; no source-only files; composer.lock byte-identical |
| Captured tests | 78 test files; existence is not a passing test result |

### Owner confirmation (7 Oct, server terminal screenshot)
- Live `MANIFEST.json` reports panel code **0.75.0**, matching the captured manifest.
- Owner reports all panel roles currently use one login page. A previous separation attempt caused
  HTTP 500, which is now fixed; the panel currently works. The earlier 500 cause is **not yet verified**.
- Preserve the working entry while investigating. Next read-only diagnostic checks actual listeners
  on 8090/2087/2083/2096; do not enable entry denial or alter nginx before reviewing the result.

Detailed findings: [source baseline audit](research/2026-10-07-source-baseline.md).
The snapshot includes accounts/packages/domains/files/mail/MySQL/DNS/backups and entry separation work,
which the September roadmap does not accurately reflect. Do not declare these fully tested merely from their names.

## 2. Official cPanel reference audit

Reviewed official public documentation on 2026-10-07:

| Interface | Official reference | Verified design points |
|---|---|---|
| Customer | https://docs.cpanel.net/cpanel/the-cpanel-interface/the-cpanel-interface/ | Tools default home; persistent navigation/main menu; grouped tools; account general information and statistics; feature availability controlled by provider |
| Server / reseller | https://docs.cpanel.net/whm/the-whm-interface/the-whm-interface/ | Top bar, category sidebar, tool/account search, favorites, statistics, server monitoring; reseller access controlled by privileges |
| Branding | https://docs.cpanel.net/whm/cpanel/customization/ | Light/dark logos, menu color, favicon, help/docs links, public contact; this customization updates cPanel, **not WHM** |
| Webmail | https://docs.cpanel.net/webmail/the-webmail-interface/ | Separate mail workflow; inbox/Roundcube, mail settings, quota, preferences, logout; access via 2096 or Check Email |

These pages were last modified 2026-07-08; displayed current documentation versions were respectively
132+, 136+, 108+, and 130+. This is a **four-interface reference audit**, not an audit of every tool/API.
The checklist's cPanel 138/Meridian/AI claims remain unverified here. Don't use those claims as a release requirement without checking official versioned documentation.

## 3. Alag panels / alag colors — proposed AlphaCP design

Palette choices below are **AlphaCP proposals**, not cPanel defaults or shipped features.

| AlphaCP surface | Palette | Visible workflow | Security boundary |
|---|---|---|---|
| Server Manager | Navy `#14243B`, blue `#2563EB` | Accounts, packages, services, DNS, backups, monitoring, licenses | Existing server permissions; never expose root tasks to customers |
| Reseller workspace | Purple `#6D28D9`, pale `#F5F3FF` | Owned accounts/packages, usage and branding | Existing ownership + permission checks; colors never grant access |
| Account Panel | Teal `#0F766E`, white `#FFFFFF` | Grouped Files/Email/Databases/Domains/Metrics/Security/Software/Advanced/Preferences | Account-scoped tools and real quotas; no global server/audit data |
| Mail workspace | Blue `#0369A1`, pale `#F0F9FF` | Mailbox, inbox, filters, forwarders, settings | Mailbox scope; no customer file/DB access |

Start with the existing auth/2FA/password middleware and existing URLs/ports. Any port/URL change,
new dependency, or schema change needs owner approval first. Existing `ModuleCatalog::modeFor()`
uses `whm`/`cpanel`; reseller/mail presentation must be added without weakening its authorization.
A colored header alone is not a separate secure panel.

## 4. Execution order (continue here)

### A — Source reconciliation (next; release blocker)
- [x] Compare actual captured state with editable source and document drift.
- [x] Add fail-closed stale-source build guard; no artifact written when source predates snapshot.
- [ ] Reconcile the 500 snapshot panel files into the editable source after reviewing auth, ownership,
  migrations, agent task compatibility and missing deployment inputs. Never edit generated `server-snapshot/`.
- [ ] Reconcile agent source/allowlist too; do not ship newer panel tasks against the old agent.
- [ ] Review manifest file-count/version drift and preserve runtime secrets outside Git.
- [ ] Run the captured PHP suite against a temporary reconciled tree; record failures and skips honestly.
- [ ] Refresh roadmap/checklist only from reproducible tests + server acceptance evidence.

### B — Separate-panel shell (first UI delivery)
- [ ] Add presentation context derived from authenticated server-side identity, not query parameters/ports.
- [ ] Separate server/reseller/customer/mail palettes and labels; consistent shared components.
- [ ] Persistent category sidebar/top bar, tool search with `/` shortcut, keyboard/mobile support.
- [ ] Real account information and resource statistics on customer home; avoid fake usage percentages.
- [ ] Permission-filtered tool navigation; pending tools explicitly disabled, not marked functional.
- [ ] Regression tests: root/reseller/customer/mail; denied direct URLs; cross-account access;
  no global stats on customer pages; escaped names; keyboard/responsive checks.
- [ ] Update rows 89/126/127 and related layout rows only to the extent actually delivered.

### C — Feature-by-feature parity, not a cosmetic clone
For every checklist row: official reference → expected inputs/results → existing code audit →
permission/ownership checks → implementation → agent tests if privileged → feature tests →
API shape fixtures if applicable → module docs/audit events → server acceptance → status update.
Prioritize current gaps and security defects before duplicating modules already captured in the snapshot.

### D — Release
Reproducible code-only artifact, matching agent version, migration rehearsal, rollback simulation,
commit-pinned checksum-verified deployment recipe, server acceptance and fresh sync. No deploy
command is approved by this initial audit. Do not push to main or mutate the live server from this session.

## 5. Tests completed in this continuation

- `python3 -m unittest discover -s tools/tests -v`: **5 passed** (baseline guard regression tests).
- `python3 tools/build-panel-2b-bundle.py`: expected refusal: source 0.3.2 < snapshot 0.75.0;
  no artifact generated/overwritten.
- PHP application tests, live login, visual and service acceptance: **not run** in this initial audit.

Legacy full design: [original master plan](../cpanel-jaisa-custom-panel-master-plan.md).
Rules: [AGENTS](../AGENTS.md), [coding standards](04-coding-standards.md),
[module blueprint](08-module-blueprint.md), [security matrix](03-security-matrix.md).

## Latest continuation — after owner screenshots
- Live owner/customer dashboards rendered at 8090; selected ports only 8090 listen (IPv4/IPv6).
- Customer direct `/accounts` returned 403. This is one acceptance case, not full isolation proof.
- Restored 500 captured panel files and 126 captured agent files into editable sources. Hash inventory:
  `docs/research/reconciled-baseline.json`. Generated snapshots untouched.
- Local candidate 0.84.0 adds four identity-based palettes/labels, mail nav and English/Hindi labels.
  Agent source remains captured 0.83.0; no new privileged code or login/port change.
- New appearance feature tests: 12 passed / 46 assertions. Agent baseline 212 passed / 0 failed.
- Full captured panel suite still running; BackupRestorationTest and DashboardShellTest failures
  already observed (some assertions reference obsolete WHM/cPanel labels). Must triage, not hide.
- Do NOT deploy current candidate until full suite + release/rollback simulations pass.

## Service-port target approved by owner (7 Oct)
Official login documentation reviewed: 2087 manager/reseller, 2083 hosting account, 2096 mailbox webmail.
8090 stays working during rollout. No new live listener yet.
Detailed security/release contract: `docs/modules/entry-separation.md`.
Existing entry-gate tests: 10 passed / 42 assertions. Mailbox login and same-host per-port session
isolation are pending; a POST-only port gate is not complete service separation.

### Latest triage
Isolated BackupRestorationTest rerun: 6 pass / 25 assertions (wasm). Earlier full-run
failures are not reproduced in this isolated run; investigate runner/shared-state before changing
application code. Runner now retains each PHPUnit output and treats missing test results as failures.
Next owner read-only check: current nginx panel vhost (listen/fastcgi parameters), no reload.

### Stage 1 entry installer ready
Manager-entry v0.1.0 is a standalone listener-only rollout; it does not ship the candidate panel bundle.
TLS 2087 added; 8090 retained; no auth/DB/PHP/firewall/2083/2096 changes. Five simulated rollout
scenarios pass; real nginx and external acceptance remain pending. See entry-separation module doc.

### Owner stage-one result (7 Oct 23:09 screenshot)
manager-entry 0.1.0 checksum verified; default `nginx -t` passed, but loaded-vhost
identity check refused: `Panel vhost is not loaded by nginx; refusing change.`
Stopped before config edit/reload/backup phase: no listener added by this invocation.
Possible causes: sites-enabled symlink path differs from sites-available, or master uses
custom `-c` wrapper config. Not confirmed; do not remove safety check or rerun unchanged.
Next read-only diagnostic: `ps -C nginx -o pid=,args=` to identify running master arguments.

### Follow-up manager-entry v0.1.1
Owner master command has no visible custom `-c`, though screenshot line is truncated.
Fixed exact-string include check to same-inode verification; symlink accepted, distinct copy/unloaded
path rejected. Eight simulated scenarios pass. Retry only the new pinned/checksummed script;
if it still refuses, inspect actual nginx dump paths instead of bypassing identity checks.
