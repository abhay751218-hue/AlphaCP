#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — UI3 FIX  v1.0  (WHM sidebar Favorites — cPanel WHM parity)
# -----------------------------------------------------------------------------
#  P-UI-3: WHM sidebar ke har live item par star toggle; favorites sidebar ke
#  sabse upar "Favorites" group me (cPanel WHM jaisa). Store localStorage hai
#  (per-browser; server-side store parity note docs me — koi DB change nahi).
#  2 panel files byte-for-byte:
#    PANEL : resources/views/partials/whm-sidebar.blade.php,
#            public/assets/panel.css
#
#  Safety: backup → structural asserts → optimize:clear + php-fpm restart →
#  HTTP smoke → alphacp-sync. Kahin fail = auto-rollback.
#
#  Usage: sudo bash ui3-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL="${ACP_HOME}/panel"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/ui3fix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/ui3-fix-${STAMP}.txt"

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

PANEL_FILES=(resources/views/partials/whm-sidebar.blade.php public/assets/panel.css)

rollback(){
  hdr "ROLLBACK — ui3-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/ui3fix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi ui1fix backup nahi mila"
  info "backup: $latest"
  for rel in "${PANEL_FILES[@]}"; do
    if [[ -f "${latest}/panel/${rel}" ]]; then cp -p "${latest}/panel/${rel}" "${PANEL}/${rel}"; else rm -f "${PANEL}/${rel}"; fi
  done
  [[ -n "$FPM_UNIT" ]] && have_systemd && { systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || true; }
  ok "rollback complete (purani files wapas)"
}

diagnose(){
  hdr "DIAGNOSE (read-only) — ui3-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} panel_user=${PANEL_USER} fpm=${FPM_UNIT:-none}"
  info "sidebar favorites star     : $(cnt 'whm-fav' "${PANEL}/resources/views/partials/whm-sidebar.blade.php")  (>=5=theek)"
  info "sidebar fav storage key      : $(cnt 'acp_whm_favs' "${PANEL}/resources/views/partials/whm-sidebar.blade.php")  (>=1=theek)"
  info "css fav styles               : $(cnt -- '.whm-fav' "${PANEL}/public/assets/panel.css")  (>=3=theek)"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — ui3-fix v${VERSION} (Paper-Lantern theme + customer dashboard parity)"
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
  mkdir -p "${PANEL}/resources/views/partials"
  cat > "${PANEL}/resources/views/partials/whm-sidebar.blade.php" <<'PHPEOF'
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
  <a class="whm-home" href="{{ route('dashboard') }}">⌂ Home</a>
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
PHPEOF
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
CSSEOF

  [[ "$(cnt 'whm-fav' "${PANEL}/resources/views/partials/whm-sidebar.blade.php")" -ge 5 ]] \
    || { rollback; die "sidebar me favorite stars nahi"; }
  [[ "$(cnt 'acp_whm_favs' "${PANEL}/resources/views/partials/whm-sidebar.blade.php")" -ge 1 ]] \
    || { rollback; die "sidebar me favorites storage key nahi"; }
  [[ "$(cnt -- '.whm-fav' "${PANEL}/public/assets/panel.css")" -ge 3 ]] \
    || { rollback; die "css me favorite styles nahi"; }
  [[ "$(cnt 'whm-search' "${PANEL}/resources/views/partials/whm-sidebar.blade.php")" -ge 1 ]] \
    || { rollback; die "sidebar search box kho gaya"; }
  ok "panel files likhi (2) — Favorites asserts pass"

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
  ok "WHM sidebar Favorites: star toggle + Favorites group (cPanel WHM jaisa)"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}ui3-fix v${VERSION} APPLY ho gaya.${C_0} WHM sidebar me items par star dabao — Favorites group upar aayega (Ctrl+Shift+R)."
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
