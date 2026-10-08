#!/usr/bin/env bash
# =============================================================================
#  mail-fix-sim — installer/mail-fix.sh v1.1 ka sandbox proof.
#  PRE = 3195ff6-era (mail-fix v1.0 applied jaisi live state: self-healing
#  MailServer, lekin is_executable()-based installed() + uid<=0 Maildir skip +
#  binsAbsent-less tests) → reproduce (markers 0) → diagnose → apply (lint 4 +
#  suite 222/0 + byte-for-byte era 380ce91, 4 files) → ROOT-RUN suite green →
#  diagnose post → rollback (v1.0-era wapas) → re-apply.
#  Usage: bash tools/sim/mail-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/mail-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
PRE="3195ff6fdd69de57537c43b1b96554e3ba239aa9"   # mail-fix v1.0 era (live-parity bugs wali)
ERA="380ce91"                                   # v1.1 fix era (4 files)

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/mail-fix.sh nahi mila — python3 tools/build-mail-fix.py"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php nahi mila (PHPBIN=/path)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-mfix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/agent/tests"
cp -a "${REPO}/agent/src" "${REPO}/agent/config" "${REPO}/agent/bin" "$FAKE/agent/" 2>/dev/null || true
for f in src/MailServer.php src/BindServer.php tests/run-tests.php tests/FakeCommandExecutor.php; do
  git -C "$REPO" show "${PRE}:agent/${f}" > "$FAKE/agent/${f}"
  git -C "$REPO" show "${PRE}:agent/${f}" > "$FAKE/.expect-pre-${f//\//_}"
  git -C "$REPO" show "${ERA}:agent/${f}" > "$FAKE/.expect-era-${f//\//_}"
done
RUN="$FAKE/agent/tests/run-tests.php"
MS="$FAKE/agent/src/MailServer.php"
BS="$FAKE/agent/src/BindServer.php"
tcount(){ grep -c '^test(' "$1" 2>/dev/null || true; }
mc(){ grep -c "$1" "$2" 2>/dev/null || true; }

echo "== reproduce: PRE state (mail-fix v1.0 era — live-parity bugs) =="
echo "  pre test blocks: $(tcount "$RUN")"
t "pre count 222 (tests pehle se current)" test "$(tcount "$RUN")" -eq 222
t "pre MailServer canChown nahi"   test "$(mc 'canChown' "$MS")" -eq 0
t "pre tests binsAbsent nahi"      test "$(mc 'binsAbsent' "$RUN")" -eq 0
t "pre BindServer probeBin nahi"   test "$(mc 'probeBin' "$BS")" -eq 0
t "pre MailServer self-healing hai (v1.0)" test "$(mc 'Self-healing verify' "$MS")" -ge 1
for f in src/MailServer.php src/BindServer.php tests/run-tests.php tests/FakeCommandExecutor.php; do
  t "pre byte-for-byte ${f}" bash -c "cmp -s '$FAKE/agent/${f}' '$FAKE/.expect-pre-${f//\//_}'"
done

echo "== --diagnose (pre) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose pre: canChown 0"     has "$D1" "live-parity canChown     : 0"
t "diagnose pre: binsAbsent 0"   has "$D1" "tests binsAbsent         : 0"
t "diagnose pre: probeBin 0"     has "$D1" "BindServer probeBin      : 0"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|suite|backup:|paneld|FINAL|✖' | sed 's/^/    /'
t "apply exit 0"                  test "$A1_RC" -eq 0
t "lint clean (4)"                has "$A1" "lint clean (4)"
t "suite GREEN 222"               has "$A1" "passed=222 failed=0"
t "backup bana"                   bash -c "ls -1d '$FAKE'/releases/mailfix-* >/dev/null 2>&1"
t "MailServer canChown gaya"      test "$(mc 'canChown' "$MS")" -ge 1
t "tests binsAbsent gaya"         test "$(mc 'binsAbsent' "$RUN")" -ge 1
t "BindServer probeBin gaya"      test "$(mc 'probeBin' "$BS")" -ge 1
for f in src/MailServer.php src/BindServer.php tests/run-tests.php tests/FakeCommandExecutor.php; do
  t "byte-for-byte era ${f}" bash -c "cmp -s '$FAKE/agent/${f}' '$FAKE/.expect-era-${f//\//_}'"
done

echo "== ROOT-RUN suite (live failure-mode ka direct proof) =="
if [[ "$(id -u)" -eq 0 ]]; then
  t "suite root par green"         env PATH="$(dirname "$PHPBIN"):$PATH" "$PHPBIN" "$RUN"
elif sudo -n true 2>/dev/null; then
  t "suite root (sudo) par green" sudo -n env PATH="$(dirname "$PHPBIN"):$PATH" "$PHPBIN" "$RUN"
else
  echo "  (sudo nahi — root-run skip; live installer root hi chalta hai)"
fi

echo "== --diagnose (post) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose post: canChown >=1"   hasre "$D2" "live-parity canChown     : [1-9]"
t "diagnose post: binsAbsent >=1" hasre "$D2" "tests binsAbsent         : [1-9]"
t "diagnose post: probeBin >=1"   hasre "$D2" "BindServer probeBin      : [1-9]"
t "diagnose post: blocks 222"     hasre "$D2" "test blocks              : 222"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"               test "$R1_RC" -eq 0
t "MailServer v1.0-era wapas"     bash -c "cmp -s '$MS' '$FAKE/.expect-pre-src_MailServer.php'"
t "tests v1.0-era wapas"          bash -c "cmp -s '$RUN' '$FAKE/.expect-pre-tests_run-tests.php'"
t "BindServer v1.0-era wapas"     bash -c "cmp -s '$BS' '$FAKE/.expect-pre-src_BindServer.php'"
t "canChown wapas 0"              test "$(mc 'canChown' "$MS")" -eq 0

echo "== re-apply =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"               test "$A2_RC" -eq 0
t "re-apply canChown >=1"         test "$(mc 'canChown' "$MS")" -ge 1
t "re-apply suite-count 222"      test "$(tcount "$RUN")" -eq 222

echo ""
echo "----------------------------------------"
echo "mail-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
