#!/usr/bin/env bash
# =============================================================================
#  suite-enable-sim — installer/suite-enable.sh ka sandbox proof.
#  Sandbox PHP me pdo_sqlite PEHLE SE hai → idempotent skip-path + live-suite
#  gate ka full run (222/0) + negative gate-path (stub suite failed:3 → exit 1).
#  Usage: bash tools/sim/suite-enable-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/suite-enable.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/suite-enable.sh nahi mila"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-suite.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs"
cp -a "${REPO}/agent" "$FAKE/agent"

echo "== apply (pdo_sqlite present → skip-path + suite gate) =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'pdo_sqlite|suite|FINAL|✖|' | sed 's/^/    /'
t "apply exit 0"                       test "$A1_RC" -eq 0
t "idempotent skip-path chala"         has "$A1" "pehle se loaded"
t "suite GREEN report hua"             has "$A1" "agent suite GREEN"
passed_in(){ sed -nE 's/.*passed=([0-9]+) failed=0.*/\1/p' <<<"$1" | tail -1; }
t "passed>=222 gate laga"              test "$(passed_in "$A1")" -ge 222

echo "== diagnose =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose pdo_sqlite YES"            has "$D1" "pdo_sqlite loaded          : YES"
t "diagnose suite file PRESENT"        has "$D1" "PRESENT"

echo "== negative: suite fail ho to gate roke =="
cat > "$FAKE/agent/tests/run-tests.php" <<'STUB'
<?php
echo "passed: 10   failed: 3\n";
exit(0);
STUB
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "fail-suite par exit non-zero"       test "$A2_RC" -ne 0
t "fail-suite par gate message"        has "$A2" "suite green nahi"

echo "== negative 2: suite fatal (exit non-zero) ho to tail + clear message =="
cat > "$FAKE/agent/tests/run-tests.php" <<'STUB'
<?php
fwrite(STDERR, "PHP Fatal error:  Uncaught Error: Class \"DOMDocument\" not found\n");
exit(255);
STUB
A3="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A3_RC=$?
t "fatal-suite par exit non-zero"      test "$A3_RC" -ne 0
t "fatal-suite par gate message (silent death nahi)" has "$A3" "suite green nahi"

echo ""
echo "----------------------------------------"
echo "suite-enable-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
