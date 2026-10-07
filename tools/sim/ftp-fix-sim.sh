#!/usr/bin/env bash
# =============================================================================
#  ftp-fix-sim — installer/ftp-fix.sh (B1 part 1: FTP via root agent) ka
#  end-to-end sandbox proof. Ek FAKE ACP_HOME banata hai jisme agent + panel
#  PRE-FTP state me hote hain (= live bug reproduce: agent registry me ftp.*
#  nahi, panel web-FPM se Process::run(['pure-pw'…]) → 500), phir:
#    1  reproduce: registry 80 types / ftp 0, panel me Process::, suite NOT green
#    2  --diagnose read-only: MISSING + ftp.add 0 + Process >0 (kuch likhta nahi)
#    3  apply: backup → lint → static smoke → suite GREEN (passed>=215 failed=0)
#       → registry 83 types → panel Process 0 / enqueue 3
#    4  --diagnose ab PRESENT + ftp.add 1 + Process 0
#    5  --rollback: ftp files wapas hat jaati hain, panel Process:: laut aata hai
#    6  re-apply: phir GREEN (idempotent)
#  Usage: bash tools/sim/ftp-fix-sim.sh    (PHPBIN=/path/to/php override kar sakte ho)
# =============================================================================
set -uo pipefail

# php-wasm `PHP` env ko version maanta hai — sim apna binary PHPBIN me rakhta hai.
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/ftp-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
# FTP fix se PEHLE ka commit (pre-FTP agent registry + Process-wala panel)
PRE_FTP="35cd630^"

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
notgreen(){ local s="$1"; [[ -z "$s" ]] && return 0; local n; n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"$s")"; [[ "$n" != "0" ]]; }

[[ -f "$FIX" ]] || { echo "installer/ftp-fix.sh nahi mila — python3 tools/build-ftp-fix.py chalao"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php8.4/php nahi mila (PHPBIN=... set karo)"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila (pre-FTP fixtures chahiye)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-ftpfix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/panel/app/Support" "$FAKE/panel/app/Http/Controllers"

# ---- agent: repo copy, phir PRE-FTP registry/allowlist + ftp handlers hata do ----
cp -a "${REPO}/agent" "$FAKE/agent"
git -C "$REPO" show "${PRE_FTP}:agent/config/tasks.php"      > "$FAKE/agent/config/tasks.php"
git -C "$REPO" show "${PRE_FTP}:agent/src/CommandRunner.php" > "$FAKE/agent/src/CommandRunner.php"
rm -f "$FAKE/agent/src/Ftp.php" "$FAKE/agent/src/Tasks/FtpTask.php" \
      "$FAKE/agent/src/Tasks/FtpAdd.php" "$FAKE/agent/src/Tasks/FtpPasswd.php" \
      "$FAKE/agent/src/Tasks/FtpDel.php"

# ---- panel: PRE-FTP (Process-wala) Support/Ftp + FtpController ----
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
git -C "$REPO" show "${PRE_FTP}:${PANEL_REL}/app/Support/Ftp.php"                    > "$FAKE/panel/app/Support/Ftp.php"
git -C "$REPO" show "${PRE_FTP}:${PANEL_REL}/app/Http/Controllers/FtpController.php" > "$FAKE/panel/app/Http/Controllers/FtpController.php"

suite(){ "$PHPBIN" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }
types_count(){ "$PHPBIN" -r '$t=require $argv[1]; echo count($t);' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
ftp_types(){ "$PHPBIN" -r '$t=require $argv[1]; echo count(array_filter(array_keys($t), fn($k)=>str_starts_with($k,"ftp.")));' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
grepc(){ grep -c "$1" "$2" 2>/dev/null || true; }

echo "== reproduce: live PRE-FTP state (agent registry + Process-wala panel) =="
PRE="$(suite)"
echo "  pre-fix suite   : ${PRE:-<fatal / no summary>}"
echo "  pre-fix registry: $(types_count) types, ftp.* = $(ftp_types)"
t "pre-fix registry me 80 types hain"            test "$(types_count)" -eq 80
t "pre-fix registry me koi ftp.* task NAHI"      test "$(ftp_types)" -eq 0
t "pre-fix suite GREEN nahi (handlers missing)"  notgreen "$PRE"
t "pre-fix agent/src/Ftp.php maujood nahi"       test ! -f "$FAKE/agent/src/Ftp.php"
t "pre-fix panel Support/Ftp Process:: chalata hai (500 ki jad)" test "$(grepc 'Process::' "$FAKE/panel/app/Support/Ftp.php")" -gt 0
t "pre-fix FtpController Ftp::addUser bulata hai" test "$(grepc 'Ftp::addUser' "$FAKE/panel/app/Http/Controllers/FtpController.php")" -gt 0
t "pre-fix allowlist me pure-pw nahi"            test "$(grepc 'pure-pw' "$FAKE/agent/src/CommandRunner.php")" -eq 0

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai"        has "$D1" "MISSING"
t "diagnose ftp.add 0 dikhata hai"      hasre "$D1" "ftp\.add[[:space:]]*:[[:space:]]*0"
t "diagnose Process bug dikhata hai"    hasre "$D1" "Support/Ftp Process:[[:space:]]*[1-9]"
t "diagnose ne koi file nahi likhi"     test ! -f "$FAKE/agent/src/Ftp.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|SMOKE|smoke|suite|backup:|FINAL|APPLY ho|✖|⚠|·' | sed 's/^/    /'
t "apply exit 0"                        test "$A1_RC" -eq 0
t "agent src/Ftp.php likhi gayi"        test -f "$FAKE/agent/src/Ftp.php"
t "charo ftp handlers likhe gaye"       bash -c "test -f '$FAKE/agent/src/Tasks/FtpAdd.php' -a -f '$FAKE/agent/src/Tasks/FtpPasswd.php' -a -f '$FAKE/agent/src/Tasks/FtpDel.php' -a -f '$FAKE/agent/src/Tasks/FtpTask.php'"
t "static smoke PASS hua"               has "$A1" "static smoke PASS"
t "agent lint clean report hua"         has "$A1" "lint clean (7)"
t "panel lint clean report hua"         has "$A1" "lint clean (2)"
t "backup bana"                         bash -c "ls -1d '$FAKE'/releases/ftpfix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite   : ${POST}"
echo "  post-fix registry: $(types_count) types, ftp.* = $(ftp_types)"
t "post-fix suite GREEN (failed=0)"     test "${POST_F:-9}" -eq 0
t "post-fix passed >= 215"              test "${POST_P:-0}" -ge 215
t "post-fix registry 83 types"          test "$(types_count)" -eq 83
t "post-fix registry me 3 ftp.* tasks"  test "$(ftp_types)" -eq 3
t "post-fix allowlist me pure-pw hai"   test "$(grepc 'pure-pw' "$FAKE/agent/src/CommandRunner.php")" -gt 0
t "post-fix panel me Process:: NAHI"    test "$(grepc 'Process::' "$FAKE/panel/app/Support/Ftp.php")" -eq 0
t "post-fix panel 3 baar enqueue karta hai" test "$(grepc 'AccountProvisioner::enqueue' "$FAKE/panel/app/Http/Controllers/FtpController.php")" -eq 3
t "post-fix embedded payload byte-for-byte match (tasks.php)" bash -c "cmp -s '$FAKE/agent/config/tasks.php' '$REPO/agent/config/tasks.php'"
t "post-fix embedded payload byte-for-byte match (FtpController)" bash -c "cmp -s '$FAKE/panel/app/Http/Controllers/FtpController.php' '$REPO/$PANEL_REL/app/Http/Controllers/FtpController.php'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai"        has "$D2" "PRESENT"
t "diagnose ftp.add 1 dikhata hai"      hasre "$D2" "ftp\.add[[:space:]]*:[[:space:]]*1"
t "diagnose ftp total 3 dikhata hai"    hasre "$D2" "ftp total[[:space:]]*:[[:space:]]*3"
t "diagnose Process 0 dikhata hai"      hasre "$D2" "Support/Ftp Process:[[:space:]]*0"
t "diagnose enqueue 3 dikhata hai"      hasre "$D2" "FtpController queue:[[:space:]]*3"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"                     test "$R1_RC" -eq 0
t "rollback ne src/Ftp.php hata di"     test ! -f "$FAKE/agent/src/Ftp.php"
t "rollback ne pre-FTP tasks.php lautaya (80 types)" test "$(types_count)" -eq 80
t "rollback ne Process-wala panel lautaya" test "$(grepc 'Process::' "$FAKE/panel/app/Support/Ftp.php")" -gt 0
t "rollback ke baad suite phir GREEN nahi" notgreen "$(suite)"

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"                     test "$A2_RC" -eq 0
t "re-apply ke baad suite phir GREEN"   test "$(failed_count)" -eq 0
t "re-apply ke baad registry 83"        test "$(types_count)" -eq 83

echo ""
echo "----------------------------------------"
echo "ftp-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
