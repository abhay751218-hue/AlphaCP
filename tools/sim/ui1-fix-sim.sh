#!/usr/bin/env bash
# =============================================================================
#  ui1-fix-sim — installer/ui1-fix.sh (Paper-Lantern theme + customer dash) ka
#  sandbox proof. Fake panel ko PRE state (commit e2e2d6a = purana dark theme,
#  koi search/sidebar nahi) par lata hai, phir:
#    1  reproduce: token 0 / search 0 / dash-cols 0 / partial MISSING
#    2  --diagnose read-only
#    3  apply: backup → structural asserts → 4 files byte-for-byte (era 24baf55)
#    4  --diagnose post
#    5  --rollback (purana theme wapas)
#    6  re-apply (idempotent)
#  Usage: bash tools/sim/ui1-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/ui1-fix.sh"

PRE="e2e2d6a"   # P-UI-1 se pehle wali panel state (live jaisi)
ERA="24baf55"   # P-UI-1 code commit = payloads ka pin

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }

[[ -f "$FIX" ]] || { echo "installer/ui1-fix.sh nahi mila — python3 tools/build-ui1-fix.py chalao"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-ui1fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" \
         "$FAKE/panel/public/assets" "$FAKE/panel/resources/views/layouts" \
         "$FAKE/panel/resources/views/partials"

PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
for f in public/assets/panel.css resources/views/layouts/panel.blade.php resources/views/dashboard.blade.php; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
done

CSS="$FAKE/panel/public/assets/panel.css"
LAY="$FAKE/panel/resources/views/layouts/panel.blade.php"
DASH="$FAKE/panel/resources/views/dashboard.blade.php"
PART="$FAKE/panel/resources/views/partials/dash-sections.blade.php"
grepc(){ grep -c "$@" 2>/dev/null || true; }

echo "== reproduce: PRE state (dark theme, koi search/sidebar nahi) =="
t "pre-fix css me navy token nahi"      test "$(grepc -- '--navy: #1c2733' "$CSS")" -eq 0
t "pre-fix layout me search box nahi"   test "$(grepc 'id="acp-search"' "$LAY")" -eq 0
t "pre-fix dashboard me dash-cols nahi" test "$(grepc 'dash-cols' "$DASH")" -eq 0
t "pre-fix sections partial MISSING"    test ! -f "$PART"

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose css token 0"        hasre "$D1" "css paper theme[[:space:]]*:[[:space:]]*0"
t "diagnose partial MISSING"    has "$D1" "MISSING"
t "diagnose ne partial nahi likhi" test ! -f "$PART"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'asserts|backup:|FINAL|✖|' | sed 's/^/    /'
t "apply exit 0"                    test "$A1_RC" -eq 0
t "structural asserts pass report"  has "$A1" "theme + layout asserts pass"
t "backup bana"                     bash -c "ls -1d '$FAKE'/releases/ui1fix-* >/dev/null 2>&1"
t "post css me navy token"          test "$(grepc -- '--navy: #1c2733' "$CSS")" -ge 1
t "post css me orange accent"       test "$(grepc -- '--accent: #FF6C2C' "$CSS")" -ge 1
t "post layout me search box"       test "$(grepc 'id="acp-search"' "$LAY")" -ge 1
t "post layout me filter JS"        test "$(grepc 'acp-search' "$LAY")" -ge 2
t "post dashboard dash-cols"        test "$(grepc 'dash-cols' "$DASH")" -ge 1
t "post dashboard General Information" test "$(grepc 'General Information' "$DASH")" -ge 1
t "post dashboard Statistics"       test "$(grepc 'Statistics' "$DASH")" -ge 1
t "post sections partial PRESENT"   test -f "$PART"
git -C "$REPO" show "${ERA}:${PANEL_REL}/public/assets/panel.css" > "$FAKE/.expect.css"
t "embedded byte-for-byte (panel.css, era)" bash -c "cmp -s '$CSS' '$FAKE/.expect.css'"
git -C "$REPO" show "${ERA}:${PANEL_REL}/resources/views/dashboard.blade.php" > "$FAKE/.expect-dash"
t "embedded byte-for-byte (dashboard, era)" bash -c "cmp -s '$DASH' '$FAKE/.expect-dash'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose css token 1"        hasre "$D2" "css paper theme[[:space:]]*:[[:space:]]*1"
t "diagnose search 1"           hasre "$D2" "layout search box[[:space:]]*:[[:space:]]*1"
t "diagnose partial PRESENT"    has "$D2" "PRESENT"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"               test "$R1_RC" -eq 0
t "rollback ne purana css lautaya" test "$(grepc -- '--navy: #1c2733' "$CSS")" -eq 0
t "rollback ne partial hatayi"    test ! -f "$PART"
t "rollback ne search hataya"      test "$(grepc 'id="acp-search"' "$LAY")" -eq 0

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"               test "$A2_RC" -eq 0
t "re-apply ke baad token wapas"  test "$(grepc -- '--navy: #1c2733' "$CSS")" -ge 1

echo ""
echo "----------------------------------------"
echo "ui1-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
