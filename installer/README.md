# installer/ — One-Click Install, Update & Rollback System

**Status:** ⏳ Not implemented yet — starts at **Step 1**.
**Design doc:** `../docs/06-installer-updater.md` (read it before writing code here)

## Pieces
| Piece | Path (planned) | Purpose |
|---|---|---|
| Bootstrap | `install.sh` | tiny bash: curl-pipe-able, detects OS/arch, downloads signed payload |
| Installer payload | `alphacp-installer` (PHP CLI or bash) | 5 phases: preflight → packages → services → panel deploy → admin setup → verify |
| Self-tests | `self-test/` | verify services, panel HTTP, agent round-trip, mail loopback |
| Release builder | `build/` | packs `alphacp-<version>.tar.gz` + manifest.json + Ed25519 signature |
| systemd units | `systemd/` | alphacp-web, alphacp-worker, alphacp-scheduler, paneld |
| CLI | ships in panel | `alphacp install/update/rollback/doctor/status/node join/support-bundle` |

## Guarantees to preserve (from design doc)
- Idempotent + resumable (state file `var/install.state`)
- Fully logged (`/var/log/alphacp-install.log`)
- Non-destructive to existing sites/websites on the server
- Update flow: preflight → verify signature → snapshot → migrate → atomic symlink swap →
  health check → auto-rollback on failure
- **Customer websites/email are never interrupted by panel updates**

## Test matrix (per release)
Ubuntu 24.04/22.04 · Alma 9 · fresh install · node join · update with live accounts ·
failure injection (rollback) · interrupted-install resume.


---

## Existing-server panel updater

`panel-update.sh` is for a panel that is already installed at `/usr/local/alphacp/panel`.
It downloads the pinned active panel artifact, verifies SHA-256, stages Composer and Laravel
preflight as `alphacp`, creates the PHP-FPM sandbox drop-in, atomically swaps the panel, checks
HTTP 200, and automatically restores the previous release if the health check fails.
It preserves `.env`/APP_KEY, database data, admin credentials, sessions and panel storage.
It does not install the base server stack and must not be used on a blank VPS.

Default artifact can be overridden for a release mirror:
`ACP_PANEL_BUNDLE_URL=... ACP_PANEL_BUNDLE_SHA256=... sudo -E bash installer/panel-update.sh`

## Step 2 installer (agent + queue)

| File | Kya hai |
|---|---|
| `step2-install.sh.in` | **Hand-written source** — installer logic (phases, flags, verify). Yahan edit karo. |
| `step2-install.sh` | **Generated** — payload (agent + CLI + migrations) embedded, sha256-protected. |
| `../tools/build-step2-installer.py` | Rebuild: `python3 tools/build-step2-installer.py` |

Flow: `agent/`, `cli/`, `db/migrations/` me kuch bhi badlo → build script chalao → naya
`step2-install.sh` ban jata hai (bash -n checked) → upload/run. Server par sirf ek file jati hai,
par source of truth hamesha repo rehta hai.
