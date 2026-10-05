#!/usr/bin/env bash
# =============================================================================
#  AlphaCP panel — PHPUnit suite in this sandbox (no system PHP needed)
#
#  * Code   : artifacts/panel-code-<version>.tar.gz (default: newest) — exactly what the updater deploys
#  * vendor : artifacts/panel-bundle-0.3.0.tar.gz (composer.lock checked to be identical)
#  * PHP    : php-wasm (@php-wasm/cli, npm) — PHP 8.5 (8.4 wasm build crashes inside PHPUnit)
#  * DB     : SQLite file per test file (the suite uses RefreshDatabase)
#
#  Sandbox-only workarounds (applied to a TEMP copy, never to the repo):
#   - php-wasm crashes on Mockery's console-output mock -> $mockConsoleOutput=false in TestCase.
#     Tests that assert on PendingCommand (->assertSuccessful etc.) are reported as "wasm-skip".
#   - ConfigBootTest spawns a child `php` process -> not possible in wasm -> "wasm-skip".
#
#  Usage:  bash tools/sim/panel-tests.sh [artifacts/panel-code-X.Y.Z.tar.gz]
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ART="${1:-$(ls -1 "${REPO}"/artifacts/panel-code-*.tar.gz | sort -V | tail -1)}"
VENDOR_ART="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-$HOME/.tools/phpw}"   # /tmp har turn par saaf ho jata hai
W=/tmp/acp-paneltests
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)

rm -rf "${W}"; mkdir -p "${W}/home/agent/config"
tar xzf "${ART}" -C "${W}"
if [[ ! -f "${VENDOR_ART}" ]]; then
  unzip -q -o "${REPO}/alphacp-code-bundle.zip" artifacts/panel-bundle-0.3.0.tar.gz -d "${W}/z"; VENDOR_ART="${W}/z/artifacts/panel-bundle-0.3.0.tar.gz"
fi
if ! diff -q <(tar xzOf "${VENDOR_ART}" panel/composer.lock) "${W}/panel/composer.lock" >/dev/null; then
  echo "composer.lock badal gaya — vendor/ ko reuse nahi kar sakte (naya vendor bundle chahiye)"; exit 2
fi
tar xzf "${VENDOR_ART}" -C "${W}" panel/vendor
cp "${REPO}/agent/config/tasks.php" "${W}/home/agent/config/"
P="${W}/panel"
mkdir -p "${P}"/storage/app/private "${P}"/storage/framework/{cache/data,sessions,views} "${P}"/storage/logs "${P}"/bootstrap/cache
printf 'APP_NAME=AlphaCP\nAPP_ENV=testing\nAPP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\nAPP_DEBUG=true\nACP_CHECK_PWNED=false\n' > "${P}/.env"
python3 - "${P}/tests/TestCase.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("abstract class TestCase extends BaseTestCase\n{","abstract class TestCase extends BaseTestCase\n{\n    public $mockConsoleOutput = false; // SANDBOX ONLY\n",1)
open(p,'w').write(s)
PY

echo "Artifact : $(basename "${ART}")  sha256 $(sha256sum "${ART}" | cut -c1-16)…"
echo "PHP      : $(PHP=8.5 "${PHPW[@]}" -r 'echo PHP_VERSION;')  (php-wasm)"
echo
TOTAL_OK=0; TOTAL_FAIL=0; TOTAL_SKIP=0
cd "${P}" || exit 1
for f in tests/Unit/*.php tests/Feature/*.php; do
  DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
  OUT="$(PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
         timeout 600 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox "${f}" 2>&1)"
  # per-test lines from testdox: " ✔ name" / " ✘ name"
  while IFS= read -r line; do
    case "${line}" in
      *"✔"*) TOTAL_OK=$((TOTAL_OK+1)) ;;
      *"✘"*)
        name="${line#*✘ }"
        skip=0
        if [[ "${f}" == *ConfigBootTest* ]] && grep -q "Usage: php \[options\]" <<<"${OUT}"; then skip=1; fi   # child php process
        if [[ "${f}" == *AdminPasswordCommandTest* ]] && ! grep -q "generates a password" <<<"${line}" \
           && grep -qE "assert(Successful|Failed|ExitCode)\(\) on int" <<<"${OUT}"; then skip=1; fi       # PendingCommand mock
        if (( skip )); then
          TOTAL_SKIP=$((TOTAL_SKIP+1)); echo "  wasm-skip  ${f##*/} :: ${name}"
        else
          TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "  FAIL       ${f##*/} :: ${name}"
        fi ;;
    esac
  done <<<"${OUT}"
  if grep -q "RuntimeError: unreachable" <<<"${OUT}"; then TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "  CRASH      ${f##*/}"; fi
done
echo
echo "=== PANEL TESTS: ${TOTAL_OK} pass, ${TOTAL_FAIL} fail, ${TOTAL_SKIP} wasm-skip ==="
[[ ${TOTAL_FAIL} -eq 0 ]]
