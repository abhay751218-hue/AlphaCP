#!/usr/bin/env bash
# LicenseServerTest + LicensePlanFlowTest — base = FULL server-snapshot + vendor.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${REPO}/server-snapshot/files/usr/local/alphacp/panel"
VENDOR="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)
W=/tmp/licenseplan-overlay
rm -rf "${W}"; mkdir -p "${W}/home"
cp -a "${SRC}" "${W}/panel"
tar xzf "${VENDOR}" -C "${W}" panel/vendor
P="${W}/panel"
mkdir -p "${P}"/storage/app/private "${P}"/storage/framework/{cache/data,sessions,views} "${P}"/storage/logs "${P}"/bootstrap/cache "${P}"/app/Support/License "${P}"/resources/views/license-server
printf 'APP_NAME=AlphaCP\nAPP_ENV=testing\nAPP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\nAPP_DEBUG=true\nACP_CHECK_PWNED=false\n' > "${P}/.env"
python3 - "${P}/tests/TestCase.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("abstract class TestCase extends BaseTestCase\n{","abstract class TestCase extends BaseTestCase\n{\n    public $mockConsoleOutput = false; // SANDBOX ONLY\n",1)
open(p,'w').write(s)
PY
LS="${REPO}/features/license-server"
LI="${REPO}/features/license"
cp "${LS}/app/Support/LicenseSigner.php" "${P}/app/Support/"
cp "${LS}/app/Models/LicenseKey.php" "${P}/app/Models/"
cp "${LS}/app/Http/Controllers/LicenseServerController.php" "${P}/app/Http/Controllers/"
cp "${LS}/resources/views/license-server/index.blade.php" "${P}/resources/views/license-server/"
cp "${LS}/database/migrations/2026_10_07_000004_create_license_keys_table.php" "${P}/database/migrations/"
cp "${LS}/database/migrations/2026_10_07_000011_add_payload_to_license_keys.php" "${P}/database/migrations/"
cat "${LS}/routes-license-server.php" >> "${P}/routes/web.php"
cp "${LI}/app/Support/License/LicenseClient.php" "${P}/app/Support/License/"
cp "${LS}/tests/Feature/LicenseServerTest.php" "${P}/tests/Feature/"
cp "${LS}/tests/Feature/LicensePlanFlowTest.php" "${P}/tests/Feature/"
cd "${P}" || exit 1
DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
  timeout 900 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox tests/Feature/LicenseServerTest.php tests/Feature/LicensePlanFlowTest.php 2>&1
