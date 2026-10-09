#!/usr/bin/env bash
# ============================================================================
# AlphaCP design-parity v1.0 — cPanel Jupiter-jaisa look (sab panels)
#   light header + left sidebar tree (WHM & cPanel) + stroke-SVG icon set +
#   emoji-free tiles/cards + asset cache-bust. SIM=1 safe.
# ============================================================================
set -euo pipefail
VERSION="1.0"
SIM="${SIM:-0}"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL="${ACP_HOME}/panel"
LOGD="${ACP_HOME}/logs"; mkdir -p "$LOGD"
LOG="${LOGD}/design-parity-$(date +%Y%m%d%H%M%S).txt"
exec > >(tee -a "$LOG") 2>&1
C_G=$'\033[0;32m'; C_R=$'\033[0;31m'; C_Y=$'\033[0;33m'; C_0=$'\033[0m'
ok()   { printf '  %s✔%s %s\n' "$C_G" "$C_0" "$*"; }
warn() { printf '  %s!%s %s\n' "$C_Y" "$C_0" "$*"; }
die()  { printf '  %s✖%s %s\n' "$C_R" "$C_0" "$*"; exit 1; }
hdr()  { printf '\n== %s ==\n' "$*"; }
info() { printf '  · %s\n' "$*"; }
TS="$(date +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/design-parity-${TS}"
mkdir -p "$BACKUP"
backup() { local f; for f in "$@"; do [[ -f "$f" ]] || continue; mkdir -p "${BACKUP}/$(dirname "$f")"; cp -a "$f" "${BACKUP}/$f"; done; }
if [[ "${1:-}" == "--rollback" ]]; then
  B="${2:?usage: --rollback <backup-dir>}"
  hdr "ROLLBACK — design-parity v${VERSION}"
  ( cd "$B" && find . -type f | sed 's|^\./||' | while read -r rel; do mkdir -p "/$(dirname "$rel")" && cp -a "$B/$rel" "/$rel"; done )
  ok "rollback complete"; exit 0
fi
printf '\n== DESIGN-PARITY v%s (cPanel Jupiter look) ==\n' "$VERSION"
info "backup: ${BACKUP}"
hdr "panel view payloads (layout/css/dashboards/partials/icons)"
backup "${PANEL}/resources/views/layouts/panel.blade.php"
backup "${PANEL}/public/assets/panel.css"
backup "${PANEL}/resources/views/dashboard-whm.blade.php"
backup "${PANEL}/resources/views/dashboard-cpanel.blade.php"
backup "${PANEL}/resources/views/partials/tile.blade.php"
backup "${PANEL}/resources/views/partials/dash-sections.blade.php"
backup "${PANEL}/resources/views/partials/whm-sidebar.blade.php"
backup "${PANEL}/resources/views/partials/icons.blade.php"
backup "${PANEL}/resources/views/partials/cpanel-sidebar.blade.php"
mkdir -p "${PANEL}/resources/views/layouts" "${PANEL}/resources/views/partials" "${PANEL}/public/assets"
cat > "${PANEL}/resources/views/layouts/panel.blade.php" <<'PEOF'
{{-- REBRAND_DONE --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ config('acp.brand.name', 'AlphaCP') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ @filemtime(public_path('assets/panel.css')) ?: config('acp.version') }}">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="logo">A</span>
        <span>
            {{ config('acp.brand.name', 'AlphaCP') }} {{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : '' }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : 'Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>

    <button class="nav-toggle" id="acp-nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button>
    <nav class="topnav" id="acp-topnav" aria-label="Main">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        @if (($panelMode ?? 'cpanel') === 'whm')
            @can('accounts.view')<a href="{{ route('accounts.index') }}">Accounts</a>@endcan
            @can('packages.view')<a href="{{ route('packages.index') }}">Packages</a>@endcan
            @can('users.view')<a href="{{ route('users.index') }}">Users</a>@endcan
            @can('system.view')<a href="{{ route('system.index') }}">System</a>@endcan
        @else
            @can('domains.view')<a href="{{ route('domains.index') }}">Domains</a>@endcan
            @can('software.view')<a href="{{ route('php.index') }}">MultiPHP</a>@endcan
            @can('cron.view')<a href="{{ route('cron.index') }}">Cron</a>@endcan
            @can('ssl.view')<a href="{{ route('ssl.index') }}">SSL</a>@endcan
            <a href="{{ route('security.index') }}">Security</a>
        @endif
    </nav>

    <input type="search" id="acp-search" class="searchbox" placeholder="Search tools…" autocomplete="off" aria-label="Search tools">

    <span class="spacer"></span>

    <div class="meta">
        server: <strong>{{ $server['hostname'] ?? 'unknown' }}</strong><br>
        panel {{ config('acp.version') }} · agent {{ config('acp.agent_version') }}
    </div>

    <div class="user">
        <span class="avatar">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
        <span class="meta">
            {{ auth()->user()?->username ?? 'Guest' }}<br>
            <span class="muted">{{ auth()->user()?->role?->label ?? 'user' }}</span>
        </span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="btn small secondary" type="submit">Logout</button>
        </form>
    </div>
</header>

@if (($panelMode ?? 'cpanel') === 'whm')
<div class="nav-backdrop" aria-hidden="true"></div>
<div class="shell whm-shell">
    <aside class="side">
        @include('partials.whm-sidebar', [])
    </aside>
    <main class="wrap main-col">
@else
<div class="shell">
    <aside class="side">
        @include('partials.cpanel-sidebar', [])
    </aside>
    <main class="wrap main-col">
@endif
    @include('partials.flash', [])

    <div class="page-head">
        <div>
            <h1>@yield('title', 'Dashboard')</h1>
            <p>@yield('subtitle', '')</p>
        </div>
        <div class="push row">
            @yield('actions')
        </div>
    </div>

    @yield('content')
</main>
</div>

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    {{ config('acp.brand.name', 'AlphaCP') }} {{ config('acp.version') }} — {{ config('acp.brand.tagline', 'Hosting control panel') }} ·
    parity checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>

<script>
/* cPanel-style top search: dashboard ke tool tiles live filter karta hai. */
(function () {
    var q = document.getElementById('acp-search');
    if (!q) { return; }
    q.addEventListener('input', function () {
        var v = q.value.trim().toLowerCase();
        document.querySelectorAll('.grid.tiles').forEach(function (grid) {
            var visible = 0;
            grid.querySelectorAll('.tile').forEach(function (tile) {
                var name = tile.querySelector('.name');
                var hit = v === '' || (name && name.textContent.toLowerCase().indexOf(v) !== -1);
                tile.classList.toggle('hidden', !hit);
                if (hit) { visible++; }
            });
            var head = grid.previousElementSibling;
            if (head && head.classList.contains('section-title')) {
                head.classList.toggle('hidden', visible === 0 && v !== '');
            }
        });
    });
})();
</script>
<script>
/* P-UI-5.1: mobile hamburger — body.nav-open toggle karta hai; CSS chhoti
   screens par sidebar ko off-canvas drawer + topnav ko stack banata hai
   (real WHM/cPanel mobile-jaisa), desktop par kuch nahi badalta. */
(function () {
    var b = document.getElementById('acp-nav-toggle');
    if (!b) { return; }
    b.addEventListener('click', function () {
        var open = document.body.classList.toggle('nav-open');
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
        if (!document.body.classList.contains('nav-open')) { return; }
        if (e.target.closest && e.target.closest('.side, .topbar')) { return; }
        document.body.classList.remove('nav-open');
        b.setAttribute('aria-expanded', 'false');
    });
})();
</script>
</body>
</html>
PEOF
cat > "${PANEL}/public/assets/panel.css" <<'PEOF'
/* ==========================================================================
   AlphaCP panel styles — hand-written (no build step, no CDN).
   P-UI-1: cPanel Paper-Lantern jaisa LIGHT "paper" theme — paper-white cards,
   navy top bar (#1c2733), orange accent (#FF6C2C/#FE7A00); WHM blocks navy
   (#22303f). Layout: top bar + search + section grid + tiles + right sidebar
   (General Information / Statistics) customer dashboard par.
   Keep this file the ONLY stylesheet: panel pages must work offline.
   ========================================================================== */

:root {
  --bg: #eef1f4;
  --bg-2: #ffffff;
  --panel: #ffffff;
  --panel-2: #f2f5f7;
  --line: #d9e0e6;
  --text: #243140;
  --muted: #64748b;
  --navy: #1c2733;
  --navy-2: #22303f;
  --accent: #FF6C2C;
  --accent-2: #FE7A00;
  --green: #16a34a;
  --amber: #d97706;
  --red: #dc2626;
  --radius: 8px;
  --shadow: 0 1px 2px rgba(16, 24, 40, .08);
  font-synthesis: none;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif;
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}

a { color: #b44a17; text-decoration: none; }
a:hover { text-decoration: underline; }

.hidden { display: none !important; }

/* ---------------------------------------------------------------- top bar */
.topbar {
  display: flex; align-items: center; gap: 14px;
  padding: 10px 18px;
  background: var(--navy);
  border-bottom: 3px solid var(--accent);
  position: sticky; top: 0; z-index: 20;
  color: #eef2f6;
}
.brand { display: flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: .3px; color: #fff; }
.brand .logo {
  width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center;
  background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; font-weight: 900;
}
.brand small { display: block; font-weight: 500; color: #9fb0c0; font-size: 11px; letter-spacing: .2px; }
.topbar .spacer { flex: 1; }
.topnav { display: flex; gap: 4px; flex-wrap: wrap; }
.topnav a {
  color: #dfe7ee; font-size: 13px; font-weight: 600;
  padding: 6px 10px; border-radius: 6px; text-decoration: none;
  border: 1px solid transparent;
}
.topnav a:hover { background: var(--navy-2); border-color: #35455a; text-decoration: none; }
.topbar .meta { color: #9fb0c0; font-size: 12px; text-align: right; }
.topbar .user { display: flex; align-items: center; gap: 10px; color: #eef2f6; }
.avatar {
  width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center;
  background: var(--navy-2); border: 1px solid #35455a; font-weight: 700; font-size: 13px; color: #fff;
}

/* cPanel-style top search — dashboard tiles filter karta hai (JS layout me) */
.searchbox {
  width: 240px; padding: 7px 12px 7px 30px; border-radius: 99px; font-size: 13px;
  border: 1px solid #35455a; background: var(--navy-2) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%239fb0c0' stroke-width='2'><circle cx='6' cy='6' r='4.5'/><path d='M9.5 9.5 13 13'/></svg>") 10px 50% no-repeat;
  color: #eef2f6;
}
.searchbox::placeholder { color: #8fa1b3; }
.searchbox:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

/* ---------------------------------------------------------------- layout */
.wrap { max-width: 1220px; margin: 0 auto; padding: 22px 18px 60px; }
.page-head { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.page-head h1 { font-size: 21px; letter-spacing: .2px; }
.page-head p { color: var(--muted); font-size: 13px; }
.push { margin-left: auto; }

/* customer dashboard: main tool-grid + right sidebar (cPanel jaisa) */
.dash-cols { display: grid; grid-template-columns: minmax(0, 1fr) 292px; gap: 16px; align-items: start; }
.dash-side .card { margin-bottom: 14px; }
.dash-side .card h3 {
  background: var(--navy); color: #fff; margin: -16px -16px 12px; padding: 9px 14px;
  border-radius: var(--radius) var(--radius) 0 0; font-size: 13px; letter-spacing: .3px;
}
@media (max-width: 980px) { .dash-cols { grid-template-columns: 1fr; } }

.grid { display: grid; gap: 14px; }
.grid.cols-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
.grid.cols-3 { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
.grid.cols-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
.grid.tiles { grid-template-columns: repeat(auto-fill, minmax(205px, 1fr)); }

/* ---------------------------------------------------------------- cards */
.card {
  background: var(--panel);
  border: 1px solid var(--line); border-radius: var(--radius);
  padding: 16px 16px 14px; box-shadow: var(--shadow);
}
.card h3 { font-size: 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.card .hint { color: var(--muted); font-size: 12.5px; }

.stat { display: flex; align-items: baseline; gap: 8px; }
.stat .num { font-size: 25px; font-weight: 800; letter-spacing: .3px; }
.stat .unit { color: var(--muted); font-size: 12.5px; }

.meter { height: 8px; border-radius: 99px; background: #e5eaf0; border: 1px solid var(--line); overflow: hidden; margin-top: 10px; }
.meter > span { display: block; height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent-2)); }
.meter.green > span { background: linear-gradient(90deg, #15803d, var(--green)); }
.meter.amber > span { background: linear-gradient(90deg, #b45309, var(--amber)); }

/* ---------------------------------------------------------------- tiles */
.section-title { display: flex; align-items: center; gap: 10px; margin: 24px 0 10px; border-bottom: 2px solid var(--line); padding-bottom: 6px; }
.section-title h2 { font-size: 15px; letter-spacing: .3px; color: var(--navy); }
.section-title .count { color: var(--muted); font-size: 12px; }

.tile {
  display: flex; gap: 12px; align-items: flex-start;
  background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius);
  padding: 12px 13px; transition: transform .12s ease, border-color .12s ease, box-shadow .12s ease;
  color: var(--text); text-decoration: none; min-height: 64px; box-shadow: var(--shadow);
}
.tile:hover { transform: translateY(-1px); border-color: var(--accent); box-shadow: 0 3px 10px rgba(16,24,40,.12); text-decoration: none; }
.tile .ico {
  width: 34px; height: 34px; flex: 0 0 34px; border-radius: 8px; display: grid; place-items: center;
  background: #fff3ec; border: 1px solid #ffd9c4; font-size: 16px;
}
.tile .name { font-weight: 600; font-size: 13px; line-height: 1.3; }
.tile .sub { color: var(--muted); font-size: 11px; margin-top: 3px; }
.tile.live { border-color: #cfe6d6; }
.tile.live .ico { background: #eaf7ef; border-color: #bfe3c8; }
.tile.disabled { opacity: .58; }
.tile.disabled:hover { transform: none; border-color: var(--line); box-shadow: var(--shadow); }

.badge {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px;
  border: 1px solid var(--line); background: var(--panel-2); color: var(--muted);
}
.badge.green { color: #15803d; border-color: #bfe3c8; background: #eaf7ef; }
.badge.amber { color: #b45309; border-color: #f0d9b0; background: #fdf4e3; }
.badge.red   { color: #b91c1c; border-color: #f0bfbf; background: #fdeaea; }
.badge.blue  { color: #1d4ed8; border-color: #c4d4f0; background: #ecf2fd; }

/* ---------------------------------------------------------------- tables */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--muted); font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; }
tr:last-child td { border-bottom: none; }
td.mono, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.table-wrap { overflow-x: auto; }

/* ---------------------------------------------------------------- forms */
label { display: block; font-size: 12.5px; color: var(--muted); margin: 12px 0 5px; }
input[type=text], input[type=password], input[type=email], input[type=number], select, textarea {
  width: 100%; padding: 9px 12px; border-radius: 6px; color: var(--text);
  background: #fff; border: 1px solid var(--line); font-size: 13.5px; font-family: inherit;
}
input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.help { color: var(--muted); font-size: 12px; margin-top: 5px; }
.error { color: #b91c1c; font-size: 12.5px; margin-top: 5px; }

.btn {
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
  border-radius: 6px; border: 1px solid #d9531a; background: linear-gradient(180deg, var(--accent), var(--accent-2));
  color: #fff; font-weight: 650; font-size: 13.5px; padding: 9px 16px; text-decoration: none;
}
.btn:hover { filter: brightness(1.06); text-decoration: none; }
.btn.secondary { background: #fff; border-color: var(--line); color: var(--text); }
.btn.danger { background: linear-gradient(180deg, #ef4444, #dc2626); border-color: #b91c1c; }
.btn.ghost { background: transparent; border-color: var(--line); color: var(--muted); }
.btn.small { padding: 6px 11px; font-size: 12px; }

/* ---------------------------------------------------------------- flash */
.flash { border-radius: 6px; padding: 11px 14px; margin-bottom: 14px; font-size: 13px; border: 1px solid; }
.flash.success { background: #eaf7ef; border-color: #bfe3c8; color: #15803d; }
.flash.warning { background: #fdf4e3; border-color: #f0d9b0; color: #b45309; }
.flash.error   { background: #fdeaea; border-color: #f0bfbf; color: #b91c1c; }
.flash.info    { background: #ecf2fd; border-color: #c4d4f0; color: #1d4ed8; }

/* ---------------------------------------------------------------- login */
.auth-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--navy); }
.auth-card { width: 100%; max-width: 420px; }
.auth-card .brand { justify-content: center; margin-bottom: 18px; }
.auth-card h1 { font-size: 19px; text-align: center; margin-bottom: 4px; }
.auth-card .sub { text-align: center; color: var(--muted); font-size: 13px; margin-bottom: 18px; }
.auth-foot { text-align: center; color: var(--muted); font-size: 12px; margin-top: 16px; }

/* ---------------------------------------------------------------- misc */
.kv { display: grid; grid-template-columns: 130px 1fr; gap: 4px 12px; font-size: 13px; }
.kv dt { color: var(--muted); }
.kv dd { margin: 0; word-break: break-word; }
.row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.muted { color: var(--muted); }
.right { text-align: right; }
.mt { margin-top: 14px; }
.mb { margin-bottom: 14px; }
.empty { color: var(--muted); font-size: 13px; padding: 14px 0; }

@media (max-width: 720px) {
  .topbar { flex-wrap: wrap; }
  .searchbox { width: 100%; order: 5; }
  .kv { grid-template-columns: 1fr; gap: 2px; }
  .brand small { display: none; }
}

/* ==========================================================================
   WHM (Server Manager) — left sidebar tree, cPanel WHM jaisa.
   ========================================================================== */
.whm-shell {
  display: grid; grid-template-columns: 268px minmax(0, 1fr);
  gap: 18px; align-items: start; padding: 18px 22px;
}
.whm-shell .whm-main { padding: 0; }
.whm-side {
  position: sticky; top: 66px; max-height: calc(100vh - 84px); overflow: auto;
  background: var(--panel); border: 1px solid var(--line);
  border-radius: var(--radius); padding: 10px; box-shadow: var(--shadow);
}
.whm-search {
  width: 100%; padding: 8px 10px; font: inherit; font-size: 13.5px;
  border: 1px solid var(--line); border-radius: 6px; background: var(--panel-2);
  color: var(--text); margin-bottom: 8px;
}
.whm-search:focus { outline: 2px solid var(--accent); outline-offset: 1px; background: var(--panel); }
.whm-home {
  display: block; padding: 7px 10px; margin-bottom: 6px; border-radius: 6px;
  font-weight: 700; color: var(--navy); background: var(--panel-2);
}
.whm-home:hover { background: #e8edf1; text-decoration: none; }
.whm-group { margin-bottom: 4px; }
.whm-groupbtn {
  width: 100%; display: flex; justify-content: space-between; align-items: center;
  background: none; border: 0; cursor: pointer; font: inherit;
  font-size: 12px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase;
  color: var(--muted); padding: 8px 10px 4px;
}
.whm-groupbtn::after { content: "▾"; font-size: 10px; }
.whm-groupbtn[aria-expanded="false"]::after { content: "▸"; }
.whm-items { padding: 0 4px 4px; }
.whm-items .whm-soon {
  display: block; padding: 5px 10px; border-radius: 6px;
  font-size: 13.5px; color: var(--muted);
}
.whm-item { display: flex; align-items: center; gap: 2px; }
.whm-item > a {
  flex: 1; display: block; padding: 5px 8px 5px 10px; border-radius: 6px;
  font-size: 13.5px; color: var(--text);
}
.whm-fav {
  background: none; border: 0; cursor: pointer; font-size: 13px; line-height: 1;
  color: var(--muted); padding: 4px 6px; border-radius: 6px;
}
.whm-fav:hover { background: var(--panel-2); color: var(--accent); }
.whm-fav.on { color: var(--accent-2); }
#whm-favs .whm-item > a { font-weight: 600; }
.whm-items a:hover { background: var(--panel-2); text-decoration: none; color: var(--navy); }
.whm-items .whm-soon { color: var(--muted); }
.whm-items .whm-soon em {
  font-style: normal; font-size: 10.5px; background: var(--panel-2);
  border: 1px solid var(--line); border-radius: 99px; padding: 1px 7px; margin-left: 6px;
}
@media (max-width: 940px) {
  .whm-shell { grid-template-columns: 1fr; }
  .whm-side { position: static; max-height: none; }
}

/* P-UI-5: mobile hamburger + stacked topbar (cPanel-jaisa responsive) */
.nav-toggle {
  display: none; align-items: center; justify-content: center;
  width: 38px; height: 34px; border-radius: 8px;
  border: 1px solid #35455a; background: var(--navy-2);
  color: #eef2f6; font-size: 18px; cursor: pointer;
}
@media (max-width: 860px) {
  .topbar { flex-wrap: wrap; gap: 10px; }
  .nav-toggle { display: inline-flex; order: 2; }
  .topnav { display: none; order: 10; width: 100%; flex-direction: column; gap: 2px; }
  .topnav.open { display: flex; }
  .topnav a { padding: 10px 12px; }
  .searchbox { order: 11; width: 100%; }
  .topbar .meta { display: none; }
}

/* ============================================================================
   DESIGN-PARITY v1.0 — cPanel Jupiter-jaisa look: light header, clean SVG
   icons, left sidebar tree, emoji-free cards/tiles.
   ========================================================================== */
:root { --line: #dfe5ea; --ink: #1c2733; --ink-2: #5b6b7b; --surf: #ffffff; }

/* light topbar (Jupiter header) */
.topbar {
  background: var(--surf); color: var(--ink);
  border-bottom: 1px solid var(--line);
  box-shadow: 0 2px 0 var(--accent);
}
.topnav a { color: #33475b; }
.topnav a:hover, .topnav a.active { background: #eef2f6; color: #101828; border-color: var(--line); }
.topbar .meta { color: var(--ink-2); }
.topbar .user { color: var(--ink); }
.avatar { background: #e8edf2; border-color: #cfd8e0; color: var(--ink); }
.searchbox { background: #f1f4f7; border: 1px solid var(--line); color: var(--ink); }
.nav-toggle { background: var(--surf); border: 1px solid #cfd8e0; color: var(--ink); }
.btn.secondary { background: #fff; color: #33475b; border-color: #cfd8e0; }
.btn.secondary:hover { background: #eef2f6; }

/* shell: left sidebar + content (dono panels) */
.shell {
  display: grid; grid-template-columns: 272px minmax(0, 1fr);
  gap: 18px; max-width: 1500px; margin: 0 auto; padding: 18px 18px 0;
}
.shell .main-col { min-width: 0; }
.side { position: sticky; top: 74px; align-self: start; max-height: calc(100vh - 96px); overflow: auto; scrollbar-width: thin; }
.side-card, .whm-sidebar { 
  background: var(--surf); border: 1px solid var(--line); border-radius: 10px;
  padding: 12px 10px; font-size: 13px;
}
.side-head { font-weight: 700; font-size: 13px; padding: 4px 8px 8px; border-bottom: 1px solid var(--line); margin-bottom: 6px; }
.side-card h4, .whm-sidebar h4 {
  margin: 10px 8px 4px; font-size: 11px; letter-spacing: .6px; text-transform: uppercase;
  color: var(--ink-2); font-weight: 700;
}
.side-card a, .whm-sidebar a {
  display: block; padding: 6px 8px; border-radius: 6px; color: #33475b; text-decoration: none;
}
.side-card a:hover, .whm-sidebar a:hover { background: #eef2f6; color: #101828; }

/* card headers: Jupiter small-caps */
.card h3 {
  display: flex; align-items: center; gap: 7px;
  font-size: 12px; letter-spacing: .5px; text-transform: uppercase;
  color: var(--ink-2); font-weight: 700; margin: 0 0 10px;
}
.hico { width: 15px; height: 15px; color: var(--accent); flex: none; }

/* tiles: clean rows with stroke icon (emoji nahi) */
.tile { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; }
.tile .tico { width: 18px; height: 18px; margin-top: 2px; color: var(--accent); flex: none; }
.tile.disabled .tico, .tile.disabled .tico { color: #9aa7b4; }
.tile .name { font-weight: 600; color: var(--ink); }
.tile .sub { color: var(--ink-2); }

/* section titles */
.section-title h2 { font-size: 15px; color: var(--ink); }
.section-title .count { color: var(--ink-2); }

@media (max-width: 980px) {
  .shell { grid-template-columns: 1fr; }
  .side { position: static; max-height: none; }
}

/* design-parity hotfix: brand text light header me visible */
.brand { color: var(--ink); }
.brand small { color: var(--ink-2); }
/* svg icons kabhi bhi giant na hon: default size jab class miss ho */
svg.ico, svg.tico, svg.hico { width: 18px; height: 18px; flex: none; }
svg.hico { width: 15px; height: 15px; }
.card h3 svg { width: 15px; height: 15px; }
.tile svg { width: 18px; height: 18px; }
.side svg, .side-card svg { width: 15px; height: 15px; }

/* ============================================================================
   DESIGN-PARITY v1.1 — mobile drawer (real WHM/cPanel-jaisa) + sidebar polish
   ========================================================================== */
.nav-backdrop { display: none; }
@media (max-width: 980px) {
  .side {
    position: fixed; left: 0; top: 0; bottom: 0; width: min(300px, 84vw);
    max-height: none; transform: translateX(-102%);
    transition: transform .22s ease; z-index: 60;
    background: #f7f8f9; padding: 74px 10px 18px; overflow: auto;
    box-shadow: 0 0 0 rgba(0,0,0,0);
  }
  body.nav-open .side { transform: none; box-shadow: 0 10px 40px rgba(16,24,40,.25); }
  .nav-backdrop {
    display: block; position: fixed; inset: 0; z-index: 55;
    background: rgba(16,24,40,.45); opacity: 0; pointer-events: none;
    transition: opacity .2s ease;
  }
  body.nav-open .nav-backdrop { opacity: 1; pointer-events: auto; }
  .shell { padding-top: 12px; }
}
@media (max-width: 860px) {
  .topnav { display: none; }
  body.nav-open .topnav {
    display: flex; flex-direction: column; width: 100%; order: 10; gap: 2px;
  }
  .topnav a { padding: 10px 12px; }
}
/* sidebar: real WHM-jaisa light block, group carets, active highlight */
.side-card, .whm-sidebar { background: #f7f8f9; border: 1px solid var(--line); }
.whm-sidebar h4::after, .side-card h4::after { content: '▾'; float: right; color: #9aa7b4; font-size: 10px; }
.side-card a[aria-current="page"], .whm-sidebar a.active { background: #e8edf2; color: #101828; font-weight: 600; }
PEOF
cat > "${PANEL}/resources/views/dashboard-whm.blade.php" <<'PEOF'
@extends('layouts.panel')

@section('title', 'WHM — Server Manager Dashboard')
@section('subtitle', 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
    @can('accounts.view')
        <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
    @endcan
    @can('system.tasks')
        <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
    @endcan
@endsection

@section('content')
<div class="card">
    <h3>Quick links</h3>
    <p>
        @can('accounts.create')<a class="btn small" href="{{ route('accounts.create') }}">Create Account</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('accounts.index') }}">List Accounts</a>@endcan
        @can('packages.view')<a class="btn small secondary" href="{{ route('packages.index') }}">Packages</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('transfer-restore.index') }}">Transfer or Restore a Hosting Account</a>@endcan
    </p>
</div>
<div class="grid cols-4">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'chip', 'cls' => 'hico']) Memory</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">No data from the agent (is paneld running?)</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Disk (/)</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['disk']['used_pct'] }}%</span>
                <span class="unit">{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB</span></div>
            <div class="meter {{ $system['disk']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['disk']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'gauge', 'cls' => 'hico']) Load · CPU</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['load'][0] }}</span>
                <span class="unit">1-min · {{ $system['cpu_cores'] }} cores</span></div>
            <p class="help">{{ $system['os'] }} · kernel {{ $system['kernel'] }} · {{ $system['arch'] }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'queue', 'cls' => 'hico']) Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Services</h3>
        @if ($services)
            <div class="table-wrap">
                <table>
                    <tr><th>Service</th><th>State</th><th>Boot</th></tr>
                    @foreach ($services as $name => $state)
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td>
                                <span class="badge {{ $state['active'] === 'active' ? 'green' : ($state['active'] === 'inactive' ? 'amber' : 'red') }}">
                                    {{ $state['active'] }}
                                </span>
                            </td>
                            <td class="muted">{{ $state['enabled'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <p class="empty">Service status did not come from the agent.</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'audit', 'cls' => 'hico']) Recent activity (audit)</h3>
        @if ($audit->isEmpty())
            <p class="empty">No activity yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    @foreach ($audit as $event)
                        <tr>
                            <td class="mono">{{ $event->action }}</td>
                            <td><span class="badge {{ $event->severity === 'critical' ? 'red' : ($event->severity === 'warning' ? 'amber' : 'blue') }}">{{ $event->severity }}</span></td>
                            <td class="muted right">{{ \App\Support\Panel::ago($event->created_at) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @can('audit.view')
                <p class="help mt"><a href="{{ route('audit.index') }}">Poora audit log →</a></p>
            @endcan
        @endif
    </div>
</div>
@include('partials.dash-sections', [])

@endsection
PEOF
cat > "${PANEL}/resources/views/dashboard-cpanel.blade.php" <<'PEOF'
@extends('layouts.panel')

@section('title', 'cPanel — Account Panel')
@section('subtitle', 'Files, email, domains, databases — ye aapka hosting control panel hai')

@section('actions')
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
    <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
@endsection

@section('content')
<div class="grid cols-4">
<div class="dash-cols">
    <div>
        <div class="grid cols-4">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Primary domain</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->main_domain }}</span></div>
            <p class="help">user <span class="mono">{{ $account->username }}</span> · {{ $account->status }}</p>
        @else
            <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Disk quota</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                <span class="unit">MB used · {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Package</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->package?->name ?? '—' }}</span></div>
            <p class="help">PHP {{ $account->php_version }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Domains</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->domains->count() }}</span>
                <span class="unit">on this account</span></div>
            <p class="help"><a href="{{ route('domains.index') }}">Manage domains →</a></p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
</div>
        @include('partials.dash-sections', [])
    </div>
    <aside class="dash-side">
        <div class="card">
            <h3>General Information</h3>
            @if ($account)
                <dl class="kv">
                    <dt>User</dt><dd class="mono">{{ $account->username }}</dd>
                    <dt>Primary domain</dt><dd>{{ $account->main_domain }}</dd>
                    <dt>Home directory</dt><dd class="mono">{{ $account->home_path }}</dd>
                    <dt>PHP version</dt><dd>{{ $account->php_version }}</dd>
                    <dt>Package</dt><dd>{{ $account->package?->name ?? '—' }}</dd>
                    <dt>Status</dt><dd>{{ $account->status }}</dd>
                    <dt>Theme</dt><dd>Paper (cPanel-style)</dd>
                    <dt>Panel version</dt><dd>{{ config('acp.version') }}</dd>
                </dl>
            @else
                <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
            @endif
        </div>
        <div class="card">
            <h3>Statistics</h3>
            @if ($account)
                @php $pct = $account->quota_mb > 0 ? min(100, (int) round($account->disk_used_mb * 100 / $account->quota_mb)) : 0; @endphp
                <p class="help">Disk usage</p>
                <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                    <span class="unit">MB / {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
                <div class="meter {{ $pct > 85 ? 'amber' : 'green' }}"><span style="width: {{ max(2, $pct) }}%"></span></div>
                <dl class="kv mt">
                    <dt>Bandwidth</dt><dd>{{ $account->bw_used_mb }} MB is cycle me</dd>
                    <dt>Domains</dt><dd>{{ $account->domains->count() }} is account par</dd>
                </dl>
            @else
                <p class="empty">—</p>
            @endif
        </div>
    </aside>
</div>

@endsection
PEOF
cat > "${PANEL}/resources/views/partials/tile.blade.php" <<'PEOF'
{{-- One dashboard tile = one row of docs/09-cpanel-parity-checklist.md --}}
@php
    $live    = ($item['status'] ?? 'step') === 'live';
    $addon   = ($item['status'] ?? 'step') === 'addon';
    $href    = $live ? route($item['route']) : null;
    $classes = 'tile' . ($live ? ' live' : ' disabled');
@endphp

@if ($live)
    <a class="{{ $classes }}" href="{{ $href }}">
@else
    <div class="{{ $classes }}" title="{{ $addon ? 'Optional module' : 'Step ' . $item['step'] . ' me aayega' }}">
@endif

    @include('partials.icons', ['icon' => $item['icon'] ?? 'folder', 'cls' => 'tico'])
    <span>
        <span class="name">{{ $item['name'] }}</span>
        <span class="sub">
            @if ($live)
                Ready
            @elseif ($addon)
                Optional ({{ $item['step'] }})
            @else
                {{ $item['step'] }} me aayega
            @endif
        </span>
    </span>

@if ($live)
    </a>
@else
    </div>
@endif
PEOF
cat > "${PANEL}/resources/views/partials/dash-sections.blade.php" <<'PEOF'
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) AlphaCP feature progress</h3>
    <div class="stat">
        <span class="num">{{ $progress['live'] }}</span>
        <span class="unit">tools live · {{ $progress['planned'] }} planned · {{ $progress['addon'] }} optional · total {{ $progress['total'] }}</span>
    </div>
    <div class="meter"><span style="width: {{ max(3, $progress['percent']) }}%"></span></div>
    <p class="help">Full checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span> — 208 items,
        har item apne step me live hota jayega.
        @if ($panelMode === 'cpanel')
            Account create / packages are Server Manager (admin) only — they are hidden here.
        @endif
    </p>
</div>

@foreach ($sections as $key => $section)
    @php
        $liveCount = collect($section['items'])->where('status', 'live')->count();
    @endphp
    <div class="section-title">
        <h2>{{ $section['label'] }}</h2>
        <span class="count">{{ $liveCount }} live / {{ count($section['items']) }}</span>
    </div>

    <div class="grid tiles">
        @foreach ($section['items'] as $item)
            @include('partials.tile', ['item' => $item])
        @endforeach
    </div>
@endforeach
PEOF
cat > "${PANEL}/resources/views/partials/whm-sidebar.blade.php" <<'PEOF'
{{--
  WHM left sidebar — cPanel WHM ke navigation tree jaisa: search box sabse
  upar, collapsible category groups (ModuleCatalog ke whm-audience sections),
  har live module apni route par, baqi "soon" chip ke saath.
--}}
@php
    $whmSections = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
@endphp
<nav class="whm-side" id="whm-side" aria-label="WHM navigation">
  <input type="search" id="whm-search" class="whm-search" placeholder="Search WHM features…" autocomplete="off" aria-label="Search WHM features">
  <a class="whm-home" href="{{ route('dashboard') }}">@include('partials.icons', ['icon' => 'home', 'cls' => 'hico']) Home</a>
  <div class="whm-tree" id="whm-tree">
    <section class="whm-group" id="whm-favs" style="display:none">
      <button type="button" class="whm-groupbtn" aria-expanded="true">Favorites</button>
      <div class="whm-items"></div>
    </section>
    @foreach ($whmSections as $key => $sec)
      <section class="whm-group">
        <button type="button" class="whm-groupbtn" aria-expanded="true">{{ $sec['label'] }}</button>
        <div class="whm-items">
          @foreach ($sec['items'] as $item)
            @if (!empty($item['route']) && \Illuminate\Support\Facades\Route::has($item['route']))
              <div class="whm-item"><a href="{{ route($item['route']) }}" data-favname="{{ $item['name'] }}">{{ $item['name'] }}</a><button type="button" class="whm-fav" title="Favorites me add/remove karo" aria-label="Favorite">☆</button></div>
            @else
              <span class="whm-soon" title="ye module is roadmap slice ke baad aayega">{{ $item['name'] }} <em>soon</em></span>
            @endif
          @endforeach
        </div>
      </section>
    @endforeach
  </div>
</nav>
<script>
/* WHM sidebar: live search filter + group collapse (vanilla JS, no build step). */
(function () {
  var q = document.getElementById('whm-search');
  var tree = document.getElementById('whm-tree');
  if (!q || !tree) { return; }
  q.addEventListener('input', function () {
    var needle = q.value.trim().toLowerCase();
    tree.querySelectorAll('section.whm-group').forEach(function (sec) {
      var any = false;
      sec.querySelectorAll('a, span.whm-soon').forEach(function (el) {
        var row = el.closest('.whm-item') || el;
        var hit = needle === '' || el.textContent.toLowerCase().indexOf(needle) !== -1;
        row.style.display = hit ? '' : 'none';
        if (hit) { any = true; }
      });
      var btn = sec.querySelector('.whm-groupbtn');
      var nameHit = needle !== '' && btn.textContent.toLowerCase().indexOf(needle) !== -1;
      sec.style.display = (needle === '' || any || nameHit) ? '' : 'none';
    });
  });
  tree.addEventListener('click', function (e) {
    var btn = e.target.closest('button.whm-groupbtn');
    if (btn) {
      var items = btn.nextElementSibling;
      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      items.style.display = open ? 'none' : '';
      return;
    }
    var star = e.target.closest('button.whm-fav');
    if (star) { toggleFav(star.closest('.whm-item').querySelector('a')); }
  });

  /* ---- Favorites (localStorage; cPanel server-side store parity note: docs) ---- */
  var KEY = 'acp_whm_favs';
  function load() { try { return JSON.parse(window.localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function save(list) { window.localStorage.setItem(KEY, JSON.stringify(list)); }
  function toggleFav(a) {
    var list = load();
    var href = a.getAttribute('href');
    var name = a.getAttribute('data-favname') || a.textContent.trim();
    var at = list.findIndex(function (f) { return f.href === href; });
    if (at >= 0) { list.splice(at, 1); } else { list.push({ href: href, name: name }); }
    save(list);
    render();
  }
  function render() {
    var list = load();
    var sec = document.getElementById('whm-favs');
    var box = sec.querySelector('.whm-items');
    box.innerHTML = '';
    list.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'whm-item';
      var a = document.createElement('a');
      a.href = f.href; a.textContent = f.name; a.setAttribute('data-favname', f.name);
      var star = document.createElement('button');
      star.type = 'button'; star.className = 'whm-fav on'; star.textContent = '★';
      star.title = 'Favorites se hatao';
      row.appendChild(a); row.appendChild(star);
      box.appendChild(row);
    });
    sec.style.display = list.length ? '' : 'none';
    tree.querySelectorAll('section.whm-group:not(#whm-favs) .whm-item > a').forEach(function (a) {
      var hit = list.some(function (f) { return f.href === a.getAttribute('href'); });
      var star = a.parentElement.querySelector('.whm-fav');
      star.textContent = hit ? '★' : '☆';
      star.classList.toggle('on', hit);
    });
  }
  render();
})();
</script>
PEOF
cat > "${PANEL}/resources/views/partials/icons.blade.php" <<'PEOF'
{{-- cPanel-jaisa clean stroke-SVG icon set (emoji nahi). Usage: @include('partials.icons', ['icon'=>'mail']) --}}
@switch($icon ?? 'folder')
    @case('folder')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        @break
    @case('mail')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
        @break
    @case('globe')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M3 12h18"/><path d="M12 3c2.5 2.6 3.8 5.7 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.7-3.8-9s1.3-6.4 3.8-9z"/></svg>
        @break
    @case('database')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3z"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
        @break
    @case('shield')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6z"/></svg>
        @break
    @case('cog')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/></svg>
        @break
    @case('user')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 20c0-3.3 3.6-5 8-5s8 1.7 8 5"/></svg>
        @break
    @case('disk')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5h14v14H5z"/><path d="M9 5v5h6V5"/><path d="M8 14h8v5H8z"/></svg>
        @break
    @case('chip')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 9h6v6H9z"/><path d="M4 4h16v16H4z"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>
        @break
    @case('gauge')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M12 14l4-4"/></svg>
        @break
    @case('queue')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        @break
    @case('audit')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/></svg>
        @break
    @case('box')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l9-4 9 4v8l-9 4-9-4z"/><path d="M3 8l9 4 9-4"/><path d="M12 12v8"/></svg>
        @break
    @case('lock')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 11h12v9H6z"/><path d="M9 11V8a3 3 0 0 1 6 0v3"/></svg>
        @break
    @case('clock')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M12 7v5l3 3"/></svg>
        @break
    @case('home')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/></svg>
        @break
    @case('services')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5h6v6H5z"/><path d="M13 13h6v6h-6z"/><path d="M13 5h6v6h-6z"/><path d="M5 13h6v6H5z"/></svg>
        @break
    @case('plug')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3v5M15 3v5"/><path d="M7 8h10v4a5 5 0 0 1-10 0z"/><path d="M12 17v4"/></svg>
        @break
    @case('target')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z"/><path d="M12 11.5a.5.5 0 1 0 0 1 .5.5 0 0 0 0-1z"/></svg>
        @break
    @default
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
@endswitch
PEOF
cat > "${PANEL}/resources/views/partials/cpanel-sidebar.blade.php" <<'PEOF'
{{-- cPanel Jupiter-jaisa left navigation: grouped text links (live tools only) --}}
@php
    $cats = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
@endphp
<div class="side-card">
    <div class="side-head">{{ config('acp.brand.name', 'AlphaCP') }} — Tools</div>
    @foreach ($cats as $key => $section)
        @php
            $liveItems = collect($section['items'])->where('status', 'live');
        @endphp
        @if ($liveItems->isNotEmpty())
            <h4>{{ $section['label'] }}</h4>
            @foreach ($liveItems as $item)
                <a href="{{ route($item['route']) }}">{{ $item['name'] }}</a>
            @endforeach
        @endif
    @endforeach
</div>
PEOF
grep -q 'class="shell' "${PANEL}/resources/views/layouts/panel.blade.php" \
  || { die "layout me shell grid nahi"; }
grep -q 'cpanel-sidebar' "${PANEL}/resources/views/layouts/panel.blade.php" \
  || { die "layout me cpanel sidebar nahi"; }
grep -q 'DESIGN-PARITY v1.0' "${PANEL}/public/assets/panel.css" \
  || { die "css me design-parity block nahi"; }
grep -q 'filemtime' "${PANEL}/resources/views/layouts/panel.blade.php" \
  || { die "css cache-bust nahi"; }
grep -q '✅' "${PANEL}/resources/views/partials/tile.blade.php" \
  && { die "tile me abhi bhi emoji hai"; }
grep -q '@switch' "${PANEL}/resources/views/partials/icons.blade.php" \
  || { die "icons partial nahi"; }
ok "9 view payloads likhi + asserts pass"
hdr "alphacp-sync"
if [[ "$SIM" == "1" ]]; then info "SIM: sync skip"; else alphacp-sync >/dev/null 2>&1 && ok "sync complete" || warn "sync skip/fail"; fi
hdr "FINAL VERDICT"
ok "Jupiter-parity: light header, left sidebar (WHM+cPanel), SVG icons, emoji-free"
info "backup : ${BACKUP}"
info "rollback: sudo bash /tmp/design-parity-v1.0.sh --rollback ${BACKUP}"
printf '\n  design-parity v%s APPLY ho gaya.\n\n' "$VERSION"
