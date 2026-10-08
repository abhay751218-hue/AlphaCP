#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — UI1 FIX  v1.0  (cPanel Paper-Lantern style theme + customer dash)
# -----------------------------------------------------------------------------
#  P-UI-1: panel ka visual experience cPanel Paper-Lantern jaisa (paper-white
#  cards, navy top bar, orange accent — AlphaCP branding ke saath), customer
#  dashboard par cPanel-jaisa layout: tool-grid + right sidebar (General
#  Information / Statistics) + top search jo tiles filter karti hai.
#  Ye script 4 panel files byte-for-byte deploy karti hai (koi agent change
#  nahi, koi DB change nahi):
#    PANEL : public/assets/panel.css,
#            resources/views/layouts/panel.blade.php,
#            resources/views/dashboard.blade.php,
#            resources/views/partials/dash-sections.blade.php
#
#  Safety: backup → 4 structural asserts (theme token / search box / dash-cols /
#  sections partial) → optimize:clear + php-fpm restart (opcache) → HTTP smoke →
#  alphacp-sync. Kahin fail = auto-rollback.
#
#  Usage: sudo bash ui1-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL="${ACP_HOME}/panel"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/ui1fix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/ui1-fix-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }
cnt(){ grep -c "$@" 2>/dev/null || true; }

unset PHP 2>/dev/null || true

detect_php(){
  local p
  if [[ -f /etc/systemd/system/paneld.service ]]; then
    p="$(sed -nE 's#^ExecStart=([^ ]+).*#\1#p' /etc/systemd/system/paneld.service 2>/dev/null | head -1)"
    [[ -n "$p" && -x "$p" ]] && { printf '%s' "$p"; return; }
  fi
  for c in php8.4 php8.3 php8.2 php; do command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }; done
  printf ''
}
PHP_BIN="$(detect_php)"

detect_panel_user(){
  local u
  u="$(grep -hoE '^[[:space:]]*user[[:space:]]*=[[:space:]]*[a-z_][a-z0-9_-]*' /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 | awk -F'=' '{gsub(/[ \t]/,"",$2); print $2}')"
  [[ -n "$u" ]] && { printf '%s' "$u"; return; }
  printf 'alphacp'
}
PANEL_USER="$(detect_panel_user)"

detect_fpm_unit(){
  local v u
  v="$([[ -n "$PHP_BIN" ]] && "$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  for u in "php${v}-fpm" "php${v%%.*}-fpm" php-fpm; do
    [[ -n "$u" ]] || continue
    if have_systemd && { [[ -f "/etc/systemd/system/${u}.service" ]] || [[ -f "/lib/systemd/system/${u}.service" ]]; }; then
      printf '%s' "$u"; return
    fi
  done
  printf ''
}
FPM_UNIT="$(detect_fpm_unit)"

PANEL_FILES=(public/assets/panel.css resources/views/layouts/panel.blade.php resources/views/dashboard.blade.php resources/views/partials/dash-sections.blade.php)

rollback(){
  hdr "ROLLBACK — ui1-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/ui1fix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi ui1fix backup nahi mila"
  info "backup: $latest"
  for rel in "${PANEL_FILES[@]}"; do
    if [[ -f "${latest}/panel/${rel}" ]]; then cp -p "${latest}/panel/${rel}" "${PANEL}/${rel}"; else rm -f "${PANEL}/${rel}"; fi
  done
  [[ -n "$FPM_UNIT" ]] && have_systemd && { systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || true; }
  ok "rollback complete (purani files wapas)"
}

diagnose(){
  hdr "DIAGNOSE (read-only) — ui1-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} panel_user=${PANEL_USER} fpm=${FPM_UNIT:-none}"
  info "css paper theme            : $(cnt -- '--navy: #1c2733' "${PANEL}/public/assets/panel.css")  (1=theek)"
  info "layout search box          : $(cnt 'id="acp-search"' "${PANEL}/resources/views/layouts/panel.blade.php")  (1=theek)"
  info "dashboard dash-cols        : $(cnt 'dash-cols' "${PANEL}/resources/views/dashboard.blade.php")  (>=1=theek)"
  info "sections partial PRESENT   : $( [[ -f "${PANEL}/resources/views/partials/dash-sections.blade.php" ]] && echo PRESENT || echo MISSING )"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — ui1-fix v${VERSION} (Paper-Lantern theme + customer dashboard parity)"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  [[ -d "$PANEL" ]] || die "panel dir nahi: ${PANEL}"

  # ---- backup ----
  mkdir -p "${BACKUP}/panel"
  local rel
  for rel in "${PANEL_FILES[@]}"; do
    mkdir -p "${BACKUP}/panel/$(dirname "$rel")"
    [[ -f "${PANEL}/${rel}" ]] && cp -p "${PANEL}/${rel}" "${BACKUP}/panel/${rel}" || true
  done
  ok "backup: ${BACKUP}"

  # ---- panel files ----
  hdr "panel files likhna"
  cat > "${PANEL}/public/assets/panel.css" <<'CSSEOF'
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
CSSEOF
  cat > "${PANEL}/resources/views/layouts/panel.blade.php" <<'PHPEOF'
{{-- REBRAND_DONE --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · AlphaCP</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="logo">A</span>
        <span>
            AlphaCP {{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : '' }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : 'Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>

    <nav class="topnav" aria-label="Main">
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

<main class="wrap">
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

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    AlphaCP {{ config('acp.version') }} — AlphaCP control panel ·
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
</body>
</html>
PHPEOF
  cat > "${PANEL}/resources/views/dashboard.blade.php" <<'PHPEOF'
@extends('layouts.panel')

@section('title', $panelMode === 'whm' ? 'Server Manager Dashboard' : 'Account Panel')
@section('subtitle', $panelMode === 'whm'
    ? 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel'
    : 'Files, email, domains, databases — ye aapka hosting control panel hai')

@section('actions')
    @if ($panelMode === 'whm')
        <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
        @can('accounts.view')
            <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
        @endcan
        @can('system.tasks')
            <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
        @endcan
    @else
        @can('domains.view')
            <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
        @endcan
        <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
    @endif
@endsection

@section('content')

@if ($panelMode === 'whm')
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
        <h3>🧠 Memory</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">No data from the agent (is paneld running?)</p>
        @endif
    </div>

    <div class="card">
        <h3>💾 Disk (/)</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['disk']['used_pct'] }}%</span>
                <span class="unit">{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB</span></div>
            <div class="meter {{ $system['disk']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['disk']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>⚙️ Load · CPU</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['load'][0] }}</span>
                <span class="unit">1-min · {{ $system['cpu_cores'] }} cores</span></div>
            <p class="help">{{ $system['os'] }} · kernel {{ $system['kernel'] }} · {{ $system['arch'] }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>📋 Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>🧩 Services</h3>
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
        <h3>📝 Recent activity (audit)</h3>
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
@else
<div class="grid cols-4">
<div class="dash-cols">
    <div>
        <div class="grid cols-4">
    <div class="card">
        <h3>🌐 Primary domain</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->main_domain }}</span></div>
            <p class="help">user <span class="mono">{{ $account->username }}</span> · {{ $account->status }}</p>
        @else
            <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
        @endif
    </div>
    <div class="card">
        <h3>💾 Disk quota</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                <span class="unit">MB used · {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>📦 Package</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->package?->name ?? '—' }}</span></div>
            <p class="help">PHP {{ $account->php_version }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>🌍 Domains</h3>
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
@endif

@endsection
PHPEOF
  mkdir -p "${PANEL}/resources/views/partials"
  cat > "${PANEL}/resources/views/partials/dash-sections.blade.php" <<'PHPEOF'
<div class="card mt">
    <h3>🎯 AlphaCP feature progress</h3>
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
PHPEOF

  [[ "$(cnt -- '--navy: #1c2733' "${PANEL}/public/assets/panel.css")" -ge 1 ]] \
    || { rollback; die "css me paper-theme navy token nahi"; }
  [[ "$(cnt 'id="acp-search"' "${PANEL}/resources/views/layouts/panel.blade.php")" -ge 1 ]] \
    || { rollback; die "layout me search box nahi"; }
  [[ "$(cnt 'dash-cols' "${PANEL}/resources/views/dashboard.blade.php")" -ge 1 ]] \
    || { rollback; die "dashboard me dash-cols layout nahi"; }
  [[ "$(cnt 'General Information' "${PANEL}/resources/views/dashboard.blade.php")" -ge 1 ]] \
    || { rollback; die "dashboard me General Information sidebar nahi"; }
  [[ -f "${PANEL}/resources/views/partials/dash-sections.blade.php" ]] \
    || { rollback; die "sections partial nahi likhi gayi"; }
  ok "panel files likhi (4) — theme + layout asserts pass"

  # ---- caches + fpm restart (opcache) ----
  hdr "panel cache + php-fpm restart"
  if command -v runuser >/dev/null 2>&1 && [[ -f "${PANEL}/artisan" ]]; then
    runuser -u "$PANEL_USER" -- env ACP_HOME="$ACP_HOME" "$PHP_BIN" "${PANEL}/artisan" optimize:clear >>"$LOG_FILE" 2>&1 \
      && ok "artisan optimize:clear" || warn "optimize:clear fail (ignore — fpm restart opcache clear karega)"
  else
    warn "runuser/artisan nahi — optimize:clear skip"
  fi
  if have_systemd && [[ -n "$FPM_UNIT" ]]; then
    systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || warn "fpm restart fail"
    sleep 1
    systemctl is-active --quiet "$FPM_UNIT" || { rollback; die "php-fpm active nahi restart ke baad"; }
    ok "${FPM_UNIT} active (opcache clear)"
  else
    warn "php-fpm unit nahi mila — restart skip (manual: systemctl restart php8.4-fpm)"
  fi

  # ---- HTTP smoke ----
  hdr "HTTP smoke"
  local code
  code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:8090/login" 2>/dev/null || echo 000)"
  if [[ "$code" == "200" || "$code" == "302" ]]; then ok "panel /login HTTP ${code}"; else warn "panel /login HTTP ${code} — browser me check karo"; fi

  # ---- sync ----
  hdr "alphacp-sync"
  if command -v alphacp-sync >/dev/null 2>&1; then
    alphacp-sync >>"$LOG_FILE" 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync fail (baad me: sudo alphacp-sync)"
  else
    warn "alphacp-sync nahi mila"
  fi

  hdr "FINAL VERDICT"
  ok "Panel ab cPanel Paper-Lantern style: paper-white cards, navy top bar, orange accent"
  ok "Customer dashboard: tool-grid + General Information/Statistics sidebar + top search"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}ui1-fix v${VERSION} APPLY ho gaya.${C_0} Browser me hard-refresh (Ctrl+Shift+R) karo — naya theme + dashboard layout."
}

usage(){ sed -nE 's/^#( |=)(.*)$/\2/p' "$0" | sed -n '1,30p'; }
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
case "${1:-apply}" in
  --diagnose|-d) diagnose ;;
  --rollback|-r) rollback ;;
  --help|-h) usage ;;
  apply|"") apply ;;
  *) die "unknown option: $1 (--diagnose | --rollback | --help)" ;;
esac
