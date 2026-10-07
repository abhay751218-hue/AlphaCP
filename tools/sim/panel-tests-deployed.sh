#!/usr/bin/env bash
# =============================================================================
#  AlphaCP panel — PHPUnit suite for the code that is ACTUALLY deployed
#                   (server-snapshot/files/usr/local/alphacp/panel)
#
#  tools/sim/panel-tests.sh runs the newest artifacts/panel-code-*.tar.gz.
#  This script runs the server snapshot instead, which is the ground truth
#  (alphacp-sync copies it off dev-srv1 every hour).
#
#  * vendor : artifacts/panel-bundle-0.3.0.tar.gz (composer.lock verified identical)
#  * PHP    : php-wasm (@php-wasm/cli, npm) — PHP 8.5 (8.4 wasm crashes in PHPUnit)
#  * DB     : one SQLite file per test file (the suite uses RefreshDatabase)
#
#  Sandbox-only workarounds (applied to a TEMP copy, never to the repo):
#   - php-wasm crashes on Mockery's console-output mock -> $mockConsoleOutput=false
#   - ConfigBootTest spawns a child `php` process -> "wasm-skip"
#   - AdminPasswordCommandTest asserts on PendingCommand -> "wasm-skip"
#
#  Usage:  bash tools/sim/panel-tests-deployed.sh [tests/Feature/XTest.php ...]
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
VENDOR_ART="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
SRC="${SRC:-${REPO}/server-snapshot/files/usr/local/alphacp/panel}"
AGENT_TASKS="${REPO}/server-snapshot/files/usr/local/alphacp/agent/config/tasks.php"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
W=/tmp/acp-paneltests-deployed

if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)

rm -rf "${W}"; mkdir -p "${W}/home/agent/config"
cp -a "${SRC}" "${W}/panel"
tar xzf "${VENDOR_ART}" -C "${W}" panel/vendor
cp "${AGENT_TASKS}" "${W}/home/agent/config/"
P="${W}/panel"
mkdir -p "${P}"/storage/app/private "${P}"/storage/framework/{cache/data,sessions,views} "${P}"/storage/logs "${P}"/bootstrap/cache
printf 'APP_NAME=AlphaCP\nAPP_ENV=testing\nAPP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\nAPP_DEBUG=true\nACP_CHECK_PWNED=false\n' > "${P}/.env"
python3 "${REPO}/tools/sim/_patch-testcase-sandbox.py" "${P}/tests/TestCase.php"

echo "Source   : server-snapshot panel $(python3 -c "import json,sys;print(json.load(open('${SRC}/MANIFEST.json'))['version'])" 2>/dev/null)"
echo "PHP      : $(PHP=8.5 "${PHPW[@]}" -r 'echo PHP_VERSION;')  (php-wasm)"
echo

TOTAL_OK=0; TOTAL_FAIL=0; TOTAL_SKIP=0; FAILED=()
cd "${P}" || exit 1
if [[ $# -gt 0 ]]; then FILES="$*"; else FILES="$(ls tests/Unit/*.php tests/Feature/*.php | grep -v TestCase.php)"; fi
for f in ${FILES}; do
  DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
  OUT="$(PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
        timeout 600 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox "${f}" 2>&1)"
  ok=0; fail=0; skip=0
  while IFS= read -r line; do
    case "${line}" in
      *"✔"*) ok=$((ok+1)); TOTAL_OK=$((TOTAL_OK+1)) ;;
      *"✘"*)
        name="${line#*✘ }"
        skip=0
        if [[ "${f}" == *ConfigBootTest* ]] && grep -q "Usage: php \[options\]" <<<"${OUT}"; then skip=1; fi
        if [[ "${f}" == *AdminPasswordCommandTest* ]] && ! grep -q "generates a password" <<<"${line}" \
           && grep -qE "assert(Successful|Failed|ExitCode)\(\) on int" <<<"${OUT}"; then skip=1; fi
        if (( skip )); then
          skip=1; TOTAL_SKIP=$((TOTAL_SKIP+1)); echo "  wasm-skip  ${f##*/} :: ${name}"
        else
          fail=$((fail+1)); TOTAL_FAIL=$((TOTAL_FAIL+1)); FAILED+=("${f} :: ${name}")
        fi ;;
    esac
  done <<<"${OUT}"
  if grep -q "RuntimeError: unreachable" <<<"${OUT}"; then
    TOTAL_FAIL=$((TOTAL_FAIL+1)); FAILED+=("${f} :: CRASH")
  fi
  if (( ok == 0 && fail == 0 && skip == 0 )); then
    TOTAL_FAIL=$((TOTAL_FAIL+1)); FAILED+=("${f} :: NO-TESTS-RAN")
    echo "${OUT}" > "/tmp/acp-err_$(basename "${f}" .php).log"
  fi
  if (( fail > 0 )); then echo "${OUT}" > "/tmp/acp-fail_$(basename "${f}" .php).log"; fi
  printf "%-42s %3d ok %3d fail\n" "${f##*/}" "${ok}" "${fail}"
done
echo
echo "=== PANEL TESTS (deployed snapshot): ${TOTAL_OK} pass, ${TOTAL_FAIL} fail, ${TOTAL_SKIP} wasm-skip ==="
if (( ${#FAILED[@]} > 0 )); then echo "--- PROBLEMS ---"; printf '%s\n' "${FAILED[@]}"; fi
[[ ${TOTAL_FAIL} -eq 0 ]]
