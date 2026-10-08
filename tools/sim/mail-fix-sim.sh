#!/usr/bin/env bash
# =============================================================================
#  mail-fix-sim — installer/mail-fix.sh ka sandbox proof.
#  PRE = 935a3e3-era purani test files (live jaisi stale state), phir:
#    reproduce (count != 222) → diagnose → apply (lint 2 + suite 222/0 +
#    byte-for-byte era 31d0301) → diagnose post → rollback (purani wapas) →
#    re-apply.  Usage: bash tools/sim/mail-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/mail-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
PRE="935a3e392436ed2c04b393219cd50208666f23eb"   # stale live-era tests
ERA="2969fd8"                                   # MailServer-fix + tests era

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/mail-fix.sh nahi mila — python3 tools/build-mail-fix.py"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-tsync.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/agent/tests"
cp -a "${REPO}/agent/src" "${REPO}/agent/config" "${REPO}/agent/bin" "$FAKE/agent/" 2>/dev/null || true
git -C "$REPO" show "${PRE}:agent/src/MailServer.php"            > "$FAKE/agent/src/MailServer.php"
git -C "$REPO" show "${PRE}:agent/tests/run-tests.php"           > "$FAKE/agent/tests/run-tests.php"
git -C "$REPO" show "${PRE}:agent/tests/FakeCommandExecutor.php" > "$FAKE/agent/tests/FakeCommandExecutor.php"
RUN="$FAKE/agent/tests/run-tests.php"
MS="$FAKE/agent/src/MailServer.php"
tcount(){ grep -c '^test(' "$1" 2>/dev/null || echo 0; }

echo "== reproduce: PRE state (stale tests) =="
PRE_COUNT="$(tcount "$RUN")"
echo "  pre test blocks: ${PRE_COUNT}"
t "pre count 222 nahi hai"        test "$PRE_COUNT" -ne 222
t "pre MailServer pre-fix hai"    test "$(grep -c 'Self-healing verify' "$MS")" -eq 0
git -C "$REPO" show "${PRE}:agent/src/MailServer.php" > "$FAKE/.expect-pre-ms"
t "pre MailServer byte=PRE era"   bash -c "cmp -s '$MS' '$FAKE/.expect-pre-ms'"
t "pre count >0 (file valid)"     test "$PRE_COUNT" -gt 0
git -C "$REPO" show "${PRE}:agent/tests/run-tests.php" > "$FAKE/.expect-pre"
t "pre file purani era ki hai"    bash -c "cmp -s '$RUN' '$FAKE/.expect-pre'"

echo "== --diagnose (pre) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
dcount(){ grep -oE 'test blocks[[:space:]]*:[[:space:]]*[0-9]+' <<<"$1" | grep -oE '[0-9]+$' | head -1; }
t "diagnose count 222 nahi"       test "$(dcount "$D1")" -ne 222

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|suite|backup:|FINAL|✖|' | sed 's/^/    /'
t "apply exit 0"                  test "$A1_RC" -eq 0
t "lint clean (3)"                has "$A1" "lint clean (3)"
t "suite GREEN 222"               has "$A1" "passed=222 failed=0"
t "backup bana"                   bash -c "ls -1d '$FAKE'/releases/mailfix-* >/dev/null 2>&1"
t "count ab 222"                  test "$(tcount "$RUN")" -eq 222
t "root-aware EISDIR maujood"     test "$(grep -c EISDIR "$RUN")" -ge 1
t "MailServer self-healing gaya"  test "$(grep -c 'Self-healing verify' "$MS")" -ge 1
git -C "$REPO" show "${ERA}:agent/src/MailServer.php" > "$FAKE/.expect-era-ms"
t "MailServer byte-for-byte era"  bash -c "cmp -s '$MS' '$FAKE/.expect-era-ms'"
git -C "$REPO" show "${ERA}:agent/tests/run-tests.php" > "$FAKE/.expect-era"
t "byte-for-byte era ${ERA}"      bash -c "cmp -s '$RUN' '$FAKE/.expect-era'"

echo "== ROOT-RUN suite (live failure-mode ka direct proof) =="
if [[ "$(id -u)" -eq 0 ]]; then
  t "suite root par green"         env PATH="$(dirname "$PHPBIN"):$PATH" php "$RUN"
elif sudo -n true 2>/dev/null; then
  t "suite root (sudo) par green" sudo -n env PATH="$(dirname "$PHPBIN"):$PATH" php "$RUN"
else
  echo "  (sudo nahi — root-run skip; live installer root hi chalta hai)"
fi

echo "== --diagnose (post) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose count 222"            hasre "$D2" "test blocks[[:space:]]*:[[:space:]]*222"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"               test "$R1_RC" -eq 0
t "purani tests wapas"            bash -c "cmp -s '$RUN' '$FAKE/.expect-pre'"
t "count wapas !=222"             test "$(tcount "$RUN")" -ne 222
t "MailServer pre-fix wapas"      bash -c "cmp -s '$MS' '$FAKE/.expect-pre-ms'"

echo "== re-apply =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"               test "$A2_RC" -eq 0
t "re-apply count 222"            test "$(tcount "$RUN")" -eq 222

echo ""
echo "----------------------------------------"
echo "mail-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
