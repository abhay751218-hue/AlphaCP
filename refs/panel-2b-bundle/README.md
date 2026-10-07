# AlphaCP Panel

The panel app for **AlphaCP** — a cPanel-equivalent hosting control panel (our own code, own brand).
Laravel 13 + Blade, no build step, no CDN, no Node.

- **URL (installed):** `https://<server>:8090/` (8090 is AlphaCP's primary port, CyberPanel-style;
  2082/2083/2086/2087 stay reserved for cPanel-compatible clients + WHM API 1).
- **Runs as:** unprivileged system user `alphacp`, behind its own nginx vhost and PHP-FPM pool.
- **Privileged work:** never here. The panel writes rows to `tasks`; the root agent `paneld`
  (see `../agent/`) validates them against its allowlist and executes.

## What works today (Step 5 / panel 0.76.0)
Login with lockout + rate limit · TOTP 2FA · password policy + force-change ·
RBAC · users CRUD · audit log · live server info / services / task queue ·
offline-first license/trial · **Accounts**: create / list / suspend / unsuspend / terminate
(OS work is paneld-only; create rolls back on failure).
**3 colour panels (0.76.0):** `body[data-panel]` — `whm` = dark navy + left sidebar (Server Manager),
`cpanel` = light + cPanel blue (customer), `webmail` = teal. Sab colours `public/assets/panel.css`
ke CSS variables me; naya theme = naya variable block (`tests/Feature/PanelThemeTest.php` contract).

Everything else on the cPanel tool list is planned and tracked — one row per tool — in
`../docs/09-cpanel-parity-checklist.md` (the written contract: rows are never removed, only marked done).

## Local development

```bash
cp .env.example .env          # if you don't have one yet
php artisan key:generate
# point DB_* at a dev database, then:
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8090
```

On a real server the database credentials live in `/usr/local/alphacp/etc/panel.env`
(mode 0640, root:alphacp) and are read by `config/database.php` — see `../docs/07-decision-log.md`
ADR-0011. Do not put them in `.env`.

## Tests

```bash
php artisan config:clear     # required: a cached config overrides phpunit.xml
php artisan test             # 41 tests / 543 assertions
```

The suite runs against a dedicated database (`alphacp_test`; override with `ACP_TEST_DATABASE`) and
**refuses to run against anything else** — it uses `RefreshDatabase`, and refusing is the point
(ADR-0012). These same tests are executed by the installer as its self-test, so the suite is
effectively production code: keep it green.

## Installing / upgrading on a server

```bash
curl -sSL https://paste.rs/VuP2l -o /tmp/alphacp-step2b.sh
sudo bash /tmp/alphacp-step2b.sh --yes
```

Full runbook (Hindi): `../docs/runbooks/step-2b-panel.md`.
Reset a locked-out admin: `sudo -u alphacp php artisan alphacp:admin-password admin --password='…' --reset-2fa`.

## Layout

| Path | What lives there |
|---|---|
| `app/Support/` | `Paneld` (task client), `Panel`, `Audit`, `Totp`, `PanelEnv`, `ModuleCatalog`, `PermissionCatalog`, `LicenseClient` |
| `app/Http/Controllers/` | thin controllers (validate → Support/model → audit → redirect) |
| `app/Http/Middleware/` | `2fa`, `password.fresh`, `perm`, `PanelSecurityHeaders` |
| `resources/views/` | Blade templates (layouts, partials, one folder per area) |
| `public/assets/panel.css` | the entire frontend (hand-written, CSP-safe) |
| `deploy/` | nginx + php-fpm templates the installer writes to the server |
| `database/migrations/` | panel tables (+ agent-side tables as a no-op-on-servers safety net) |
