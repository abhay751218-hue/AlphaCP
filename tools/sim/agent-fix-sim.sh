#!/usr/bin/env bash
# =============================================================================
#  agent-fix-sim — installer/agent-fix.sh (B6: MysqlServer.php restore) ka
#  end-to-end sandbox proof. Ek FAKE ACP_HOME banata hai jisme canonical agent/
#  hota hai PAR MysqlServer.php hata diya jata hai (= live bug reproduce), phir:
#    1  pre-fix suite FAIL hona chahiye (class not found)         [reproduce]
#    2  --diagnose read-only: "MISSING" dikhaye
#    3  apply: lint + static smoke + (pdo_sqlite ho to) full suite GREEN gate
#    4  post-fix suite 212/0; MysqlServer.php maujood
#    5  --diagnose ab "PRESENT" + smoke pass
#    6  --rollback: file hata de, suite phir FAIL
#    7  re-apply: phir GREEN (idempotent)
#  Usage: sudo bash tools/sim/agent-fix-sim.sh   (ya bash, root ki zaroorat nahi)
# =============================================================================
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/agent-fix.sh"
PHP="${PHP:-$(command -v php8.4 || command -v php)}"

pass=0; fail=0
t(){ # t "desc" condition-exit-code
  local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }

[[ -f "$FIX" ]] || { echo "agent-fix.sh nahi mila — python3 tools/build-agent-fix.py chalao"; exit 2; }
[[ -n "$PHP" ]] || { echo "php8.4/php nahi mila"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-agentfix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases"
cp -a "${REPO}/agent" "$FAKE/agent"

suite(){ "$PHP" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }

echo "== reproduce: MysqlServer.php hata do (live bug) =="
rm -f "$FAKE/agent/src/MysqlServer.php"
PRE="$(suite)"; PRE_F="$(failed_count)"
echo "  pre-fix suite: ${PRE:-<no summary>} (failed=${PRE_F})"
t "pre-fix suite me failures hain (bug reproduce)" test "${PRE_F:-0}" -gt 0
t "pre-fix failure MysqlServer class se hai" bash -c "'$PHP' '$FAKE/agent/tests/run-tests.php' 2>&1 | grep -q 'MysqlServer'"

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai" has "$D1" "MISSING"
t "diagnose ne file nahi banai" test ! -f "$FAKE/agent/src/MysqlServer.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'SELF-TEST|GREEN|SMOKE OK|FINAL VERDICT|APPLY ho gaya|failed=' | sed 's/^/    /'
t "apply exit 0" test "$A1_RC" -eq 0
t "apply ne MysqlServer.php likhi" test -f "$FAKE/agent/src/MysqlServer.php"
t "static smoke PASS hua" has "$A1" "static smoke PASS"
t "backup bana" bash -c "ls -1d '$FAKE'/releases/agentfix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite: ${POST} (passed=${POST_P} failed=${POST_F})"
t "post-fix suite GREEN (failed=0)" test "${POST_F:-9}" -eq 0
t "post-fix passed >= 212" test "${POST_P:-0}" -ge 212

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai" has "$D2" "PRESENT"
t "diagnose smoke pass dikhata hai" has "$D2" "smoke pass"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0" test "$R1_RC" -eq 0
t "rollback ne file hata di (pre-exist nahi thi)" test ! -f "$FAKE/agent/src/MysqlServer.php"
RB_F="$(failed_count)"
t "rollback ke baad suite phir FAIL" test "${RB_F:-0}" -gt 0

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0" test "$A2_RC" -eq 0
RE_F="$(failed_count)"
t "re-apply ke baad suite phir GREEN" test "${RE_F:-9}" -eq 0

echo ""
echo "----------------------------------------"
echo "agent-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
