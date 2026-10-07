#!/usr/bin/env bash
# =============================================================================
#  sec-fix-sim — installer/sec-fix.sh (Security suite via root agent) ka
#  end-to-end sandbox proof. Fake ACP_HOME ko LIVE ki maujooda state me lata hai
#  (commit b97d76f = b1-fix ke baad: registry 89 types, security.*/waf.* absent,
#  panel Support/Firewall+Waf `Process::` se → HTTP 500), phir:
#    1  reproduce: 89 types / sec 0, panel Process::, suite NOT green
#    2  --diagnose read-only (MISSING, sec/waf 0, Process >0; kuch likhta nahi)
#    3  apply: backup → lint 9+2 → smoke → suite GREEN (passed>=220 failed=0)
#       → registry 95 → panel Support files Process 0 / Paneld present
#    4  --diagnose post (PRESENT, sec/waf 6, allowlist 2)
#    5  --rollback (89 types + Process-wali Support files wapas)
#    6  re-apply (idempotent)
#  Usage: bash tools/sim/sec-fix-sim.sh   (PHPBIN=/path/to/php override)
# =============================================================================
set -uo pipefail
# php-wasm `PHP` env ko version maanta hai — sim apna binary PHPBIN me rakhta hai.
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/sec-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

PRE="b97d76f"   # b1-fix commit = live ki maujooda state (pre-B1-ext)

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
notgreen(){ local s="$1"; [[ -z "$s" ]] && return 0; local n; n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"$s")"; [[ "$n" != "0" ]]; }

[[ -f "$FIX" ]] || { echo "installer/sec-fix.sh nahi mila — python3 tools/build-sec-fix.py chalao"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php8.4/php nahi mila (PHPBIN=... set karo)"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila (pre-state fixtures chahiye)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-secfix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/panel/app/Http/Controllers" "$FAKE/panel/app/Support"

# ---- agent: repo copy, phir PRE (live) registry/allowlist + b1 handlers hata do ----
cp -a "${REPO}/agent" "$FAKE/agent"
git -C "$REPO" show "${PRE}:agent/config/tasks.php"      > "$FAKE/agent/config/tasks.php"
git -C "$REPO" show "${PRE}:agent/src/CommandRunner.php" > "$FAKE/agent/src/CommandRunner.php"
rm -f "$FAKE/agent/src/Tasks/SecurityTask.php" "$FAKE/agent/src/Tasks/IpBlock.php" \
      "$FAKE/agent/src/Tasks/IpUnblock.php" "$FAKE/agent/src/Tasks/WafStatus.php" \
      "$FAKE/agent/src/Tasks/WafEnable.php" "$FAKE/agent/src/Tasks/WafDisable.php" \
      "$FAKE/agent/src/Tasks/VirusScan.php"

# ---- panel: PRE (Process-wale) controllers + AppInstaller ----
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
# test-suite ARTIFACT-era (ce59b7b) se pin — post-gate usi suite par chalta hai.
git -C "$REPO" show 'ce59b7b:agent/tests/run-tests.php'           > "$FAKE/agent/tests/run-tests.php"
git -C "$REPO" show 'ce59b7b:agent/tests/FakeCommandExecutor.php' > "$FAKE/agent/tests/FakeCommandExecutor.php"
for f in app/Support/Firewall.php app/Support/Waf.php; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
done

suite(){ "$PHPBIN" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }
types_count(){ "$PHPBIN" -r '$t=require $argv[1]; echo count($t);' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
b1_types(){ "$PHPBIN" -r '$t=require $argv[1]; echo count(array_filter(array_keys($t), fn($k)=>str_starts_with($k,"security.")||str_starts_with($k,"waf.")));' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
grepc(){ grep -c "$@" 2>/dev/null || true; }
procfiles(){ grep -l 'Process::run\|Process::timeout' "$@" 2>/dev/null | wc -l; }
allhandlers(){ local f; for f in IpBlock IpUnblock WafStatus WafEnable WafDisable VirusScan; do [[ -f "$FAKE/agent/src/Tasks/$f.php" ]] || return 1; done; }

echo "== reproduce: live PRE state (89 types + Process-wali Support files) =="
PRE_S="$(suite)"
echo "  pre-fix suite   : ${PRE_S:-<fatal / no summary>}"
echo "  pre-fix registry: $(types_count) types, b1 = $(b1_types)"
t "pre-fix registry 89 types hai"             test "$(types_count)" -eq 89
t "pre-fix registry me koi sec task NAHI"    test "$(b1_types)" -eq 0
t "pre-fix suite GREEN nahi (handlers missing)" notgreen "$PRE_S"
t "pre-fix SecurityTask.php maujood nahi"    test ! -f "$FAKE/agent/src/Tasks/SecurityTask.php"
t "pre-fix Firewall Process chalata hai (500 ki jad)" test "$(procfiles "$FAKE/panel/app/Support/Firewall.php")" -eq 1
t "pre-fix Waf Process chalata hai" test "$(procfiles "$FAKE/panel/app/Support/Waf.php")" -eq 1
t "pre-fix allowlist me ufw nahi"            test "$(grepc "'/usr/sbin/ufw'" "$FAKE/agent/src/CommandRunner.php")" -eq 0

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai"       has "$D1" "MISSING"
t "diagnose sec/waf 0 dikhata hai"     hasre "$D1" "security/waf:[[:space:]]*0"
t "diagnose Firewall Process >0 hai"   hasre "$D1" "Firewall Process[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose ne koi file nahi likhi"    test ! -f "$FAKE/agent/src/Tasks/SecurityTask.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|SMOKE|smoke|suite|backup:|FINAL|APPLY ho|✖|⚠|·' | sed 's/^/    /'
t "apply exit 0"                        test "$A1_RC" -eq 0
t "agent SecurityTask.php likhi gayi"  test -f "$FAKE/agent/src/Tasks/SecurityTask.php"
t "saare 6 sec handlers likhe gaye"    allhandlers
t "static smoke PASS hua"               has "$A1" "static smoke PASS"
t "agent lint clean (9) report hua"    has "$A1" "lint clean (9)"
t "panel lint clean (2) report hua"    has "$A1" "lint clean (2)"
t "backup bana"                         bash -c "ls -1d '$FAKE'/releases/secfix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite   : ${POST}"
echo "  post-fix registry: $(types_count) types, b1 = $(b1_types)"
t "post-fix suite GREEN (failed=0)"     test "${POST_F:-9}" -eq 0
t "post-fix passed >= 220"              test "${POST_P:-0}" -ge 220
t "post-fix registry 95 types"          test "$(types_count)" -eq 95
t "post-fix registry me 6 sec tasks"    test "$(b1_types)" -eq 6
t "post-fix allowlist me ufw+clamscan"  test "$(grepc -e "'/usr/sbin/ufw'" -e "'/usr/bin/clamscan'" "$FAKE/agent/src/CommandRunner.php")" -ge 2
t "post-fix Support me Process:: nahi"  test "$(procfiles "$FAKE/panel/app/Support/Firewall.php" "$FAKE/panel/app/Support/Waf.php")" -eq 0
t "post-fix Waf Paneld:: x3"             test "$(grepc 'Paneld::' "$FAKE/panel/app/Support/Waf.php")" -ge 3
t "post-fix Firewall Paneld::enqueue x2" test "$(grepc 'Paneld::enqueue' "$FAKE/panel/app/Support/Firewall.php")" -eq 2
git -C "$REPO" show "ce59b7b:agent/config/tasks.php" > "$FAKE/.expect-tasks.php"
t "embedded payload byte-for-byte (tasks.php, era)" bash -c "cmp -s '$FAKE/agent/config/tasks.php' '$FAKE/.expect-tasks.php'"
git -C "$REPO" show "ce59b7b:${PANEL_REL}/app/Support/Waf.php" > "$FAKE/.expect-waf.php"
t "embedded payload byte-for-byte (Waf, era)" bash -c "cmp -s '$FAKE/panel/app/Support/Waf.php' '$FAKE/.expect-waf.php'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai"        has "$D2" "PRESENT"
t "diagnose sec/waf 6 dikhata hai"      hasre "$D2" "security/waf:[[:space:]]*6"
t "diagnose allowlist 2 dikhata hai"    hasre "$D2" "ufw/clamscan:[[:space:]]*2"
t "diagnose Firewall Process 0 hai"     hasre "$D2" "Firewall Process[[:space:]]*:[[:space:]]*0"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"                     test "$R1_RC" -eq 0
t "rollback ne SecurityTask hata di"    test ! -f "$FAKE/agent/src/Tasks/SecurityTask.php"
t "rollback ne 89-type registry lautaya" test "$(types_count)" -eq 89
t "rollback ne Process-wali Firewall lautayi" test "$(procfiles "$FAKE/panel/app/Support/Firewall.php")" -eq 1
t "rollback ke baad suite phir GREEN nahi" notgreen "$(suite)"

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"                     test "$A2_RC" -eq 0
t "re-apply ke baad suite phir GREEN"   test "$(failed_count)" -eq 0
t "re-apply ke baad registry 95"        test "$(types_count)" -eq 95

echo ""
echo "----------------------------------------"
echo "sec-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
