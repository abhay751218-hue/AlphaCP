#!/usr/bin/env bash
# Overlay-run sirf FileXtrasTest — base 0.3.2 + vendor, php-wasm.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ART="${REPO}/artifacts/panel-code-0.3.2.tar.gz"
VENDOR="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)
W=/tmp/filextras-overlay
rm -rf "${W}"; mkdir -p "${W}/home"
tar xzf "${ART}" -C "${W}"
tar xzf "${VENDOR}" -C "${W}" panel/vendor
P="${W}/panel"
mkdir -p "${P}"/storage/app/private "${P}"/storage/framework/{cache/data,sessions,views} "${P}"/storage/logs "${P}"/bootstrap/cache
printf 'APP_NAME=AlphaCP\nAPP_ENV=testing\nAPP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\nAPP_DEBUG=true\nACP_CHECK_PWNED=false\n' > "${P}/.env"
python3 - "${P}/tests/TestCase.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("abstract class TestCase extends BaseTestCase\n{","abstract class TestCase extends BaseTestCase\n{\n    public $mockConsoleOutput = false; // SANDBOX ONLY\n",1)
open(p,'w').write(s)
PY
# ---- overlay: filextras feature ----
F="${REPO}/features/filextras"
mkdir -p "${P}/resources/views/optimize" "${P}/resources/views/images" "${P}/resources/views/trash"
cp "${F}/app/Models/OptimizeSetting.php" "${P}/app/Models/"
cp "${F}/app/Http/Controllers/OptimizeController.php" "${P}/app/Http/Controllers/"
cp "${F}/app/Http/Controllers/ImagesController.php" "${P}/app/Http/Controllers/"
cp "${F}/app/Http/Controllers/TrashController.php" "${P}/app/Http/Controllers/"
cp "${F}/resources/views/optimize/index.blade.php" "${P}/resources/views/optimize/"
cp "${F}/resources/views/images/index.blade.php" "${P}/resources/views/images/"
cp "${F}/resources/views/trash/index.blade.php" "${P}/resources/views/trash/"
cp "${F}/database/migrations/2026_10_07_000008_create_optimize_settings_table.php" "${P}/database/migrations/"
cp "${F}/tests/Feature/FileXtrasTest.php" "${P}/tests/Feature/"
cat "${F}/routes-filextras.php" >> "${P}/routes/web.php"
cd "${P}" || exit 1
DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
  timeout 600 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox tests/Feature/FileXtrasTest.php 2>&1
