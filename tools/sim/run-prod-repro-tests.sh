#!/usr/bin/env bash
# Production-equivalent repro: snapshot + license-server + entry-gate alias +
# PRODUCTION layout (main se) + error-pages v2 → GET /login & GET / status.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${REPO}/server-snapshot/files/usr/local/alphacp/panel"
VENDOR="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)
W=/tmp/prodrepro
rm -rf "${W}"; mkdir -p "${W}/home" "${W}/bin"
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
# license-server (#28 live state)
LS="${REPO}/features/license-server"; LI="${REPO}/features/license"
cp "${LS}/app/Support/LicenseSigner.php" "${P}/app/Support/"
cp "${LS}/app/Models/LicenseKey.php" "${P}/app/Models/"
cp "${LS}/app/Http/Controllers/LicenseServerController.php" "${P}/app/Http/Controllers/"
cp "${LS}/resources/views/license-server/index.blade.php" "${P}/resources/views/license-server/"
cp "${LS}/database/migrations/2026_10_07_000004_create_license_keys_table.php" "${P}/database/migrations/"
cp "${LS}/database/migrations/2026_10_07_000011_add_payload_to_license_keys.php" "${P}/database/migrations/"
cat "${LS}/routes-license-server.php" >> "${P}/routes/web.php"
cp "${LI}/app/Support/License/LicenseClient.php" "${P}/app/Support/License/"
# entry-gate (#27 live state): alias + controller
cp "${REPO}/features/entrygate/app/Http/Controllers/Auth/EntryLoginController.php" "${P}/app/Http/Controllers/Auth/"
python3 - "${P}/routes/web.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
s=s.replace("use App\\Http\\Controllers\\Auth\\LoginController;","use App\\Http\\Controllers\\Auth\\EntryLoginController as LoginController;",1)
open(p,'w').write(s)
PY
# production layout (patched, main se)
cp /tmp/prod-layout.blade.php "${P}/resources/views/layouts/panel.blade.php"
# error-pages v2 (#29 live state)
printf '#!/usr/bin/env bash\necho "[stub php] $*"\n' > "${W}/bin/php"
printf '#!/usr/bin/env bash\necho "[stub sync]"\n' > "${W}/bin/alphacp-sync"
chmod +x "${W}/bin/"*
ACP_PANEL="${P}" PATH="${W}/bin:$PATH" bash "${REPO}/installer/error-pages.sh" >/dev/null || { echo INSTALLER-FAIL; exit 1; }
cat > "${P}/tests/Feature/ProdReproTest.php" <<'PHPEOF'
<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ProdReproTest extends TestCase
{
    use RefreshDatabase;
    public function test_statuses(): void
    {
        foreach (['/login', '/'] as $url) {
            $r = $this->get($url);
            $line = $url . ' => ' . $r->status();
            file_put_contents('/tmp/prodrepro/out.txt', $line . "\n", FILE_APPEND);
            if (isset($r->exceptions) && $r->exceptions) {
                $e = $r->exceptions[0];
                file_put_contents('/tmp/prodrepro/out.txt', '   EXC(' . $url . '): ' . get_class($e) . ': ' . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        $this->assertTrue(true);
    }
}
PHPEOF
cd "${P}" || exit 1
DB="${W}/t.sqlite"; rm -f "${DB}" "${W}/out.txt"; touch "${DB}"
PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" ACP_HOME="${W}/home" \
  timeout 600 "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result tests/Feature/ProdReproTest.php 2>&1 | tail -3
echo "=== OUT ==="; cat "${W}/out.txt" 2>/dev/null
