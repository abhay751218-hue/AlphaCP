#!/usr/bin/env bash
# ============================================================================
# AlphaCP finalize v1.0 — P-UI-5 (responsive/mobile + white-label hooks)
#                          + Roundcube (webmail) branding
#
#  1  panel payloads: layouts/panel.blade.php (hamburger + brand hooks),
#     public/assets/panel.css (mobile stacked topbar), config/acp.php (brand)
#  2  Roundcube: skins/elastic/images/acp-logo.svg + /etc/roundcube/config.inc.php
#     me ACP_BRAND block (product_name + skin_logo), idempotent
#  3  structural asserts; koi fail = ROLLBACK + non-zero exit
#  SIM=1 + env paths → tools/sim/finalize-sim.sh (server mutate NAHI)
# ============================================================================
set -euo pipefail

VERSION="1.0"
SIM="${SIM:-0}"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
RC_ETC="${ACP_RC_ETC:-/etc/roundcube}"
RC_SHARE="${ACP_RC_SHARE:-/usr/share/roundcube}"
PANEL="${ACP_HOME}/panel"

LOGD="${ACP_HOME}/logs"
mkdir -p "$LOGD"
LOG="${LOGD}/finalize-$(date +%Y%m%d%H%M%S).txt"
exec > >(tee -a "$LOG") 2>&1

C_G=$'\033[0;32m'; C_R=$'\033[0;31m'; C_Y=$'\033[0;33m'; C_0=$'\033[0m'
ok()   { printf '  %s✔%s %s\n' "$C_G" "$C_0" "$*"; }
warn() { printf '  %s!%s %s\n' "$C_Y" "$C_0" "$*"; }
die()  { printf '  %s✖%s %s\n' "$C_R" "$C_0" "$*"; exit 1; }
hdr()  { printf '\n== %s ==\n' "$*"; }
info() { printf '  · %s\n' "$*"; }

TS="$(date +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/finalize-${TS}"
RB_MANIFEST=()
backup() {
  for f in "$@"; do
    [[ -f "$f" ]] || continue
    mkdir -p "${BACKUP}/$(dirname "$f")"
    cp -a "$f" "${BACKUP}/$f" 2>/dev/null || cp -a "$f" "${BACKUP}/$(echo "$f" | tr / _)"
    RB_MANIFEST+=("$f")
  done
}
rollback() {
  hdr "ROLLBACK — finalize v${VERSION}"
  local B="${1:-$BACKUP}" f key
  for f in "${RB_MANIFEST[@]:-}"; do
    [[ -z "$f" ]] && continue
    key="$f"
    if [[ -f "${B}${key}" ]]; then cp -a "${B}${key}" "$f"; else cp -a "${B}/$(echo "$key" | tr / _)" "$f" 2>/dev/null || true; fi
  done
  ok "rollback complete (purani state wapas)"
}

if [[ "${1:-}" == "--rollback" ]]; then
  B="${2:?usage: --rollback <backup-dir>}"
  hdr "ROLLBACK — finalize v${VERSION}"
  ( cd "$B" && find . -type f | sed 's|^\./||' | while read -r rel; do
      mkdir -p "/$(dirname "$rel")" && cp -a "$B/$rel" "/$rel"
    done )
  ok "rollback complete (purani state wapas)"
  exit 0
fi

printf '\n== FINALIZE v%s (P-UI-5 responsive + white-label + Roundcube branding) ==\n' "$VERSION"
info "backup: ${BACKUP}"
mkdir -p "$BACKUP"

# ---- 1) panel payloads ----
hdr "panel payloads (blade hamburger + brand hooks + mobile css)"
backup "${PANEL}/resources/views/layouts/panel.blade.php" \
       "${PANEL}/public/assets/panel.css" \
       "${PANEL}/config/acp.php"
mkdir -p "${PANEL}/resources/views/layouts" "${PANEL}/public/assets" "${PANEL}/config"

cat > "${PANEL}/resources/views/layouts/panel.blade.php" <<'PEOF'
{{-- REBRAND_DONE --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ config('acp.brand.name', 'AlphaCP') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
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
<div class="whm-shell">
    @include('partials.whm-sidebar', [])
    <main class="wrap whm-main">
@else
<main class="wrap">
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
@if (($panelMode ?? 'cpanel') === 'whm')
</div>
@endif

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
/* P-UI-5: mobile hamburger — topnav ko stack karta hai chhoti screens par. */
(function () {
    var b = document.getElementById('acp-nav-toggle');
    var n = document.getElementById('acp-topnav');
    if (!b || !n) { return; }
    b.addEventListener('click', function () {
        var open = n.classList.toggle('open');
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
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
PEOF
cat > "${PANEL}/config/acp.php" <<'PEOF'
<?php

declare(strict_types=1);

/**
 * AlphaCP panel configuration.
 *
 * Values come from the app .env (written by installer/step2b-install.sh).
 * Never hardcode paths or credentials in panel code — read them from here.
 */
return [
    // Panel + agent versions (shown in the UI footer and system page)
    'version'       => env('ACP_VERSION', '0.72.0'),
    'agent_version' => env('ACP_AGENT_VERSION', '0.62.0'),

    // AlphaCP install root (agent, etc/, logs/, panel/)
    'home'          => rtrim((string) env('ACP_HOME', '/usr/local/alphacp'), '/'),

    // Webmail (Roundcube) entry — cPanel-style alag port (default 2096).
    // OWNER-CTRL slice me ye superadmin-controlled ho jayega.
    'webmail_port'  => (int) env('ACP_WEBMAIL_PORT', 2096),

    // OWNER-CTRL port↔panel map file (panel + agent + installer shared truth).
    'ports_file'    => (string) env('ACP_PORTS_FILE', '/usr/local/alphacp/etc/ports.json'),
    // P-UI-5 white-label hooks: license-grade branding env se override ho sakta hai.
    'brand'         => [
        'name'    => (string) env('ACP_BRAND_NAME', 'AlphaCP'),
        'tagline' => (string) env('ACP_BRAND_TAGLINE', 'Hosting control panel'),
    ],

    // This server's row in `servers`.
    //
    // Source of truth = etc/panel.env (written by the installer, readable by
    // the panel user). Dev machines fall back to etc/database.env / app .env.
    // This guarantees panel and agent always agree on the server id.
    'server_id'     => (int) App\Support\PanelEnv::get('ACP_SERVER_ID', (string) env('ACP_SERVER_ID', '1')),

    // Where customer data lives (used from Step 3 onward)
    'paths' => [
        'accounts' => env('ACP_ACCOUNTS_PATH', '/home'),
        'www'      => env('ACP_WWW_PATH', '/var/www'),
    ],

    // Seconds the panel waits for paneld after enqueueing account tasks.
    // Tests force 0 (see AccountProvisioner).
    'provision_wait' => (int) env('ACP_PROVISION_WAIT', 25),

    // First admin account (used once by AdminUserSeeder during install).
    // Kept here — not read with env() in the seeder — because env() is
    // unavailable when the config cache is warm on a production server.
    'admin' => [
        'user'         => env('ACP_ADMIN_USER', 'admin'),
        'password'     => env('ACP_ADMIN_PASSWORD'),
        'email'        => env('ACP_ADMIN_EMAIL'),
        'force_change' => (bool) env('ACP_ADMIN_FORCE_CHANGE', true),
    ],

    // License client (S2C): offline-first signed payload + local trial.
    // An empty API URL is safe: the panel starts a local trial and never blocks
    // customer websites/email because a license server is unavailable.
    'license' => [
        'api_url' => (string) env('ACP_LICENSE_API_URL', ''),
        'timeout' => (int) env('ACP_LICENSE_TIMEOUT', 8),
        'store_path' => (string) env('ACP_LICENSE_STORE_PATH', storage_path('app/private/license.json')),
        'public_key' => (string) env('ACP_LICENSE_PUBLIC_KEY', ''),
        'public_key_path' => (string) env('ACP_LICENSE_PUBLIC_KEY_PATH', config_path('license_public.pem')),
    ],

    // S10 scheduled backups (routes/console.php → alphacp:scheduled-backups).
    //
    // `file` is the window marker: it records which day / ISO week / month was
    // already backed up, so the hourly tick runs the real archive pass exactly
    // once per window. Tests point it at a temp file.
    'backup_schedule' => [
        'file' => (string) env('ACP_BACKUP_SCHEDULE_FILE', storage_path('app/private/backup-schedule.json')),
    ],

    // Password quality
    //
    // check_pwned queries haveibeenpwned.com (k-anonymity: only 5 hash chars
    // leave the server). On a host without outbound access that call is a
    // 30-second stall, so the timeout is short and the check can be turned off
    // with ACP_CHECK_PWNED=false — the rest of the policy still applies.
    'password_check_pwned'  => (bool) env('ACP_CHECK_PWNED', true),
    'password_pwned_timeout' => (int) env('ACP_PWNED_TIMEOUT', 3),

    // Login protection (cPHulk-lite — full cPHulk arrives in Step 13)
    'security' => [
        'max_login_attempts'   => (int) env('ACP_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'      => (int) env('ACP_LOCKOUT_MINUTES', 15),
        'throttle_per_minute'  => (int) env('ACP_LOGIN_THROTTLE', 10),
        'session_lifetime'     => (int) env('ACP_SESSION_LIFETIME', 30),
        'password_min_length'  => (int) env('ACP_PASSWORD_MIN_LENGTH', 10),

        // Clickjacking protection: DENY (production default) | SAMEORIGIN | OFF.
        // OFF exists only for --insecure-http dev boxes that are viewed inside a
        // preview iframe; a default install never sets it.
        'frame_options'        => env('ACP_FRAME_OPTIONS', 'DENY'),
    ],
];
PEOF
PHP_BIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
if [[ -n "$PHP_BIN" ]]; then
  "$PHP_BIN" -l "${PANEL}/config/acp.php" >/dev/null || { rollback; die "acp.php lint fail"; }
fi
grep -q 'acp-nav-toggle' "${PANEL}/resources/views/layouts/panel.blade.php" \
  || { rollback; die "blade me hamburger nahi"; }
grep -q "config('acp.brand.name'" "${PANEL}/resources/views/layouts/panel.blade.php" \
  || { rollback; die "blade me white-label brand hook nahi"; }
grep -q 'max-width: 860px' "${PANEL}/public/assets/panel.css" \
  || { rollback; die "css me mobile media query nahi"; }
grep -q "'brand'" "${PANEL}/config/acp.php" \
  || { rollback; die "config me brand hook nahi"; }
ok "3 panel payloads likhi + asserts pass"

# ---- 2) Roundcube branding ----
hdr "Roundcube branding (logo + product_name)"
backup "${RC_ETC}/config.inc.php"
mkdir -p "${RC_SHARE}/skins/elastic/images"
cat > "${RC_SHARE}/skins/elastic/images/acp-logo.svg" <<'PEOF'
<svg xmlns="http://www.w3.org/2000/svg" width="150" height="40" viewBox="0 0 150 40"><rect width="40" height="40" rx="9" fill="#FF6C2C"/><text x="20" y="27" font-family="Arial,Helvetica,sans-serif" font-size="22" font-weight="700" fill="#ffffff" text-anchor="middle">A</text><text x="48" y="26" font-family="Arial,Helvetica,sans-serif" font-size="17" font-weight="600" fill="#ffffff">AlphaCP</text></svg>
PEOF
[[ -f "${RC_ETC}/config.inc.php" ]] || printf '<?php\n/* AlphaCP managed Roundcube config */\n' > "${RC_ETC}/config.inc.php"
python3 - "$RC_ETC/config.inc.php" <<'PY'
import sys, pathlib
p = pathlib.Path(sys.argv[1]); s = p.read_text()
block = """// ACP_BRAND_START
$config['product_name'] = 'AlphaCP Webmail';
$config['skin_logo'] = 'skins/elastic/images/acp-logo.svg';
// ACP_BRAND_END"""
if '// ACP_BRAND_START' in s:
    a = s.index('// ACP_BRAND_START'); b = s.index('// ACP_BRAND_END') + len('// ACP_BRAND_END')
    s = s[:a] + block + s[b:]
else:
    s = s.rstrip() + "\n" + block + "\n"
p.write_text(s)
PY
grep -q "product_name'] = 'AlphaCP Webmail'" "${RC_ETC}/config.inc.php" \
  || { rollback; die "rc config me product_name nahi"; }
grep -q 'acp-logo.svg' "${RC_ETC}/config.inc.php" \
  || { rollback; die "rc config me skin_logo nahi"; }
[[ -s "${RC_SHARE}/skins/elastic/images/acp-logo.svg" ]] \
  || { rollback; die "logo svg nahi likha"; }
if [[ -n "$PHP_BIN" ]]; then
  "$PHP_BIN" -l "${RC_ETC}/config.inc.php" >/dev/null || { rollback; die "rc config lint fail"; }
fi
ok "Roundcube branding set (product_name + logo)"

# ---- 3) sync ----
hdr "alphacp-sync"
if [[ "$SIM" == "1" ]]; then
  info "SIM: sync skip"
else
  alphacp-sync >/dev/null 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync skip/fail"
fi

hdr "FINAL VERDICT"
ok "Mobile: hamburger + stacked topbar (<=860px); white-label: ACP_BRAND_NAME/TAGLINE env"
ok "Webmail: 'AlphaCP Webmail' product_name + AlphaCP logo (Roundcube elastic skin)"
info "backup : ${BACKUP}"
info "rollback: sudo bash /tmp/finalize-v1.0.sh --rollback"
printf '\n  finalize v%s APPLY ho gaya.\n\n' "$VERSION"
