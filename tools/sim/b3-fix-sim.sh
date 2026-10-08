#!/usr/bin/env bash
# =============================================================================
#  b3-fix-sim — installer/b3-fix.sh (WebDisk via root agent) ka
#  end-to-end sandbox proof. Fake ACP_HOME ko LIVE ki maujooda state me lata hai
#  (commit 2e9bc49 = b2-fix ke baad: registry 96 types, webdisk.* absent, panel
#  WebDiskController sirf DB-row-wala), phir:
#    1  reproduce: 96 types / webdisk 0, DB-only controller, suite NOT green
#    2  --diagnose read-only (MISSING, webdisk 0, Paneld 0; kuch likhta nahi)
#    3  apply: backup → lint 7+2 → smoke → suite GREEN (passed>=222 failed=0)
#       → registry 99 → controller Paneld::run / view password / routes {login}
#    4  --diagnose post (PRESENT, webdisk 1, Paneld >=1)
#    5  --rollback (96 types + DB-only controller wapas)
#    6  re-apply (idempotent)
#  Usage: bash tools/sim/b3-fix-sim.sh    (PHPBIN=/path/to/php override)
# =============================================================================
set -uo pipefail
# php-wasm `PHP` env ko version maanta hai — sim apna binary PHPBIN me rakhta hai.
unset PHP 2>/dev/null || true

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/b3-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

PRE="2e9bc49"   # b2-fix commit = live ki maujooda state (pre-B3)
ERA="ee6b659"   # B3 code commit = payloads + test-suite ka pin

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
notgreen(){ local s="$1"; [[ -z "$s" ]] && return 0; local n; n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"$s")"; [[ "$n" != "0" ]]; }

[[ -f "$FIX" ]] || { echo "installer/b3-fix.sh nahi mila — python3 tools/build-b3-fix.py chalao"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php8.4/php nahi mila (PHPBIN=... set karo)"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "git nahi mila (pre-state fixtures chahiye)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-b3fix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/panel/app/Http/Controllers" \
         "$FAKE/panel/routes" "$FAKE/panel/resources/views/webdisk"

# ---- agent: repo copy, phir PRE (live) registry/hooks + webdisk files hata do ----
cp -a "${REPO}/agent" "$FAKE/agent"
git -C "$REPO" show "${PRE}:agent/config/tasks.php"      > "$FAKE/agent/config/tasks.php"
git -C "$REPO" show "${PRE}:agent/src/AccountOs.php"     > "$FAKE/agent/src/AccountOs.php"
git -C "$REPO" show "${PRE}:agent/src/AccountPaths.php"  > "$FAKE/agent/src/AccountPaths.php"
rm -f "$FAKE/agent/src/WebDisk.php" "$FAKE/agent/src/Tasks/WebDiskList.php" \
      "$FAKE/agent/src/Tasks/WebDiskCreate.php" "$FAKE/agent/src/Tasks/WebDiskDelete.php"

# ---- panel: PRE (DB-only) controller + routes + view ----
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
# test-suite ARTIFACT-era (ee6b659) se pin — post-gate usi suite par chalta hai.
git -C "$REPO" show "${ERA}:agent/tests/run-tests.php"           > "$FAKE/agent/tests/run-tests.php"
git -C "$REPO" show "${ERA}:agent/tests/FakeCommandExecutor.php" > "$FAKE/agent/tests/FakeCommandExecutor.php"
for f in app/Http/Controllers/WebDiskController.php routes/web.php resources/views/webdisk/index.blade.php; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}"
done

suite(){ "$PHPBIN" "$FAKE/agent/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1; }
failed_count(){ suite | sed -E 's/.*failed: ([0-9]+).*/\1/'; }
passed_count(){ suite | sed -E 's/.*passed: ([0-9]+).*/\1/ ; s/ .*//'; }
types_count(){ "$PHPBIN" -r '$t=require $argv[1]; echo count($t);' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
wd_types(){ "$PHPBIN" -r '$t=require $argv[1]; echo count(array_filter(array_keys($t), fn($k)=>str_starts_with($k,"webdisk.")));' "$FAKE/agent/config/tasks.php" 2>/dev/null || echo 0; }
grepc(){ grep -c "$@" 2>/dev/null || true; }
allwd(){ local f; for f in WebDiskList WebDiskCreate WebDiskDelete; do [[ -f "$FAKE/agent/src/Tasks/$f.php" ]] || return 1; done; }

echo "== reproduce: live PRE state (96 types + DB-only WebDisk) =="
PRE_S="$(suite)"
echo "  pre-fix suite   : ${PRE_S:-<fatal / no summary>}"
echo "  pre-fix registry: $(types_count) types, webdisk = $(wd_types)"
t "pre-fix registry 96 types hai"              test "$(types_count)" -eq 96
t "pre-fix registry me webdisk.* NAHI"         test "$(wd_types)" -eq 0
t "pre-fix suite GREEN nahi (webdisk missing)" notgreen "$PRE_S"
t "pre-fix src/WebDisk.php maujood nahi"       test ! -f "$FAKE/agent/src/WebDisk.php"
t "pre-fix controller DB-only (Paneld::run 0)" test "$(grepc 'Paneld::run' "$FAKE/panel/app/Http/Controllers/WebDiskController.php")" -eq 0
t "pre-fix view me password field nahi"        test "$(grepc 'name="password"' "$FAKE/panel/resources/views/webdisk/index.blade.php")" -eq 0
t "pre-fix AccountOs me webdisk hook nahi"     test "$(grepc 'function ensureWebDiskInclude' "$FAKE/agent/src/AccountOs.php")" -eq 0

echo "== --diagnose (read-only, fix se pehle) =="
D1="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose MISSING dikhata hai"       has "$D1" "MISSING"
t "diagnose webdisk 0 dikhata hai"     hasre "$D1" "registry webdisk[[:space:]]*:[[:space:]]*0"
t "diagnose controller Paneld 0"       hasre "$D1" "controller Paneld[[:space:]]*:[[:space:]]*0"
t "diagnose ne koi file nahi likhi"    test ! -f "$FAKE/agent/src/WebDisk.php"

echo "== APPLY =="
A1="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint|SMOKE|smoke|suite|backup:|FINAL|APPLY ho|✖|⚠|·' | sed 's/^/    /'
t "apply exit 0"                         test "$A1_RC" -eq 0
t "agent src/WebDisk.php likhi gayi"     test -f "$FAKE/agent/src/WebDisk.php"
t "webdisk task handlers likhe gaye"     allwd
t "static smoke PASS hua"                has "$A1" "static smoke PASS"
t "agent lint clean (7) report hua"      has "$A1" "lint clean (7)"
t "panel lint clean (2) report hua"      has "$A1" "lint clean (2)"
t "backup bana"                          bash -c "ls -1d '$FAKE'/releases/b3fix-* >/dev/null 2>&1"
POST="$(suite)"; POST_F="$(failed_count)"; POST_P="$(passed_count)"
echo "  post-fix suite   : ${POST}"
echo "  post-fix registry: $(types_count) types, webdisk = $(wd_types)"
t "post-fix suite GREEN (failed=0)"      test "${POST_F:-9}" -eq 0
t "post-fix passed >= 222"               test "${POST_P:-0}" -ge 222
t "post-fix registry 99 types"           test "$(types_count)" -eq 99
t "post-fix registry me 3 webdisk types" test "$(wd_types)" -eq 3
t "post-fix AccountOs webdisk hook"      test "$(grepc 'function ensureWebDiskInclude' "$FAKE/agent/src/AccountOs.php")" -ge 1
t "post-fix controller Paneld::run"      test "$(grepc 'Paneld::run' "$FAKE/panel/app/Http/Controllers/WebDiskController.php")" -ge 1
t "post-fix view me password field"      test "$(grepc 'name="password"' "$FAKE/panel/resources/views/webdisk/index.blade.php")" -ge 1
t "post-fix routes me webdisk/{login}"   test "$(grepc 'webdisk/{login}' "$FAKE/panel/routes/web.php")" -ge 1
git -C "$REPO" show "${ERA}:agent/config/tasks.php" > "$FAKE/.expect-tasks.php"
t "embedded payload byte-for-byte (tasks.php, era)" bash -c "cmp -s '$FAKE/agent/config/tasks.php' '$FAKE/.expect-tasks.php'"
git -C "$REPO" show "${ERA}:${PANEL_REL}/app/Http/Controllers/WebDiskController.php" > "$FAKE/.expect-wc.php"
t "embedded payload byte-for-byte (WebDiskController, era)" bash -c "cmp -s '$FAKE/panel/app/Http/Controllers/WebDiskController.php' '$FAKE/.expect-wc.php'"
git -C "$REPO" show "${ERA}:${PANEL_REL}/resources/views/webdisk/index.blade.php" > "$FAKE/.expect-view.php"
t "embedded payload byte-for-byte (view blade, era)" bash -c "cmp -s '$FAKE/panel/resources/views/webdisk/index.blade.php' '$FAKE/.expect-view.php'"

echo "== --diagnose (fix ke baad) =="
D2="$(ACP_HOME="$FAKE" bash "$FIX" --diagnose 2>&1)"
t "diagnose PRESENT dikhata hai"        has "$D2" "PRESENT"
t "diagnose webdisk 1 dikhata hai"      hasre "$D2" "registry webdisk[[:space:]]*:[[:space:]]*1"
t "diagnose controller Paneld >=1"      hasre "$D2" "controller Paneld[[:space:]]*:[[:space:]]*[1-9]"
t "diagnose view password >=1"          hasre "$D2" "view password field[[:space:]]*:[[:space:]]*[1-9]"

echo "== --rollback =="
R1="$(ACP_HOME="$FAKE" bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"                      test "$R1_RC" -eq 0
t "rollback ne src/WebDisk.php hata di"  test ! -f "$FAKE/agent/src/WebDisk.php"
t "rollback ne 96-type registry lautaya" test "$(types_count)" -eq 96
t "rollback ne DB-only controller lautaya" test "$(grepc 'Paneld::run' "$FAKE/panel/app/Http/Controllers/WebDiskController.php")" -eq 0
t "rollback ke baad suite phir GREEN nahi" notgreen "$(suite)"

echo "== re-apply (idempotent) =="
A2="$(ACP_HOME="$FAKE" bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"                      test "$A2_RC" -eq 0
t "re-apply ke baad suite phir GREEN"    test "$(failed_count)" -eq 0
t "re-apply ke baad registry 99"         test "$(types_count)" -eq 99

echo ""
echo "----------------------------------------"
echo "b3-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
