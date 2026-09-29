# panel/ — AlphaCP Panel Application

**Stack:** Laravel 11 (PHP 8.3) + React 18 + TypeScript + Inertia.js + Tailwind + MariaDB + Redis
**Status:** ⏳ Not initialized yet — starts at **Step 1-2** of the roadmap.

## Read first
1. `../AI_CONTEXT.md` (context + golden rules)
2. `../docs/04-coding-standards.md` (conventions)
3. `../docs/08-module-blueprint.md` (module shape)

## Planned structure
```
panel/
├── app/
│   ├── Modules/            # ← all business modules live here (Accounts, Email, Dns, ...)
│   ├── Http/Middleware/    # panel middleware (auth, license gate, feature gates)
│   ├── Support/            # ErrorCodes, AuditLogger, PathGuard, TaskDispatcher
│   ├── Policies/           # global policies
│   └── Providers/
├── config/
│   ├── permissions.php     # permission registry (synced to DB)
│   ├── whm_token_permissions.php
│   └── license_public.pem  # embedded license public key
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/js/
│   ├── pages/              # Inertia pages mirroring routes
│   ├── components/
│   └── lib/api.ts
├── routes/
│   ├── admin.php  client.php  webhooks.php
│   ├── api/whm.php  api/v1.php  api/uapi.php
│   └── modules/<module>.php
├── tests/Feature/  tests/Unit/
└── lang/{en,hi}.json
```

## First boot (when S1-2 lands)
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm ci && npm run build
php artisan serve   # dev only; production uses nginx+fpm via installer
```

---

## Actual layout (Step 2B-1 — built)

```
panel/                      # Laravel 11 app (served on :8090 via nginx + php-fpm)
├── app/
│   ├── Support/AcpEnv.php          # reads /usr/local/alphacp/etc/*.env (single source of credentials)
│   ├── Services/AgentQueue.php     # panel → tasks table → paneld (the ONLY way to do privileged work)
│   ├── Services/Audit.php          # append-only audit_logs writer
│   ├── Http/Controllers/AuthController.php      # login/logout + brute-force throttle
│   ├── Http/Controllers/DashboardController.php # live server data via agent tasks
│   ├── Http/Middleware/PanelAuth.php|SecurityHeaders.php
│   ├── Models/User.php
│   └── Console/Commands/CreateAdmin.php   # php artisan alphacp:create-admin
├── config/panel_modules.php        # cPanel 9-section tile map (each tile = roadmap step)
├── resources/views/{layouts/panel,auth/login,dashboard,coming-soon}.blade.php
├── public/css/panel.css            # hand-written styles (no build step required)
└── routes/web.php                  # /login, /logout, / (dashboard)
```

**Rules for this app:**
1. Never `exec`/`shell_exec` here — queue a task (`AgentQueue`) and let `paneld` run it (ADR-0002).
2. Never store DB credentials in `.env` — they come from `etc/database.env` via `AcpEnv`.
3. New UI page = route + controller + blade + tile entry in `config/panel_modules.php`.
4. After any change: `python3 tools/build-panel-installer.py` (rebuilds `panel-install.sh`).
