#!/usr/bin/env bash
# tools/sim/finalize-sim.sh — finalize v1.0 ka SIM harness (local + live dono).
# Live:  sudo FIX=/tmp/finalize-v1.0.sh bash /tmp/finalize-sim.sh
# Local: bash tools/sim/finalize-sim.sh   (php na ho to lint skip hota hai)
set -uo pipefail

REPO="${REPO:-$(cd "$(dirname "$0")/../.." && pwd)}"
FIX="${FIX:-$REPO/installer/finalize.sh}"
FAKE="$(mktemp -d /tmp/acp-finalize.XXXXXX)"
trap 'rm -rf "$FAKE"' EXIT

PASS=0; FAIL=0
t() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$name"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$name"; fi; }

# ---- fake roots seed ----
mkdir -p "$FAKE/home/panel/resources/views/layouts" "$FAKE/home/panel/public/assets" \
         "$FAKE/home/panel/config" "$FAKE/rc-etc" "$FAKE/rc-share"
printf '<?php\n/* seeded original blade-marker OLD-BLADE */\n' > "$FAKE/home/panel/resources/views/layouts/panel.blade.php"
printf '/* seeded original css OLD-CSS */\n'                 > "$FAKE/home/panel/public/assets/panel.css"
printf '<?php\nreturn ["version" => "0.0.1"];\n'             > "$FAKE/home/panel/config/acp.php"
printf '<?php\n/* seeded rc config */\n$config = [];\n'      > "$FAKE/rc-etc/config.inc.php"

echo "== apply #1 (SIM) =="
A1_RC=0
SIM=1 ACP_HOME="$FAKE/home" ACP_RC_ETC="$FAKE/rc-etc" ACP_RC_SHARE="$FAKE/rc-share" \
  bash "$FIX" > "$FAKE/.a1" 2>&1 || A1_RC=$?
if [[ "$A1_RC" -ne 0 ]]; then echo "----- A1 tail -----"; tail -20 "$FAKE/.a1"; echo "-------------------"; fi
t "installer exit 0"        test "$A1_RC" -eq 0
t "blade hamburger"         grep -q 'acp-nav-toggle' "$FAKE/home/panel/resources/views/layouts/panel.blade.php"
t "blade brand hook"        grep -q "config('acp.brand.name'" "$FAKE/home/panel/resources/views/layouts/panel.blade.php"
t "css mobile media 860"    grep -q 'max-width: 860px' "$FAKE/home/panel/public/assets/panel.css"
t "config brand hook"       grep -q "'brand'" "$FAKE/home/panel/config/acp.php"
t "rc product_name"         grep -q "product_name'] = 'AlphaCP Webmail'" "$FAKE/rc-etc/config.inc.php"
t "rc skin_logo"            grep -q 'acp-logo.svg' "$FAKE/rc-etc/config.inc.php"
t "logo svg likha"          test -s "$FAKE/rc-share/skins/elastic/images/acp-logo.svg"

BDIR="$(ls -dt "$FAKE/home"/releases/finalize-* 2>/dev/null | head -1)"  # apply#1 ka backup = seeded original
sleep 1

echo "== apply #2 (idempotent) =="
A2_RC=0
SIM=1 ACP_HOME="$FAKE/home" ACP_RC_ETC="$FAKE/rc-etc" ACP_RC_SHARE="$FAKE/rc-share" \
  bash "$FIX" > "$FAKE/.a2" 2>&1 || A2_RC=$?
t "re-apply exit 0"         test "$A2_RC" -eq 0
t "brand block ek baar"     test "$(grep -c 'ACP_BRAND_START' "$FAKE/rc-etc/config.inc.php")" -eq 1

sleep 1
echo "== rollback =="
RB_RC=0
bash "$FIX" --rollback "$BDIR" > "$FAKE/.rb" 2>&1 || RB_RC=$?
t "rollback exit 0"         test "$RB_RC" -eq 0
t "blade original wapas"    grep -q 'OLD-BLADE' "$FAKE/home/panel/resources/views/layouts/panel.blade.php"
t "rc config original wapas" bash -c "! grep -q 'AlphaCP Webmail' '$FAKE/rc-etc/config.inc.php'"

printf '\n----------------------------------------\nfinalize-sim: passed=%d failed=%d\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
