#!/usr/bin/env bash
# =============================================================================
#  ui3-fix-sim — installer/ui3-fix.sh (WHM left sidebar) ka sandbox proof.
#  PRE = 4526624 (P-UI-2 se pehle wali panel state = live jaisi: koi whm
#  sidebar nahi) → reproduce → diagnose → apply (lint + structural asserts +
#  4 files byte-for-byte era 0beb285) → diagnose post → rollback → re-apply.
#  Usage: bash tools/sim/ui3-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/ui3-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
PRE="0beb285618435c14e564e53b1d443b103a9f8265"   # ui2-era (live state: sidebar hai, favorites nahi)
ERA="8ee6f85280d9e30f60b28e415a5b065be6815bc3"   # P-UI-3 code commit

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/ui3-fix.sh nahi mila — python3 tools/build-ui3-fix.py"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php nahi mila (PHPBIN=/path)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-ui2fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
mkdir -p "$FAKE/logs" "$FAKE/releases" \
         "$FAKE/panel/public/assets" "$FAKE/panel/resources/views/partials"
FILES=(resources/views/partials/whm-sidebar.blade.php public/assets/panel.css)
for f in "${FILES[@]}"; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/.pre-${f//\//_}"
  git -C "$REPO" show "${ERA}:${PANEL_REL}/${f}" > "$FAKE/.era-${f//\//_}"
done
CSS="$FAKE/panel/public/assets/panel.css"
PART="$FAKE/panel/resources/views/partials/whm-sidebar.blade.php"
grepc(){ grep -c "$@" 2>/dev/null || true; }

echo "== reproduce: PRE state (sidebar hai, Favorites nahi) =="
t "pre partial PRESENT (ui2 era)"    test -f "$PART"
t "pre css me .whm-fav nahi"         test "$(grepc -- '.whm-fav' "$CSS")" -eq 0
t "pre partial me whm-fav nahi"      test "$(grepc 'whm-fav' "$PART")" -eq 0
t "pre partial me fav key nahi"      test "$(grepc 'acp_whm_favs' "$PART")" -eq 0

echo "== --diagnose (pre) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose pre: stars 0"        hasre "$D1" "sidebar favorites star[[:space:]]*:[[:space:]]*0"
t "diagnose pre: key 0"          hasre "$D1" "sidebar fav storage key[[:space:]]*:[[:space:]]*0"
t "diagnose pre: css fav 0"      hasre "$D1" "css fav styles[[:space:]]*:[[:space:]]*0"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|asserts|backup:|FINAL|✖' | sed 's/^/    /'
t "apply exit 0"                 test "$A1_RC" -eq 0
t "asserts pass msg"             has "$A1" "Favorites asserts pass"
t "backup bana"                  bash -c "ls -1d '$FAKE'/releases/ui3fix-* >/dev/null 2>&1"
t "stars gaye"                   test "$(grepc 'whm-fav' "$PART")" -ge 5
t "fav key gaya"                  test "$(grepc 'acp_whm_favs' "$PART")" -ge 1
t "css fav styles gaye"          test "$(grepc -- '.whm-fav' "$CSS")" -ge 3
t "search box barkarar"           test "$(grepc 'id="whm-search"' "$PART")" -ge 1
t "byte-for-byte css"            bash -c "cmp -s '$CSS' '$FAKE/.era-public_assets_panel.css'"
t "byte-for-byte partial"        bash -c "cmp -s '$PART' '$FAKE/.era-resources_views_partials_whm-sidebar.blade.php'"

echo "== --diagnose (post) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose post: stars >=5"     hasre "$D2" "sidebar favorites star[[:space:]]*:[[:space:]]*[5-9]"
t "diagnose post: key >=1"       hasre "$D2" "sidebar fav storage key[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose post: css fav >=3"   hasre "$D2" "css fav styles[[:space:]]*:[[:space:]]*[3-9]"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"              test "$R1_RC" -eq 0
t "css PRE wapas"                bash -c "cmp -s '$CSS' '$FAKE/.pre-public_assets_panel.css'"
t "partial PRE wapas"            bash -c "cmp -s '$PART' '$FAKE/.pre-resources_views_partials_whm-sidebar.blade.php'"
t "stars wapas 0"                test "$(grepc 'whm-fav' "$PART")" -eq 0

echo "== re-apply =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"              test "$A2_RC" -eq 0
t "re-apply stars wapas"         test "$(grepc 'whm-fav' "$PART")" -ge 5
t "re-apply css fav marker"      test "$(grepc -- '.whm-fav' "$CSS")" -ge 3

echo ""
echo "----------------------------------------"
echo "ui3-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
