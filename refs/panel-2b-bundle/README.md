# AlphaCP Panel Bundle

**Source status:** v0.76.0 branch build, **not deployed**. The canonical deployed baseline remains v0.75.0 in `../../server-snapshot/`.

This is the active Laravel 13.33.0 / PHP 8.3+ panel source. It provides one shared application with separate AlphaCP-branded WHM/operator, reseller and customer workspaces. Role-aware navigation and colors are presentation only; every route, query and mutation must continue to enforce permission and ownership server-side. The web process is unprivileged; privileged server work must go through allowlisted `paneld` tasks.

## v0.76.0 scope

- Distinct indigo WHM/operator, emerald/teal reseller, and light-blue customer palettes/navigation.
- Reseller dashboard with scoped account/package summaries and recent clients.
- Reseller account and package access restricted to owned records plus explicitly global packages.
- Reseller-created accounts/packages record the reseller owner; resellers cannot set the global default package.
- `/resellers` requires `roles.manage`.

This is not three independent deployments or a claim of full cPanel/WHM feature parity. Complete reseller ACLs, resource caps, white-label branding, browser acceptance, and API ownership review remain. See `../../docs/modules/panel-experience.md` and `../../docs/09-cpanel-parity-checklist.md`.

## Important code locations

| Path | Responsibility |
|---|---|
| `app/Support/ModuleCatalog.php` | Workspace mode and audience-aware module navigation |
| `resources/views/layouts/panel.blade.php` | Shared role-aware shell |
| `public/assets/panel.css` | AlphaCP color tokens and responsive shell styling |
| `app/Http/Controllers/DashboardController.php` | Role-scoped dashboard data |
| `app/Http/Controllers/AccountsController.php` | Account listing, creation, ownership and mutations |
| `app/Http/Controllers/PackagesController.php` | Package visibility, ownership and mutation checks |
| `routes/web.php` | Authenticated routes and permission middleware |
| `tests/Feature/` | Feature/security regression tests |

## Build and verify

From the repository root:

```bash
python3 tools/build-panel-2b-bundle.py
bash tools/sim/panel-tests.sh artifacts/panel-code-0.76.0.tar.gz
```

The current code-only archive is `artifacts/panel-code-0.76.0.tar.gz` (500 files). The build tool prints its SHA-256; the repository-root `CHANGELOG.md` and `START-HERE.md` record the current checksum. The complete 77-file PHP 8.5/php-wasm test matrix passed **481 tests, 0 failures, 6 wasm-only skips** using four isolated workers. The sandbox lacks system PHP; the documented runner uses the vendor bundle whose lockfile matches this source.

Do not upload, install or deploy this artifact without explicit owner authorization. `server-snapshot/` remains unchanged and is the production source of truth.
