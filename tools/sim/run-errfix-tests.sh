#!/usr/bin/env bash
# GuestErrorPageTest — base = FULL server-snapshot app + vendor bundle.
# PATCH=1 (default): installer/guest-errors.sh ASLI me chala kar patch lagata hai.
# PATCH=0          : bina patch — bug reproduce karne ke liye.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${REPO}/server-snapshot/files/usr/local/alphacp/panel"
VENDOR="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)
W=/tmp/errfix-overlay
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
# stub php + alphacp-sync (sandbox me php binary nahi)
printf '#!/usr/bin/env bash\necho "[stub php] $*"\n' > "${W}/bin/php"
printf '#!/usr/bin/env bash\necho "[stub alphacp-sync]"\n' > "${W}/bin/alphacp-sync"
chmod +x "${W}/bin/"*

if [[ "${PATCH:-1}" == "1" ]]; then
  echo "--- installer/guest-errors.sh chala rahe hain (real script) ---"
  ACP_PANEL="${P}" PATH="${W}/bin:$PATH" bash "${REPO}/installer/guest-errors.sh" || { echo "INSTALLER FAIL"; exit 1; }
else
  echo "--- PATCH=0: bina patch (bug reproduce) ---"
fi

cp "${REPO}/features/errfix/tests/Feature/GuestErrorPageTest.php" "${P}/tests/Feature/"
cd "${P}" || exit 1
DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
  timeout 900 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox tests/Feature/GuestErrorPageTest.php 2>&1
