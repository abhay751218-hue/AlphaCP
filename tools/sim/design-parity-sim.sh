#!/usr/bin/env bash
# tools/sim/design-parity-sim.sh — design-parity v1.0 SIM harness (local + live).
# Live:  sudo FIX=/tmp/design-parity-v1.0.sh bash /tmp/design-parity-sim.sh
set -uo pipefail
REPO="${REPO:-$(cd "$(dirname "$0")/../.." && pwd)}"
FIX="${FIX:-$REPO/installer/design-parity.sh}"
FAKE="$(mktemp -d /tmp/acp-dp.XXXXXX)"
trap 'rm -rf "$FAKE"' EXIT
PASS=0; FAIL=0
t() { local n="$1"; shift; if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$n"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$n"; fi; }

V="$FAKE/home/panel/resources/views"
mkdir -p "$V/layouts" "$V/partials" "$FAKE/home/panel/public/assets"
printf 'OLD-LAYOUT\n' > "$V/layouts/panel.blade.php"
printf 'OLD-CSS\n'    > "$FAKE/home/panel/public/assets/panel.css"
for f in dashboard-whm dashboard-cpanel; do printf 'OLD-%s\n' "$f" > "$V/$f.blade.php"; done
for f in tile dash-sections whm-sidebar; do printf 'OLD-%s\n' "$f" > "$V/partials/$f.blade.php"; done

echo "== apply #1 (SIM) =="
RC=0
SIM=1 ACP_HOME="$FAKE/home" bash "$FIX" > "$FAKE/.a1" 2>&1 || RC=$?
if [[ "$RC" -ne 0 ]]; then echo "--- A1 tail ---"; tail -15 "$FAKE/.a1"; echo "---------------"; fi
t "installer exit 0"       test "$RC" -eq 0
t "layout sidenav"         grep -q 'class="sidenav"' "$V/layouts/panel.blade.php"
t "layout cpanel sidebar"  grep -q 'cpanel-sidebar' "$V/layouts/panel.blade.php"
t "layout cache-bust"      grep -q 'filemtime' "$V/layouts/panel.blade.php"
t "css jupiter block"      grep -q 'DESIGN-PARITY v1.0' "$FAKE/home/panel/public/assets/panel.css"
t "css light topbar"       grep -q 'light topbar' "$FAKE/home/panel/public/assets/panel.css"
t "icons svg set"          grep -q '@switch' "$V/partials/icons.blade.php"
t "tile emoji-free"        bash -c "! grep -q '✅' '$V/partials/tile.blade.php'"
t "tile svg icon"          grep -q 'partials.icons' "$V/partials/tile.blade.php"
t "whm dashboard emoji-free" bash -c "! grep -q '🧠\\|💾\\|⚙' '$V/dashboard-whm.blade.php'"
t "cpanel sidebar partial" grep -q 'ModuleCatalog::sectionsFor' "$V/partials/cpanel-sidebar.blade.php"
t "icons blade double-brace" grep -qF 'class="{{ $cls' "$V/partials/icons.blade.php"
t "brand light-visible"        grep -q '.brand { color: var(--ink); }' "$FAKE/home/panel/public/assets/panel.css"
t "mobile drawer css"        grep -q 'body.nav-open .side' "$FAKE/home/panel/public/assets/panel.css"
t "drawer js body toggle"    grep -q "classList.toggle('nav-open')" "$V/layouts/panel.blade.php"
t "backdrop element"         grep -q 'nav-backdrop' "$V/layouts/panel.blade.php"
t "jupiter sidenav layout"   grep -q 'class="sidenav"' "$V/layouts/panel.blade.php"
t "jupiter mainbar"           grep -q 'class="mainbar"' "$V/layouts/panel.blade.php"
t "jupiter css vars"          grep -q 'jup-navy' "$FAKE/home/panel/public/assets/panel.css"
t "tile icon chip"            grep -q 'tchip' "$V/partials/tile.blade.php"
t "whm login wordmark"      grep -q 'wm-mark' "$V/layouts/guest.blade.php"
t "whm login blue btn"      grep -q '29a9e0' "$FAKE/home/panel/public/assets/panel.css"
t "mobile dark mainbar"     grep -q 'mainbar .crumb { display: none' "$FAKE/home/panel/public/assets/panel.css"
t "drawer click fix"         grep -q '.sidenav, .mainbar, #acp-nav-toggle' "$V/layouts/panel.blade.php"
t "brand-first wordmark"     grep -q "wordmark', config('acp.brand.name" "$V/layouts/guest.blade.php"
t "sect cards collapsible"   grep -q 'sect-card' "$V/partials/dash-sections.blade.php"
t "icon keyword fallback"    grep -q "case('chart')" "$V/partials/icons.blade.php"

B="$(ls -dt "$FAKE/home"/releases/design-parity-* | head -1)"
sleep 1

echo "== apply #2 (idempotent) =="
RC2=0
SIM=1 ACP_HOME="$FAKE/home" bash "$FIX" > "$FAKE/.a2" 2>&1 || RC2=$?
t "re-apply exit 0"        test "$RC2" -eq 0

sleep 1
echo "== rollback =="
RC3=0
bash "$FIX" --rollback "$B" > "$FAKE/.rb" 2>&1 || RC3=$?
t "rollback exit 0"        test "$RC3" -eq 0
t "layout original wapas"  grep -q 'OLD-LAYOUT' "$V/layouts/panel.blade.php"

printf '\n----------------------------------------\ndesign-parity-sim: passed=%d failed=%d\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
