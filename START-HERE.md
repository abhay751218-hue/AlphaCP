# 👋 START HERE — naye AI / developer ke liye

> **AlphaCP** ek AlphaCP-branded hosting control panel hai jo cPanel/WHM ke feature set ko target karta hai. Server ki deployed state `server-snapshot/STATE.md` me hai; latest deployed panel baseline **v0.75.0** hai. This Arena session is fixed to branch `arena/aeae010f-alphacp`.

## 1. Isi order me padho

| # | File | Kyun |
|---|---|---|
| 1 | **`server-snapshot/STATE.md`** | Server par deployed software, versions, services, ports, migrations, routes aur schema. Secrets values snapshot me nahi hoti. |
| 2 | `server-snapshot/LAST-SYNC.md` | Aakhri server sync kab hua. |
| 3 | `COMMANDS.md` | Approved server commands aur commit-pinned script references. |
| 4 | `CHANGELOG.md` | Kya kab bana/fix hua |
| 5 | `ROADMAP.md`, `project-status.md` | Steps S0–S15 aur current release state |
| 6 | `AI_CONTEXT.md`, `AGENTS.md`, `docs/04-coding-standards.md`, `docs/08-module-blueprint.md` | Rules aur architecture |
| 7 | `docs/09-cpanel-parity-checklist.md` | 208 items ka parity contract. **Koi row delete mat karna.** |
| 8 | `cpanel-jaisa-custom-panel-master-plan.md` | Original master plan; `docs/MASTER-PLAN.md` path maujood nahi hai. |

## 2. Code kahan hai

| Path | Kya |
|---|---|
| **`refs/panel-2b-bundle/`** | Active Laravel 13.33.0 panel source. Deployed v0.75.0 snapshot se sync kiya; current branch v0.76.0 work tayyar karta hai. Panel code yahin badlo. |
| `artifacts/panel-code-<ver>.tar.gz` | Reproducible code-only build (`python3 tools/build-panel-2b-bundle.py`). Current v0.76.0 artifact sirf build output hai — deployment authorization nahi. |
| `artifacts/panel-bundle-0.3.0.tar.gz` | Older vendor bundle retained for the php-wasm test harness; its composer lock matches the active panel source. |
| `server-snapshot/files/usr/local/alphacp/…` | Latest redacted deployed server snapshot. **v0.75.0 canonical baseline**; this branch is not deployed. |
| `server-snapshot/files/etc/...`, `server-snapshot/db-schema.sql` | nginx/php-fpm/systemd config and schema only (no live DB data). |
| `agent/` | Current `paneld` source and task allowlist synced from the redacted deployed snapshot; tests use `agent/config/tasks.php`. |
| `installer/` | Install/update/sync/doctor scripts. Do not run deployment/server-changing scripts without explicit authorization. |
| `tools/sim/` | Test harnesses and installer/updater simulations. |
| `panel/`, `cli/`, `license-server/` | Older scaffold or separate future work; paid-license server API is still pending. |

`server-snapshot/` me `.env`, DB password, APP_KEY, license key values ya admin password nahi hone chahiye. Values server par hi rehti hain.

## 3. User ke saath kaam karne ke rules (BINDING)

1. Jawab **Hindi/Hinglish** me do.
2. Server par user se chalwane wale steps me ek baar me sirf **ek command/step** do; output dekho phir aage badho.
3. **Untested command kabhi mat do.** Pehle reproduce karo, phir fix aur `tools/sim/` me verify karo.
4. Har server script apna **version banner** print kare.
5. Server command commit-pinned + SHA-256-verified ho. Repo private ho sakta hai, isliye `alphacp-sync get` workflow aur `COMMANDS.md` follow karo. Chat me credentials kabhi mat maango.
6. Server par change karne wali authorized script ke end me `alphacp-sync` chalna chahiye, taki redacted snapshot sync ho.
7. Behavior change ke saath tests, permissions/security review, docs, changelog aur parity status update karo.
8. Arena Agent Mode ki branch fixed hai; is session me branch switch mat karo. Deployment alag owner authorization maangta hai.

## 4. GitHub ↔ server sync

- `installer/alphacp-sync.sh` server par redacted snapshot ko configured GitHub branch ke `server-snapshot/` folder me sync karta hai.
- Sync secrets exclude karta hai; koi badlav na ho to commit nahi karta. Manual status command `sudo alphacp-sync --status` hai.
- Server sync `server-snapshot/` ko update karta hai; panel source/artifact ko deploy karne ka kaam alag hai.

## 4b. Tests (sandbox me; system PHP/MySQL ki zaroorat nahi)

| Command | Kya test karta hai | Latest result |
|---|---|---|
| `bash tools/sim/panel-tests.sh artifacts/panel-code-0.76.0.tar.gz` | Full Laravel PHPUnit set, PHP 8.5 php-wasm + SQLite; serial runner | Full test matrix: **481 pass, 0 fail, 6 wasm-skip** (same 77 files run in four isolated workers after final changes) |
| `agent/tests/run-tests.php` | Current paneld agent unit suite (PHP 8.5 php-wasm; no database) | **212 pass, 0 fail** |
| `sudo bash tools/sim/update-sim.sh` | `panel-update.sh` update, SHA mismatch, rollback, backup pruning and private-repo fetch | **54/54** (existing recorded result) |
| `sudo bash tools/sim/doctor-sim.sh` | `panel-doctor` service sandbox and password-rotation scenarios | **21/21** (existing recorded result) |
| `sudo bash tools/sim/sync-sim.sh` | `alphacp-sync` secret filtering, rebase, deploy-key flow, port-443 fallback and fetch | **60/60** (existing recorded result) |

Full panel suite ka completed v0.76.0 run used the same test files, php-wasm settings, SQLite isolation, vendor bundle, task registry, and temporary `mockConsoleOutput=false` workaround as `tools/sim/panel-tests.sh`; it ran four isolated workers to avoid the serial harness's long runtime. The two known wasm-only skips are five `PendingCommand` tests and one child-`php` config test. Real-server verification remains separate and must not be inferred from the sandbox run.

## 4c. Panel build/update recipe (general; **not authorization to deploy this branch**)

1. `refs/panel-2b-bundle/` me change + tests; `MANIFEST.json` aur `config/acp.php` version bump.
2. `python3 tools/build-panel-2b-bundle.py` → `artifacts/panel-code-<ver>.tar.gz` + SHA-256.
3. Full panel suite 0 fail; docs/changelog/parity update. Commit only to this session's fixed branch.
4. Existing server updater config/URL/SHA changes, simulation and deployment require explicit owner authorization. Is v0.76.0 UI work ke liye **koi server update authorized nahi hua**.

## 5. Abhi kahan hain

- v0.75.0 deployed snapshot remains the canonical baseline.
- Current branch prepares v0.76.0 source: distinct AlphaCP-branded WHM/operator, reseller and customer workspaces; reseller dashboard; account/package ownership scoping; `/resellers` permission tightened to `roles.manage`.
- Built artifact `artifacts/panel-code-0.76.0.tar.gz` has 500 files and SHA-256 `3b66c1e8053dd78e0819509663ac42965ae910239853dbce1c0edf28c7c45436`.
- Full panel test matrix: **481 pass, 0 fail, 6 wasm-skip**. See `docs/modules/panel-experience.md` for current implementation and remaining scope.
- Still pending: browser/responsive/accessibility review, complete reseller ACL/resource/package policy, API ownership audit, paid license-server API and production acceptance.
- **Do not deploy** the artifact or modify the live server without explicit owner authorization.

Latest production truth: `server-snapshot/STATE.md` + `server-snapshot/LAST-SYNC.md`. Latest source changes: `CHANGELOG.md`, `ROADMAP.md`, and `docs/modules/panel-experience.md`.
