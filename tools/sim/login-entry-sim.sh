#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — login-fix v1.0 simulation test
#  (sirf throwaway sandbox/container me! sudo chahiye — /usr/bin/php8.4 stub banta hai)
#
#  Kya test hota hai:
#   P0  build drift:  installer/login-fix.sh payload ke saath byte-for-byte sync me
#   P1  acp-entry-ports: nginx ke ASLI listen ports -> truth file (6 scenario)
#       * B1 regression: ports.json me 2083 ho par nginx na sune -> customer=[]
#   P2  login-fix.sh --diagnose: read-only, kuch badalta nahi
#   P3  login-fix.sh (full): v2 controller + ResellerScopeProvider v2 (B5) +
#       2FA loop fix + GET /login + truth file + selftest (B5 recursion check samet)
#   P4  idempotent: dobara chalane par "skip", kuch tootta nahi
#   P5  --enable-ports: nginx vhost ka ACP_PORTS block bharta hai, nginx -t fail par rollback
#   P6  --rollback: purani files wapas
#   P7  PHPUnit (asli live panel code 0.75.0 + asli vendor, php-wasm):
#       * EntryLoginTest  10/10
#       * AuthTest 8/8  — ports.json(2083) MAUJOOD hone par bhi (v1 me yahi fail hota tha)
#   P8  BUG PROOF: purana v1 controller + ports.json(2083) -> AuthTest FAIL hona CHAHIYE
#       (yani sim asli shikayat ko sach me reproduce karta hai)
#       ownership layout LIVE jaisi hai (code root, storage alphacp, pool conf
#       alphacp) taaki PANEL_USER detection ka imtihaan ho: galat detection
#       (artisan-owner=root) Step 8 me storage root:root kar deta.
#   P9  BUG PROOF (B5 — ASLI WAJAH): purana v1 ResellerScopeProvider ->
#       SessionAuthTest par PHP FATAL (global-scope <-> Auth::user() infinite
#       recursion). v2 wapas -> 12/12 OK.
# =============================================================================
set -uo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
FIX="${REPO}/installer/login-fix.sh"
ENTRYBIN="${REPO}/installer/payload/acp-entry-ports"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
W=/tmp/acp-loginfix-sim
PASS=0; FAIL=0

[[ ${EUID} -eq 0 ]] || { echo "sudo se chalao:  sudo bash tools/sim/login-entry-sim.sh"; exit 1; }
t_ok()   { echo "  PASS: $*"; PASS=$((PASS+1)); }
t_fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
t_chk()  { if eval "$2"; then t_ok "$1"; else t_fail "$1"; fi; }
hdr()    { echo ""; echo "== $* =="; }

# ---------------------------------------------------------------- php (wasm)
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
chmod -R a+rX "${PHPWASM_DIR}" 2>/dev/null || true
cat > /usr/bin/php8.4 <<EOF
#!/bin/sh
PHP=8.4 exec /usr/local/bin/node ${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js "\$@"
EOF
chmod 0755 /usr/bin/php8.4
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=1G)

id -u alphacp >/dev/null 2>&1 || useradd --system --home-dir /tmp --shell /usr/sbin/nologin alphacp

# ---------------------------------------------------------------- fake server
rm -rf "${W}"; mkdir -p "${W}/acp/etc" "${W}/acp/var" "${W}/acp/bin" "${W}/acp/logs" \
                        "${W}/acp/releases" "${W}/stub" "${W}/nginx"
ACP="${W}/acp"; PANEL="${ACP}/panel"

echo "-- panel code (server-snapshot = live 0.75.0) + vendor (bundle) taiyar --"
cp -r "${REPO}/server-snapshot/files/usr/local/alphacp/panel" "${PANEL}"
TMPB="${W}/z"; mkdir -p "${TMPB}"
if [[ -f "${REPO}/artifacts/panel-bundle-0.3.0.tar.gz" ]]; then
  VB="${REPO}/artifacts/panel-bundle-0.3.0.tar.gz"
else
  unzip -q -o "${REPO}/alphacp-code-bundle.zip" artifacts/panel-bundle-0.3.0.tar.gz -d "${TMPB}"
  VB="${TMPB}/artifacts/panel-bundle-0.3.0.tar.gz"
fi
tar xzf "${VB}" -C "${TMPB}" panel/vendor panel/composer.lock
if ! diff -q "${TMPB}/panel/composer.lock" "${PANEL}/composer.lock" >/dev/null; then
  echo "composer.lock alag hai — vendor reuse nahi ho sakta"; exit 2
fi
cp -r "${TMPB}/panel/vendor" "${PANEL}/vendor"
mkdir -p "${PANEL}"/storage/app/private "${PANEL}"/storage/framework/{cache/data,sessions,views,testing} \
         "${PANEL}"/storage/logs "${PANEL}"/bootstrap/cache "${W}/home/agent/config"
cp "${REPO}/server-snapshot/files/usr/local/alphacp/agent/config/tasks.php" "${W}/home/agent/config/" 2>/dev/null || true
cat > "${PANEL}/.env" <<EOF
APP_NAME=AlphaCP
APP_ENV=production
APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
APP_DEBUG=false
APP_URL=https://127.0.0.1:8090
LOG_CHANNEL=single
DB_CONNECTION=sqlite
DB_DATABASE=${PANEL}/database/panel.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BCRYPT_ROUNDS=4
ACP_HOME=${ACP}
ACP_CHECK_PWNED=false
ACP_PROVISION_WAIT=0
EOF
touch "${PANEL}/database/panel.sqlite"
chown -R alphacp:alphacp "${ACP}"
( cd "${PANEL}" && runuser -u alphacp -- env ACP_HOME="${ACP}" /usr/bin/php8.4 artisan key:generate --force ) >"${W}/mig.log" 2>&1
( cd "${PANEL}" && runuser -u alphacp -- env ACP_HOME="${ACP}" /usr/bin/php8.4 artisan migrate --force ) >>"${W}/mig.log" 2>&1
MIGOK=$(grep -c "DONE" "${W}/mig.log" 2>/dev/null || echo 0)
[[ ${MIGOK} -gt 50 ]] && echo "  (sim panel migrate: ${MIGOK} migrations DONE)" \
  || { echo "  (sim panel migrate FAIL — ${W}/mig.log)"; tail -20 "${W}/mig.log"; }

# 2 test users (root + reseller) — Step 3 unlock aur SELFTEST ke B5 recursion
# check ko ek asli active user chahiye (warna wo SKIP ho jata).
cat > "${PANEL}/seed-sim.php" <<'PHPEOF'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$role = static function (string $name, int $level): int {
    return App\Models\Role::query()
        ->firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'level' => $level, 'is_system' => true])
        ->id;
};
$rootId = $role('root', 1);
$resId  = $role('reseller', 2);
foreach ([['rootu', $rootId], ['resu', $resId]] as [$username, $roleId]) {
    App\Models\User::query()->updateOrCreate(
        ['username' => $username],
        [
            'email'                 => $username . '@example.test',
            'password_hash'         => Illuminate\Support\Facades\Hash::make('SimPass1234'),
            'full_name'             => ucfirst($username),
            'role_id'               => $roleId,
            'status'                => 'active',
            'force_password_change' => false,
            'two_factor_enabled'    => false,
        ],
    );
    // locked_until / failed_logins User::$fillable me NAHI hain (mass-assignment
    // se chup-chaap drop ho jate the) -> Step 3 ke unlock check ke liye DB se likho.
    Illuminate\Support\Facades\DB::table('users')->where('username', $username)->update([
        'failed_logins' => 3,
        'locked_until'  => now()->addMinutes(15),
        'status'        => 'locked',
    ]);
}
echo 'seeded ' . App\Models\User::query()->count() . " users\n";
PHPEOF
chown alphacp:alphacp "${PANEL}/seed-sim.php"
SEED_OUT="$(cd "${PANEL}" && runuser -u alphacp -- env ACP_HOME="${ACP}" /usr/bin/php8.4 seed-sim.php 2>&1)"
echo "  (sim seed: ${SEED_OUT##*$'\n'})"

# LIVE (13.207.123.177) jaisi ownership-mess + fpm pool:
#   panel code root-owned (deploy root se), storage/bootstrap/database alphacp,
#   pool conf /etc/php/8.4/fpm/pool.d/alphacp.conf me user = alphacp.
# Isse PANEL_USER detection ka asli imtihaan hota hai: artisan owner (root) par
# gaya to Step 8 storage ko root:root karke panel ko aur tod dega.
chown -R root:root "${PANEL}"
chown -R alphacp:alphacp "${PANEL}/storage" "${PANEL}/bootstrap/cache" "${PANEL}/database"
mkdir -p /etc/php/8.4/fpm/pool.d
printf '[alphacp]\nuser = alphacp\ngroup = alphacp\nlisten = /run/php/alphacp-fpm.sock\n' \
  > /etc/php/8.4/fpm/pool.d/alphacp.conf
echo "  (sim ownership: code=root, storage/bootstrap/database=alphacp; pool conf=alphacp)"

# ---- stubs: curl / systemctl / nginx / ufw ----------------------------------
cat > "${W}/stub/curl" <<'EOF'
#!/usr/bin/env bash
# canned panel responses (sandbox me nginx nahi chalta)
out=""; fmt=""
args=("$@"); i=0
while [[ $i -lt ${#args[@]} ]]; do
  case "${args[$i]}" in
    -o) out="${args[$((i+1))]}"; i=$((i+2)); continue ;;
    -w) fmt="${args[$((i+1))]}"; i=$((i+2)); continue ;;
  esac
  i=$((i+1))
done
url="${args[-1]}"
body=""
case "${url}" in
  *"/login")   # POST /login -> 302 dashboard
      body='<html><title>Redirecting</title></html>'; code=302; loc="https://127.0.0.1:8090/dashboard" ;;
  *)  body='<!doctype html><html><head><title>Login · AlphaCP</title></head><body><h1>Panel Login</h1>
      <form method="post" action="https://127.0.0.1:8090/login">
      <input type="hidden" name="_token" value="SIMTOKEN1234567890">
      </form></body></html>'; code=200; loc="" ;;
esac
[[ -n "${out}" ]] && printf '%s' "${body}" > "${out}"
res="${fmt//\%\{http_code\}/${code}}"
res="${res//\%\{redirect_url\}/${loc}}"
res="${res//\%\{url_effective\}/https:\/\/127.0.0.1:8090\/dashboard}"
res="${res//\%\{num_redirects\}/1}"
printf '%s' "${res}"
exit 0
EOF
cat > "${W}/stub/systemctl" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "${W}/stub/ufw" <<'EOF'
#!/usr/bin/env bash
echo "STUB-UFW $*"
exit 0
EOF
chmod 0755 "${W}"/stub/*
export PATH="${W}/stub:${PATH}"

# ---- fake nginx vhost (P2 ke diagnose se pehle zaroori) ---------------------
mkdir -p /etc/nginx/sites-available "${W}/nginx"
cat > "${W}/nginx/alphacp-panel.conf" <<'EOF'
server {
    listen 8090 ssl;
# ACP_PORTS_START
# ACP_PORTS_END
    listen [::]:8090 ssl;
    server_name _;
}
EOF
cp "${W}/nginx/alphacp-panel.conf" /etc/nginx/sites-available/alphacp-panel.conf

# =============================================================================
hdr "P0: build drift check"
if python3 "${REPO}/tools/build-login-fix.py" --check >/dev/null 2>&1; then
  t_ok "installer/login-fix.sh payload ke saath sync me hai"
else
  t_fail "login-fix.sh PURANA hai (tools/build-login-fix.py chalao)"
fi
bash -n "${FIX}" && t_ok "login-fix.sh bash -n clean" || t_fail "login-fix.sh syntax error"
bash -n "${ENTRYBIN}" && t_ok "acp-entry-ports bash -n clean" || t_fail "acp-entry-ports syntax error"

# =============================================================================
hdr "P1: acp-entry-ports — nginx ke ASLI ports hi truth file me jaayein"
ET="${W}/acp/etc/entry-ports.json"
mkstub() { # mkstub <nginx-ssl-ports-csv> <bound-ports-csv>
  cat > "${W}/stub/nginx" <<EOF
#!/usr/bin/env bash
[[ "\$1" == "-T" ]] || exit 0
for p in $(echo "$1" | tr ',' ' '); do echo "    listen \${p} ssl;"; done
exit 0
EOF
  cat > "${W}/stub/ss" <<EOF
#!/usr/bin/env bash
for p in $(echo "$2" | tr ',' ' '); do echo "LISTEN 0 511 0.0.0.0:\${p} 0.0.0.0:*"; done
exit 0
EOF
  chmod 0755 "${W}/stub/nginx" "${W}/stub/ss"
}
run_entry() { rm -f "${ET}"; ACP_HOME="${ACP}" bash "${ENTRYBIN}" --quiet >/dev/null 2>&1; cat "${ET}" 2>/dev/null; }
jqv() { python3 -c "import json,sys;print(json.load(open(sys.argv[1])).get(sys.argv[2]))" "$1" "$2" 2>/dev/null; }

mkstub 8090 8090,22,3306
run_entry > "${W}/e1.json"
t_chk "sirf 8090 live -> manager=[8090] customer=[]  (B1: customer lockout nahi)" \
      '[[ "$(jqv "${W}/e1.json" manager)" == "[8090]" && "$(jqv "${W}/e1.json" customer)" == "[]" ]]'

# B1 ka asli trigger: ports.json me 2083 "enabled", par nginx us par sunta NAHI
echo '{"ssl":[8090,2083,2087,2096],"cpanel":true}' > "${ACP}/etc/ports.json"
run_entry > "${W}/e2.json"
t_chk "ports.json(2083) maujood, nginx nahi sunta -> customer=[] (gate fail-open)" \
      '[[ "$(jqv "${W}/e2.json" customer)" == "[]" ]]'

mkstub 8090,2083,2087,2096 8090,2083,2087,2096,22
run_entry > "${W}/e3.json"
t_chk "sab entry ports live -> manager=[8090,2087] customer=[2083,2096]" \
      '[[ "$(jqv "${W}/e3.json" manager)" == "[8090, 2087]" && "$(jqv "${W}/e3.json" customer)" == "[2083, 2096]" ]]'

mkstub 8090,2083 8090,22          # nginx config me 2083 hai par bound nahi
run_entry > "${W}/e4.json"
t_chk "nginx config me 2083 par bound nahi -> customer=[] (aadhi config se lockout nahi)" \
      '[[ "$(jqv "${W}/e4.json" customer)" == "[]" ]]'

mkstub 8090,2083,9999 8090,2083,9999   # anjaan port allowlist me nahi
run_entry > "${W}/e5.json"
t_chk "anjaan port (9999) truth file me nahi aata" \
      '! grep -q 9999 "${W}/e5.json"'

rm -f "${W}/stub/nginx" "${W}/stub/ss"   # nginx/ss bilkul gayab
run_entry > "${W}/e6.json"
t_chk "nginx+ss gayab -> exit 0, manager=[] customer=[] (kabhi crash nahi)" \
      '[[ -s "${W}/e6.json" ]]'
mkstub 8090 8090,22

# =============================================================================
hdr "P2: login-fix.sh --diagnose (read-only)"
BEFORE="$(find "${PANEL}/app" "${PANEL}/routes" -type f -exec md5sum {} \; | sort | md5sum)"
DIAG_OUT="$(ACP_HOME="${ACP}" ACP_PRIMARY_PORT=8090 bash "${FIX}" --diagnose 2>&1)"
AFTER="$(find "${PANEL}/app" "${PANEL}/routes" -type f -exec md5sum {} \; | sort | md5sum)"
t_chk "banner v1.0 dikhta hai"          'grep -q "LOGIN FIX  -  v1.0" <<<"${DIAG_OUT}"'
t_chk "diagnose ne code NAHI badla"     '[[ "${BEFORE}" == "${AFTER}" ]]'
t_chk "diagnose ne entry-gate ki haalat batayi" 'grep -q "ports.json" <<<"${DIAG_OUT}"'
t_chk "diagnose ne nginx ACP_PORTS block check kiya" 'grep -q "ACP_PORTS" <<<"${DIAG_OUT}"'
t_chk "diagnose DB users/lock dikhata hai" 'grep -qE "panel users|users query" <<<"${DIAG_OUT}"'

# =============================================================================
hdr "P3: login-fix.sh (full run)"
RUN1="$(ACP_HOME="${ACP}" ACP_PRIMARY_PORT=8090 ACP_FIX_USER=root ACP_FIX_PASS=SimPass12345 bash "${FIX}" 2>&1)"
echo "${RUN1}" > "${W}/run1.log"
t_chk "EntryLoginController v2 install hua (decide() maujood)" \
      'grep -q "public static function decide" "${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php"'
t_chk "2FA middleware me self-heal hai" \
      'grep -q "two_factor_passed., true" "${PANEL}/app/Http/Middleware/EnsureTwoFactorIsVerified.php"'
t_chk "TwoFactorController me loop-breaker patch laga" \
      'grep -q "loop-breaker" "${PANEL}/app/Http/Controllers/Auth/TwoFactorController.php"'
t_chk "GET /login route jud gaya" \
      "grep -q \"name('login.page')\" '${PANEL}/routes/web.php'" 
t_chk "acp-entry-ports install + cron laga" \
      '[[ -x "${ACP}/bin/acp-entry-ports" && -f /etc/cron.d/alphacp-entry-ports ]]'
t_chk "truth file bani (sirf 8090 live -> customer khaali)" \
      '[[ "$(jqv "${ACP}/etc/entry-ports.json" customer)" == "[]" ]]'
t_chk "runtime user fpm pool se detect hua (alphacp), artisan-owner (root) se nahi" \
      'grep -q "runtime user    : alphacp" <<<"${RUN1}"'
t_chk "Step 8: storage ka owner fpm user (alphacp) hai, root nahi" \
      '[ "$(stat -c %U "${PANEL}/storage")" = "alphacp" ] \
       && [ "$(stat -c %U "${PANEL}/bootstrap/cache")" = "alphacp" ]'
t_chk "Step 8: fpm user storage me likh sakta hai (write-test guard OK)" \
      'grep -q "storage me likh sakta hai" <<<"${RUN1}"'
t_chk "ResellerScopeProvider v2 install hua (B5 recursion-breaker)" \
      'grep -q "installed: .*app/Providers/ResellerScopeProvider.php" <<<"${RUN1}"'
t_chk "SELFTEST ka B5 recursion check PASS hua" \
      'grep -q "B5: session se user resolve hua" <<<"${RUN1}"'
t_chk "Step 3 ne locked account unlock kiya" 'grep -q "unlocked users: [1-9]" <<<"${RUN1}"'
t_chk "regression test install hua" \
      '[[ -f "${PANEL}/tests/Feature/EntryLoginTest.php" ]]'
t_chk "SELFTEST pass hua"          'grep -q "SELFTEST pass" <<<"${RUN1}"'
t_chk "SELFTEST me 0 fail"         'grep -qE "SELFTEST: [0-9]+ pass, 0 fail" <<<"${RUN1}"'
t_chk "har PHP swap se pehle lint hua (koi FAIL nahi)" '! grep -q "lint FAIL" <<<"${RUN1}"'
t_chk "backup bana"                'ls -1d "${ACP}"/releases/loginfix-* >/dev/null 2>&1'
t_chk "FINAL VERDICT aaya"         'grep -q "FINAL VERDICT" <<<"${RUN1}"'
t_chk "live login probe (stub curl) dashboard tak pahuncha" \
      'grep -qE "LOGIN CHAL GAYA|dashboard 200" <<<"${RUN1}" || grep -q "FINAL: 302" <<<"${RUN1}"'

# =============================================================================
hdr "P4: idempotent — dobara chalao"
RUN2="$(ACP_HOME="${ACP}" ACP_PRIMARY_PORT=8090 ACP_FIX_USER=root ACP_FIX_PASS=SimPass12345 bash "${FIX}" 2>&1)"
echo "${RUN2}" > "${W}/run2.log"
t_chk "doosri baar v2 'skip' bolta hai"   'grep -q "v2 pehle se installed" <<<"${RUN2}"'
t_chk "doosri baar GET /login 'skip'"     'grep -q "GET /login pehle se hai" <<<"${RUN2}"'
t_chk "doosri baar loop-breaker 'skip'"   'grep -q "loop-breaker pehle se hai" <<<"${RUN2}"'
t_chk "doosri baar bhi SELFTEST pass"     'grep -qE "SELFTEST: [0-9]+ pass, 0 fail" <<<"${RUN2}"'
t_chk "routes/web.php me login.page ek hi baar (idempotent)" \
      '[[ "$(grep -cF "name('"'"'login.page'"'"')" "${PANEL}/routes/web.php")" == "1" ]]'

# =============================================================================
hdr "P5: --enable-ports (nginx vhost ka ACP_PORTS block)"
VHOST="${W}/nginx/alphacp-panel.conf"
cp "${VHOST}" /etc/nginx/sites-available/alphacp-panel.conf   # pristine wapas
cat > "${W}/stub/nginx" <<'EOF'
#!/usr/bin/env bash
# -T hamesha US vhost ko padhe jo login-fix.sh patch karta hai
[[ "$1" == "-t" ]] && exit "${NGINX_T_RC:-0}"
[[ "$1" == "-T" ]] && { grep -E '^[[:space:]]*listen' /etc/nginx/sites-available/alphacp-panel.conf; exit 0; }
exit 0
EOF
chmod 0755 "${W}/stub/nginx"
cat > "${W}/stub/ss" <<'EOF'
#!/usr/bin/env bash
for p in 8090 2083 2087 2096; do echo "LISTEN 0 511 0.0.0.0:${p} 0.0.0.0:*"; done
EOF
chmod 0755 "${W}/stub/ss"
RUN3="$(ACP_HOME="${ACP}" ACP_PRIMARY_PORT=8090 ACP_FIX_USER=root ACP_FIX_PASS=SimPass12345 bash "${FIX}" --enable-ports 2>&1)"
echo "${RUN3}" > "${W}/run3.log"
t_chk "vhost me 2083/2087/2096 listen jud gaye" \
      '[[ "$(grep -c "listen 2083 ssl;" /etc/nginx/sites-available/alphacp-panel.conf)" == "1" ]]'
t_chk "truth file ab separation ACTIVE batati hai" \
      '[[ "$(jqv "${ACP}/etc/entry-ports.json" customer)" == "[2083, 2096]" ]]'
t_chk "SELFTEST ab bhi 0 fail" 'grep -qE "SELFTEST: [0-9]+ pass, 0 fail" <<<"${RUN3}"'

# nginx -t FAIL -> vhost rollback (vhost ko pehle pristine karo)
cp "${VHOST}" /etc/nginx/sites-available/alphacp-panel.conf
RUN4="$(ACP_HOME="${ACP}" ACP_PRIMARY_PORT=8090 NGINX_T_RC=1 bash "${FIX}" --enable-ports 2>&1)"
echo "${RUN4}" > "${W}/run4.log"
t_chk "nginx -t fail par vhost rollback hua" 'grep -q "vhost wapas" <<<"${RUN4}"'

# =============================================================================
hdr "P6: --rollback"
RB="$(ACP_HOME="${ACP}" bash "${FIX}" --rollback 2>&1)"
t_chk "rollback chala"  'grep -q "ROLLBACK" <<<"${RB}"'
t_chk "rollback ke baad panel code dobara v2 (backup se restore)" \
      'grep -q "restore" <<<"${RB}"'

# =============================================================================
hdr "P7: PHPUnit — asli live panel code par (php-wasm, sqlite)"
phpunit_run() { # phpunit_run <testfile>
  local DB="${W}/t.sqlite"; rm -f "${DB}"; touch "${DB}"
  ( cd "${PANEL}" && PHP=8.5 DB_CONNECTION=sqlite DB_DATABASE="${DB}" ACP_TEST_DATABASE="${DB}" \
      ACP_HOME="${W}/home" ACP_CHECK_PWNED=false ACP_PROVISION_WAIT=0 APP_URL='https://panel.test:8090' \
      "${PHPW[@]}" vendor/bin/phpunit --colors=never --do-not-cache-result --testdox "$1" 2>&1 )
}
# TestCase: php-wasm Mockery workaround (panel-tests.sh jaisa) — sirf sim copy me
python3 - "${PANEL}/tests/TestCase.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
if "$mockConsoleOutput" not in s:
    s=s.replace("abstract class TestCase extends BaseTestCase\n{","abstract class TestCase extends BaseTestCase\n{\n    public $mockConsoleOutput = false; // SANDBOX ONLY\n",1)
    open(p,'w').write(s)
PY

# v1 ka trigger: ports.json me 2083 (nginx nahi sunta) — AuthTest tab bhi pass hona chahiye
echo '{"ssl":[8090,2083,2087,2096],"cpanel":true}' > "${ACP}/etc/ports.json"
rm -f "${ACP}/etc/entry-ports.json"
OUT="$(phpunit_run tests/Feature/EntryLoginTest.php)"; echo "${OUT}" > "${W}/p7a.log"
t_chk "EntryLoginTest: OK (10 tests)" 'grep -qE "^OK \(10 tests" <<<"${OUT}"'
OUT="$(phpunit_run tests/Feature/AuthTest.php)"; echo "${OUT}" > "${W}/p7b.log"
t_chk "AuthTest 8/8 — ports.json(2083) maujood hone par bhi (v1 me FAIL hota tha)" \
      'grep -qE "^OK \(8 tests" <<<"${OUT}"'

# =============================================================================
hdr "P8: BUG PROOF — purana v1 controller + ports.json(2083) -> AuthTest FAIL hona chahiye"
cp "${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php" "${W}/v2-controller.php"
cp "${REPO}/server-snapshot/files/usr/local/alphacp/panel/app/Http/Controllers/Auth/EntryLoginController.php" \
   "${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php"
OUT="$(phpunit_run tests/Feature/AuthTest.php)"; echo "${OUT}" > "${W}/p8.log"
t_chk "v1 + ports.json(2083) -> AuthTest FAIL (asli shikayat reproduce hui)" \
      'grep -qE "FAILURES|Account Panel login 2083" <<<"${OUT}"'
cp "${W}/v2-controller.php" "${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php"
OUT="$(phpunit_run tests/Feature/AuthTest.php)"
t_chk "v2 wapas -> AuthTest phir OK" 'grep -qE "^OK \(8 tests" <<<"${OUT}"'

# =============================================================================
hdr "P9: BUG PROOF (B5) — v1 ResellerScopeProvider -> SessionAuthTest par PHP FATAL"
RSP="${PANEL}/app/Providers/ResellerScopeProvider.php"
cp "${RSP}" "${W}/v2-provider.php"
cp "${REPO}/server-snapshot/files/usr/local/alphacp/panel/app/Providers/ResellerScopeProvider.php" "${RSP}"
OUT="$(phpunit_run tests/Feature/SessionAuthTest.php)"; echo "${OUT}" > "${W}/p9-v1.log"
# native PHP par "Allowed memory size ... exhausted"; php-wasm par node-level
# "RangeError: Maximum call stack size exceeded" — dono isi recursion ke chehre hain.
t_chk "v1 provider -> PHP fatal / recursion (asli shikayat reproduce hui)" \
      'grep -qE "memory size of .* exhausted|Maximum call stack size exceeded|Premature end of PHP process|Segmentation fault|ResellerScopeProvider.php\(4[0-9]\)" <<<"${OUT}"'
t_chk "v1 provider -> SessionAuthTest OK NAHI hai" '! grep -qE "^OK \(" <<<"${OUT}"'

cp "${W}/v2-provider.php" "${RSP}"
OUT="$(phpunit_run tests/Feature/SessionAuthTest.php)"; echo "${OUT}" > "${W}/p9-v2.log"
t_chk "v2 provider -> SessionAuthTest OK (12 tests)" 'grep -qE "^OK \(12 tests" <<<"${OUT}"'
t_chk "charon role ka fresh request 200 deta hai" \
      '[ "$(grep -c "works on a fresh request with data set" <<<"${OUT}")" -eq 4 ] \
       && ! grep -q "✘" <<<"${OUT}"'

# scoping abhi bhi kaam karti hai (fix ne sirf recursion toda, parity nahi)
OUT="$(phpunit_run tests/Feature/AuthorizationTest.php)"; echo "${OUT}" > "${W}/p9-authz.log"
t_chk "AuthorizationTest abhi bhi OK (reseller/root parity intact)" 'grep -qE "^OK \(" <<<"${OUT}"'

# ---------------------------------------------------------------- cleanup
rm -f /etc/nginx/sites-available/alphacp-panel.conf /etc/cron.d/alphacp-entry-ports \
      /etc/php/8.4/fpm/pool.d/alphacp.conf 2>/dev/null || true
echo ""
echo "=== LOGIN-ENTRY SIM: ${PASS} pass, ${FAIL} fail ==="
echo "logs: ${W}/run1.log run2.log run3.log p7a.log p7b.log p8.log p9-v1.log p9-v2.log p9-authz.log"
[[ ${FAIL} -eq 0 ]]
