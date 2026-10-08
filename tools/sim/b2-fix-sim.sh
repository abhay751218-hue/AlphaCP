#!/usr/bin/env bash
# =============================================================================
#  b2-fix-sim — installer/b2-fix.sh (Metrics via root agent) ka
#  end-to-end sandbox proof. Fake ACP_HOME ko LIVE ki maujooda state me lata hai
#  (commit 935a3e3 = sec-fix ke baad: registry 95 types, metrics.access absent,
#  panel MetricsController Support/Metrics::parse se → open_basedir bug), phir:
#    1  reproduce: 95 types / metrics 0, panel parse() wala, suite NOT green
#    2  --diagnose read-only (MISSING, metrics 0, parse() >0; kuch likhta nahi)
#    3  apply: backup → lint 4+2 → smoke → suite GREEN (passed>=221 failed=0)
#       → registry 96 → controller Paneld::run / Support parse() 0
#    4  --diagnose post (PRESENT, metrics 1, parse 0)
#    5  --rollback (95 types + parse()-wali Support wapas)
#    6  re-apply (idempotent)
#  Usage: bash tools/sim/b2-fix-sim.sh    (PHPBIN=/path/to/php override)
# =============================================================================
set -uo pipefail
# php-wasm `PHP` env ko version maanta hai — sim apna binary PHPBIN me rakhta hai.
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/b2-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

PRE="935a3e3"   # sec-fix commit = live ki maujooda state (pre-B2)

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
notgreen(){ local s="$1"; [[ -z "$s" ]] && return 0; local n; n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"$s")"; [[ "$n" != "0" ]]; }

[[ -f "$FIX" ]] || { echo "installer/b2-fix.sh nahi mila — python3 tools/build-b2-fix.py chalao"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php8.4/php nahi mila (PHPBIN=... set karo)"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila (pre-state fixtures chahiye)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-b2fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/panel/app/Http/Controllers" "$FAKE/panel/app/Support"

# ---- agent: repo copy, phir PRE (live) registry/allowlist + b1 handlers hata do ----
cp -a "${REPO}/agent" "$FAKE/agent"
git -C "$REPO" show "${PRE}:agent/config/tasks.php"      > "$FAKE/agent/config/tasks.php"
git -C "$REPO" show "${PRE}:agent/src/CommandRunner.php" > "$FAKE/agent/src/CommandRunner.php"
rm -f "$FAKE/agent/src/Metrics.php" "$FAKE/agent/src/Tasks/MetricsAccess.php"

# ---- panel: PRE (Process-wale) controllers + AppInstaller ----
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
# test-suite ARTIFACT-era (715a9e2) se pin — post-gate usi suite par chalta hai.
git -C "$REPO" show '715a9e2:agent/tests/run-tests.php'           > "$FAKE/agent/tests/run-tests.php"
git -C "$REPO" show '715a9e2:agent/tests/FakeCommandExecutor.php' > "$FAKE/agent/tests/FakeCommandExecutor.php"
for f in app/Http/Controllers/MetricsController.php app/Support/Metrics.php; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
done

suite(){ "$PHPBIN" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }
types_count(){ "$PHPBIN" -r '$t=require $argv[1]; echo count($t);' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
b1_types(){ "$PHPBIN" -r '$t=require $argv[1]; echo count(array_filter(array_keys($t), fn($k)=>$k==="metrics.access"));' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
grepc(){ grep -c "$@" 2>/dev/null || true; }
procfiles(){ grep -l 'Process::run\|Process::timeout' "$@" 2>/dev/null | wc -l; }
allhandlers(){ local f; for f in IpBlock IpUnblock WafStatus WafEnable WafDisable VirusScan; do [[ -f "$FAKE/agent/src/Tasks/$f.php" ]] || return 1; done; }

echo "== reproduce: live PRE state (95 types + parse()-wala Metrics) =="
PRE_S="$(suite)"
echo "  pre-fix suite   : ${PRE_S:-<fatal / no summary>}"
echo "  pre-fix registry: $(types_count) types, b1 = $(b1_types)"
t "pre-fix registry 95 types hai"             test "$(types_count)" -eq 95
t "pre-fix registry me metrics.access NAHI"  test "$(b1_types)" -eq 0
t "pre-fix suite GREEN nahi (handlers missing)" notgreen "$PRE_S"
t "pre-fix src/Metrics.php maujood nahi"     test ! -f "$FAKE/agent/src/Metrics.php"
t "pre-fix controller Metrics::parse chalata hai (open_basedir bug)" test "$(grepc 'Metrics::parse(' "$FAKE/panel/app/Http/Controllers/MetricsController.php")" -eq 1
t "pre-fix Support/Metrics me parse() hai" test "$(grepc 'function parse' "$FAKE/panel/app/Support/Metrics.php")" -eq 1
t "pre-fix registry file padhne layak hai"   test "$(types_count)" -gt 0

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai"       has "$D1" "MISSING"
t "diagnose metrics 0 dikhata hai"     hasre "$D1" "registry metrics[[:space:]]*:[[:space:]]*0"
t "diagnose parse() >0 dikhata hai"    hasre "$D1" "Support parse\(\)[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose ne koi file nahi likhi"    test ! -f "$FAKE/agent/src/Metrics.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|SMOKE|smoke|suite|backup:|FINAL|APPLY ho|✖|⚠|·' | sed 's/^/    /'
t "apply exit 0"                        test "$A1_RC" -eq 0
t "agent src/Metrics.php likhi gayi"   test -f "$FAKE/agent/src/Metrics.php"
t "MetricsAccess handler likha gaya"     test -f "$FAKE/agent/src/Tasks/MetricsAccess.php"
t "static smoke PASS hua"               has "$A1" "static smoke PASS"
t "agent lint clean (4) report hua"    has "$A1" "lint clean (4)"
t "panel lint clean (2) report hua"    has "$A1" "lint clean (2)"
t "backup bana"                         bash -c "ls -1d '$FAKE'/releases/b2fix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite   : ${POST}"
echo "  post-fix registry: $(types_count) types, b1 = $(b1_types)"
t "post-fix suite GREEN (failed=0)"     test "${POST_F:-9}" -eq 0
t "post-fix passed >= 221"              test "${POST_P:-0}" -ge 221
t "post-fix registry 96 types"          test "$(types_count)" -eq 96
t "post-fix registry me metrics.access" test "$(b1_types)" -eq 1
t "post-fix MetricsAccess handler likha"  test -f "$FAKE/agent/src/Tasks/MetricsAccess.php"
t "post-fix Support me parse() NAHI"    test "$(grepc 'function parse' "$FAKE/panel/app/Support/Metrics.php")" -eq 0
t "post-fix controller Paneld::run"     test "$(grepc 'Paneld::run' "$FAKE/panel/app/Http/Controllers/MetricsController.php")" -ge 1
t "post-fix controller me Metrics::parse NAHI" test "$(grepc 'Metrics::parse(' "$FAKE/panel/app/Http/Controllers/MetricsController.php")" -eq 0
git -C "$REPO" show "715a9e2:agent/config/tasks.php" > "$FAKE/.expect-tasks.php"
t "embedded payload byte-for-byte (tasks.php, era)" bash -c "cmp -s '$FAKE/agent/config/tasks.php' '$FAKE/.expect-tasks.php'"
git -C "$REPO" show "715a9e2:${PANEL_REL}/app/Http/Controllers/MetricsController.php" > "$FAKE/.expect-mc.php"
t "embedded payload byte-for-byte (MetricsController, era)" bash -c "cmp -s '$FAKE/panel/app/Http/Controllers/MetricsController.php' '$FAKE/.expect-mc.php'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai"        has "$D2" "PRESENT"
t "diagnose metrics 1 dikhata hai"      hasre "$D2" "registry metrics[[:space:]]*:[[:space:]]*1"
t "diagnose controller Paneld >=1"      hasre "$D2" "controller Paneld[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose parse() 0 dikhata hai"      hasre "$D2" "Support parse\(\)[[:space:]]*:[[:space:]]*0"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"                     test "$R1_RC" -eq 0
t "rollback ne src/Metrics.php hata di" test ! -f "$FAKE/agent/src/Metrics.php"
t "rollback ne 95-type registry lautaya" test "$(types_count)" -eq 95
t "rollback ne parse()-wali Support lautayi" test "$(grepc 'function parse' "$FAKE/panel/app/Support/Metrics.php")" -eq 1
t "rollback ke baad suite phir GREEN nahi" notgreen "$(suite)"

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"                     test "$A2_RC" -eq 0
t "re-apply ke baad suite phir GREEN"   test "$(failed_count)" -eq 0
t "re-apply ke baad registry 96"        test "$(types_count)" -eq 96

echo ""
echo "----------------------------------------"
echo "b2-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
