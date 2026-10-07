#!/usr/bin/env bash
# ErrorPagesStandaloneTest + GuestErrorPageTest — base = FULL server-snapshot.
# Worst-case proof: layout ko JAAN-BOOJH kar break karo (unsafe auth call),
# phir error-pages installer chalao — error pages phir bhi render honi chahiye.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${REPO}/server-snapshot/files/usr/local/alphacp/panel"
VENDOR="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)
W=/tmp/errorpages-overlay
rm -rf "${W}"; mkdir -p "${W}/home" "${W}/bin"
cp -a "${SRC}" "${W}/panel"
tar xzf "${VENDOR}" -C "${W}" panel/vendor
P="${W}/panel"
mkdir -p "${P}"/storage/app/private "${P}"/storage/framework/{cache/data,sessions,views} "${P}"/storage/logs "${P}"/bootstrap/cache
printf 'APP_NAME=AlphaCP\nAPP_ENV=testing\nAPP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\nAPP_DEBUG=true\nACP_CHECK_PWNED=false\n' > "${P}/.env"
python3 - "${P}/tests/TestCase.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("abstract class TestCase extends BaseTestCase\n{","abstract class TestCase extends BaseTestCase\n{\n    public $mockConsoleOutput = false; // SANDBOX ONLY\n",1)
open(p,'w').write(s)
PY
# --- layout ko worst-case broken banao (production-jaisa crash scenario) ---
python3 - "${P}/resources/views/layouts/panel.blade.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("auth()->user()?->username","auth()->user()->username")
open(p,'w').write(s)
PY
printf '#!/usr/bin/env bash\necho "[stub php] $*"\n' > "${W}/bin/php"
printf '#!/usr/bin/env bash\necho "[stub alphacp-sync]"\n' > "${W}/bin/alphacp-sync"
chmod +x "${W}/bin/"*

echo "--- installer/error-pages.sh (real script, broken layout ke SAATH) ---"
ACP_PANEL="${P}" PATH="${W}/bin:$PATH" bash "${REPO}/installer/error-pages.sh" || { echo "INSTALLER FAIL"; exit 1; }

cp "${REPO}/features/errfix/tests/Feature/ErrorPagesStandaloneTest.php" "${P}/tests/Feature/"
cp "${REPO}/features/errfix/tests/Feature/GuestErrorPageTest.php" "${P}/tests/Feature/"
cd "${P}" || exit 1
DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
  timeout 900 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox tests/Feature/ErrorPagesStandaloneTest.php tests/Feature/GuestErrorPageTest.php 2>&1
