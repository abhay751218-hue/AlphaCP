#!/usr/bin/env bash
# =============================================================================
#  b1-fix-sim — installer/b1-fix.sh (Git/Terminal/Apps via root agent) ka
#  end-to-end sandbox proof. Fake ACP_HOME ko LIVE ki maujooda state me lata hai
#  (commit 047d974 = ftp-fix ke baad: registry 83 types, git.* absent, panel
#  Git/Terminal/Apps `Process::` se → HTTP 500), phir:
#    1  reproduce: 83 types / git 0, panel Process::, suite NOT green
#    2  --diagnose read-only (MISSING, git.* 0, Process >0; kuch likhta nahi)
#    3  apply: backup → lint 10+4 → smoke → suite GREEN (passed>=218 failed=0)
#       → registry 89 → panel Process 0 / Paneld::run+enqueue present
#    4  --diagnose post (PRESENT, git.* 4, terminal+app 2)
#    5  --rollback (83 types + Process-wala panel wapas)
#    6  re-apply (idempotent)
#  Usage: bash tools/sim/b1-fix-sim.sh    (PHPBIN=/path/to/php override)
# =============================================================================
set -uo pipefail
# php-wasm `PHP` env ko version maanta hai — sim apna binary PHPBIN me rakhta hai.
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/b1-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

PRE="047d974"

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
notgreen(){ local s="$1"; [[ -z "$s" ]] && return 0; local n; n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"$s")"; [[ "$n" != "0" ]]; }

[[ -f "$FIX" ]] || { echo "installer/b1-fix.sh nahi mila — python3 tools/build-b1-fix.py chalao"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php8.4/php nahi mila (PHPBIN=... set karo)"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila (pre-state fixtures chahiye)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-b1fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/panel/app/Http/Controllers" "$FAKE/panel/app/Support"

# ---- agent: repo copy, phir PRE (live) registry/allowlist + b1 handlers hata do ----
cp -a "${REPO}/agent" "$FAKE/agent"
git -C "$REPO" show "${PRE}:agent/config/tasks.php"      > "$FAKE/agent/config/tasks.php"
git -C "$REPO" show "${PRE}:agent/src/CommandRunner.php" > "$FAKE/agent/src/CommandRunner.php"
rm -f "$FAKE/agent/src/Git.php" "$FAKE/agent/src/Tasks/GitTask.php" "$FAKE/agent/src/Tasks/GitList.php" \
      "$FAKE/agent/src/Tasks/GitClone.php" "$FAKE/agent/src/Tasks/GitPull.php" \
      "$FAKE/agent/src/Tasks/GitStatus.php" "$FAKE/agent/src/Tasks/TerminalRun.php" \
      "$FAKE/agent/src/Tasks/AppsInstall.php"

# ---- panel: PRE (Process-wale) controllers + AppInstaller ----
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
for f in app/Http/Controllers/GitController.php app/Http/Controllers/TerminalController.php app/Http/Controllers/AppsController.php app/Support/AppInstaller.php; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
done

suite(){ "$PHPBIN" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }
types_count(){ "$PHPBIN" -r '$t=require $argv[1]; echo count($t);' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
b1_types(){ "$PHPBIN" -r '$t=require $argv[1]; echo count(array_filter(array_keys($t), fn($k)=>str_starts_with($k,"git.")||$k==="terminal.run"||$k==="apps.install"));' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
grepc(){ grep -c "$@" 2>/dev/null || true; }
procfiles(){ grep -l 'Process::run\|Process::timeout' "$@" 2>/dev/null | wc -l; }

echo "== reproduce: live PRE state (83 types + Process-wale controllers) =="
PRE_S="$(suite)"
echo "  pre-fix suite   : ${PRE_S:-<fatal / no summary>}"
echo "  pre-fix registry: $(types_count) types, b1 = $(b1_types)"
t "pre-fix registry 83 types hai"             test "$(types_count)" -eq 83
t "pre-fix registry me koi b1 task NAHI"      test "$(b1_types)" -eq 0
t "pre-fix suite GREEN nahi (handlers missing)" notgreen "$PRE_S"
t "pre-fix agent/src/Git.php maujood nahi"    test ! -f "$FAKE/agent/src/Git.php"
t "pre-fix GitController Process chalata hai (500 ki jad)" test "$(procfiles "$FAKE/panel/app/Http/Controllers/GitController.php")" -eq 1
t "pre-fix TerminalController Process chalata hai" test "$(procfiles "$FAKE/panel/app/Http/Controllers/TerminalController.php")" -eq 1
t "pre-fix allowlist me /usr/bin/git nahi"    test "$(grepc "'/usr/bin/git'" "$FAKE/agent/src/CommandRunner.php")" -eq 0

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai"       has "$D1" "MISSING"
t "diagnose git.* 0 dikhata hai"       hasre "$D1" "git\.\*[[:space:]]*:[[:space:]]*0"
t "diagnose Git Process >0 dikhata hai" hasre "$D1" "GitController Process:[[:space:]]*[1-9]"
t "diagnose ne koi file nahi likhi"    test ! -f "$FAKE/agent/src/Git.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|SMOKE|smoke|suite|backup:|FINAL|APPLY ho|✖|⚠|·' | sed 's/^/    /'
t "apply exit 0"                        test "$A1_RC" -eq 0
t "agent src/Git.php likhi gayi"        test -f "$FAKE/agent/src/Git.php"
t "saare 7 b1 handlers likhe gaye"      bash -c "for f in GitTask GitList GitClone GitPull GitStatus TerminalRun AppsInstall; do test -f '$FAKE/agent/src/Tasks/'\"\$f\"'.php' || exit 1; done"
t "static smoke PASS hua"               has "$A1" "static smoke PASS"
t "agent lint clean (10) report hua"    has "$A1" "lint clean (10)"
t "panel lint clean (4) report hua"     has "$A1" "lint clean (4)"
t "backup bana"                         bash -c "ls -1d '$FAKE'/releases/b1fix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite   : ${POST}"
echo "  post-fix registry: $(types_count) types, b1 = $(b1_types)"
t "post-fix suite GREEN (failed=0)"     test "${POST_F:-9}" -eq 0
t "post-fix passed >= 218"              test "${POST_P:-0}" -ge 218
t "post-fix registry 89 types"          test "$(types_count)" -eq 89
t "post-fix registry me 6 b1 tasks"     test "$(b1_types)" -eq 6
t "post-fix allowlist me git+curl"      test "$(grepc -e "'/usr/bin/git'" -e "'/usr/bin/curl'" "$FAKE/agent/src/CommandRunner.php")" -ge 2
t "post-fix panel me koi Process:: nahi" test "$(procfiles "$FAKE/panel/app/Http/Controllers/GitController.php" "$FAKE/panel/app/Http/Controllers/TerminalController.php" "$FAKE/panel/app/Http/Controllers/AppsController.php" "$FAKE/panel/app/Support/AppInstaller.php")" -eq 0
t "post-fix GitController Paneld::run x2" test "$(grepc 'Paneld::run' "$FAKE/panel/app/Http/Controllers/GitController.php")" -ge 2
t "post-fix AppsController enqueue x1"  test "$(grepc 'AccountProvisioner::enqueue' "$FAKE/panel/app/Http/Controllers/AppsController.php")" -eq 1
t "embedded payload byte-for-byte (tasks.php)" bash -c "cmp -s '$FAKE/agent/config/tasks.php' '$REPO/agent/config/tasks.php'"
t "embedded payload byte-for-byte (GitController)" bash -c "cmp -s '$FAKE/panel/app/Http/Controllers/GitController.php' '$REPO/$PANEL_REL/app/Http/Controllers/GitController.php'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai"        has "$D2" "PRESENT"
t "diagnose git.* 4 dikhata hai"        hasre "$D2" "registry git\.\*[[:space:]]*:[[:space:]]*4"
t "diagnose terminal+app 2 dikhata hai" hasre "$D2" "terminal\+app:[[:space:]]*2"
t "diagnose Git Process 0 dikhata hai"  hasre "$D2" "GitController Process:[[:space:]]*0"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"                     test "$R1_RC" -eq 0
t "rollback ne src/Git.php hata di"     test ! -f "$FAKE/agent/src/Git.php"
t "rollback ne 83-type registry lautaya" test "$(types_count)" -eq 83
t "rollback ne Process-wala panel lautaya" test "$(procfiles "$FAKE/panel/app/Http/Controllers/GitController.php")" -eq 1
t "rollback ke baad suite phir GREEN nahi" notgreen "$(suite)"

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"                     test "$A2_RC" -eq 0
t "re-apply ke baad suite phir GREEN"   test "$(failed_count)" -eq 0
t "re-apply ke baad registry 89"        test "$(types_count)" -eq 89

echo ""
echo "----------------------------------------"
echo "b1-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
