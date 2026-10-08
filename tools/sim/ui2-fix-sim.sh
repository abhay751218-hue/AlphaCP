#!/usr/bin/env bash
# =============================================================================
#  ui2-fix-sim — installer/ui2-fix.sh (WHM left sidebar) ka sandbox proof.
#  PRE = 4526624 (P-UI-2 se pehle wali panel state = live jaisi: koi whm
#  sidebar nahi) → reproduce → diagnose → apply (lint + structural asserts +
#  4 files byte-for-byte era 0beb285) → diagnose post → rollback → re-apply.
#  Usage: bash tools/sim/ui2-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/ui2-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
PRE="45266244f7029e3561e0709437df7f12ae6ac43c"   # pre-P-UI-2 panel state
ERA="0beb285618435c14e564e53b1d443b103a9f8265"   # P-UI-2 code commit

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/ui2-fix.sh nahi mila — python3 tools/build-ui2-fix.py"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php nahi mila (PHPBIN=/path)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-ui2fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
mkdir -p "$FAKE/logs" "$FAKE/releases" \
         "$FAKE/panel/public/assets" "$FAKE/panel/resources/views/layouts" \
         "$FAKE/panel/resources/views/partials" "$FAKE/panel/app/Support"
FILES=(app/Support/ModuleCatalog.php resources/views/layouts/panel.blade.php public/assets/panel.css)
for f in "${FILES[@]}"; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/.pre-${f//\//_}"
  git -C "$REPO" show "${ERA}:${PANEL_REL}/${f}" > "$FAKE/.era-${f//\//_}"
done
git -C "$REPO" show "${ERA}:${PANEL_REL}/resources/views/partials/whm-sidebar.blade.php" > "$FAKE/.era-part"
CSS="$FAKE/panel/public/assets/panel.css"
LAY="$FAKE/panel/resources/views/layouts/panel.blade.php"
CAT="$FAKE/panel/app/Support/ModuleCatalog.php"
PART="$FAKE/panel/resources/views/partials/whm-sidebar.blade.php"
grepc(){ grep -c "$@" 2>/dev/null || true; }

echo "== reproduce: PRE state (koi WHM sidebar nahi) =="
t "pre css me .whm-side nahi"        test "$(grepc -- '.whm-side' "$CSS")" -eq 0
t "pre layout me whm-shell nahi"     test "$(grepc 'whm-shell' "$LAY")" -eq 0
t "pre sidebar partial MISSING"      test ! -f "$PART"
t "pre catalog me whm-dns nahi"      test "$(grepc "'whm-dns'" "$CAT")" -eq 0

echo "== --diagnose (pre) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose pre: css 0"          hasre "$D1" "css whm sidebar[[:space:]]*:[[:space:]]*0"
t "diagnose pre: partial MISSING" has "$D1" "MISSING"
t "diagnose pre: catalog 0"      hasre "$D1" "catalog WHM groups[[:space:]]*:[[:space:]]*0"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|asserts|backup:|FINAL|✖' | sed 's/^/    /'
t "apply exit 0"                 test "$A1_RC" -eq 0
t "lint clean msg"               has "$A1" "ModuleCatalog lint clean"
t "asserts pass msg"             has "$A1" "WHM sidebar asserts pass"
t "backup bana"                  bash -c "ls -1d '$FAKE'/releases/ui2fix-* >/dev/null 2>&1"
t "css .whm-side gaya"           test "$(grepc -- '.whm-side' "$CSS")" -ge 1
t "layout whm-shell gaya"        test "$(grepc 'whm-shell' "$LAY")" -ge 1
t "partial likhi gayi"           test -f "$PART"
t "sidebar search box"           test "$(grepc 'id="whm-search"' "$PART")" -ge 1
t "catalog 8 groups"             test "$(grepc "'whm-" "$CAT")" -ge 8
t "byte-for-byte catalog"        bash -c "cmp -s '$CAT' '$FAKE/.era-app_Support_ModuleCatalog.php'"
t "byte-for-byte layout"         bash -c "cmp -s '$LAY' '$FAKE/.era-resources_views_layouts_panel.blade.php'"
t "byte-for-byte css"            bash -c "cmp -s '$CSS' '$FAKE/.era-public_assets_panel.css'"
t "byte-for-byte partial"        bash -c "cmp -s '$PART' '$FAKE/.era-part'"
t "catalog lint (php -l)"        "$PHPBIN" -l "$CAT"

echo "== --diagnose (post) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose post: css >=1"       hasre "$D2" "css whm sidebar[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose post: PRESENT"       has "$D2" "PRESENT"
t "diagnose post: catalog 1"     hasre "$D2" "catalog WHM groups[[:space:]]*:[[:space:]]*1"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"              test "$R1_RC" -eq 0
t "css PRE wapas"                bash -c "cmp -s '$CSS' '$FAKE/.pre-public_assets_panel.css'"
t "layout PRE wapas"             bash -c "cmp -s '$LAY' '$FAKE/.pre-resources_views_layouts_panel.blade.php'"
t "catalog PRE wapas"            bash -c "cmp -s '$CAT' '$FAKE/.pre-app_Support_ModuleCatalog.php'"
t "partial delete hui"           test ! -f "$PART"

echo "== re-apply =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"              test "$A2_RC" -eq 0
t "re-apply partial wapas"       test -f "$PART"
t "re-apply css marker"          test "$(grepc -- '.whm-side' "$CSS")" -ge 1

echo ""
echo "----------------------------------------"
echo "ui2-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
