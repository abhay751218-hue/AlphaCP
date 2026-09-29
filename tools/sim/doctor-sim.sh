#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — panel-doctor simulation test  (sirf throwaway sandbox/container me!)
#  Dekho: tools/sim/README.md
#
#  Scenario A: server ki exact haalat -> ProtectSystem=full (panel read-only) + admin
#              password = public wala. Expect: 500 -> drop-in -> 200, password rotate,
#              naya password se login chale, purana fail ho.
#  Scenario B: doctor dobara chalao (healthy). Expect: 200, password NAHI badalta.
#  Scenario C: user ne khud password badal diya. Expect: SAFE, kuch nahi chhedta.
# =============================================================================
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
DOCTOR="${REPO}/installer/panel-doctor.sh"
SIM=/tmp/acpsim
ACP_HOME=/usr/local/alphacp
PANEL=${ACP_HOME}/panel
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
LEAKED='AlphaCP@2026'
PASS=0; FAIL=0

[[ ${EUID} -eq 0 ]] || { echo "sudo se chalao"; exit 1; }
t_ok()   { echo "  PASS: $*"; PASS=$((PASS+1)); }
t_fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }

# ---------------------------------------------------------------- php 8.4 (wasm)
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"; (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
chmod -R a+rX "${PHPWASM_DIR}"
cat > /usr/bin/php8.4 <<EOF
#!/bin/sh
PHP=8.4 exec /usr/local/bin/node ${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js "\$@"
EOF
chmod 0755 /usr/bin/php8.4

# ---------------------------------------------------------------- fresh "server"
rm -rf "${SIM}" "${ACP_HOME}" /etc/php/8.4 /etc/systemd/system/php8.4-fpm.service.d
mkdir -p "${SIM}/bin" "${SIM}/state" "${ACP_HOME}/etc" "${ACP_HOME}/var" /etc/php/8.4/fpm/pool.d /run/php
id -u alphacp >/dev/null 2>&1 || useradd --system --home-dir "${PANEL}" --shell /usr/sbin/nologin alphacp
id -u www-data >/dev/null 2>&1 || useradd --system www-data

TMPB="$(mktemp -d)"
unzip -q -o "${REPO}/alphacp-code-bundle.zip" artifacts/panel-bundle-0.3.0.tar.gz -d "${TMPB}"
tar xzf "${TMPB}/artifacts/panel-bundle-0.3.0.tar.gz" -C "${TMPB}"
mv "${TMPB}/panel" "${PANEL}"; rm -rf "${TMPB}"

cat > "${PANEL}/.env" <<EOF
APP_NAME=AlphaCP
APP_ENV=production
APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
APP_DEBUG=false
APP_URL=https://127.0.0.1:8090
DB_CONNECTION=sqlite
DB_DATABASE=${PANEL}/database/panel.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
LOG_CHANNEL=daily
ACP_HOME=${ACP_HOME}
ACP_VERSION=0.3.0
ACP_ADMIN_USER=admin
ACP_ADMIN_PASSWORD=${LEAKED}
ACP_CHECK_PWNED=false
EOF
touch "${PANEL}/database/panel.sqlite"
printf 'panel_user=admin\npanel_pass=%s\npanel_port=8090\n' "${LEAKED}" > "${ACP_HOME}/var/panel-admin.txt"
printf 'AlphaCP panel\nUsername : admin\nPassword : %s\n' "${LEAKED}" > /root/.alphacp-admin-credentials
chown -R alphacp:alphacp "${PANEL}"; chmod 0640 "${PANEL}/.env"
( cd "${PANEL}" && runuser -u alphacp -- /usr/bin/php8.4 artisan migrate --force >/dev/null \
               && runuser -u alphacp -- /usr/bin/php8.4 artisan db:seed --force >/dev/null )
cat > /etc/php/8.4/fpm/pool.d/alphacp.conf <<EOF
[alphacp]
user = alphacp
listen = /run/php/alphacp-fpm.sock
EOF

# ---------------------------------------------------------------- request handler (= php-fpm worker)
cat > "${SIM}/handler.php" <<'PHP'
<?php
// php-fpm worker jaisa: ek GET / request Laravel HTTP kernel se, status file me.
$root = '/usr/local/alphacp/panel';
$out = getenv('SIM_OUT'); $st = getenv('SIM_STATUS');
file_put_contents($st, '500');
register_shutdown_function(function () use ($st) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) { @file_put_contents($st, '500'); }
});
require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$req = Illuminate\Http\Request::create('https://127.0.0.1:8090/', 'GET', [], [], [], ['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1']);
$res = $kernel->handle($req);
file_put_contents($out, (string) $res->getContent());
file_put_contents($st, (string) $res->getStatusCode());
PHP
chmod 0644 "${SIM}/handler.php"

# ---------------------------------------------------------------- stubs: systemctl + curl
cat > "${SIM}/bin/systemctl" <<'EOF'
#!/bin/bash
# systemd stub: ProtectSystem=full (Ondrej unit); drop-ins sirf daemon-reload ke baad "load" hote hain
S=/tmp/acpsim/state; echo "systemctl $*" >> $S/calls.log
case "$1" in
  show)
    prop=""; while [[ $# -gt 0 ]]; do [[ "$1" == "-p" ]] && prop="$2"; shift; done
    case "$prop" in
      ProtectSystem) echo full ;; PrivateTmp) echo yes ;; ProtectHome) echo no ;; MainPID) echo 0 ;;
      ReadWritePaths) [[ -f $S/loaded.conf ]] && grep -h '^ReadWritePaths=' $S/loaded.conf | cut -d= -f2- | tr '\n' ' ' ;;
    esac ;;
  daemon-reload) cat /etc/systemd/system/php8.4-fpm.service.d/*.conf > $S/loaded.conf 2>/dev/null || rm -f $S/loaded.conf ;;
  restart) python3 -c 'import socket,os; p="/run/php/alphacp-fpm.sock"; os.path.exists(p) and os.remove(p); s=socket.socket(socket.AF_UNIX); s.bind(p)' ;;
  *) : ;;
esac
exit 0
EOF
cat > "${SIM}/bin/curl" <<'EOF'
#!/bin/bash
# curl stub: 127.0.0.1:8090 = asli Laravel request, php-fpm ke systemd sandbox ke andar
S=/tmp/acpsim/state; out=""; fmt=""; url=""
while [[ $# -gt 0 ]]; do
  case "$1" in -o) out="$2"; shift ;; -w) fmt="$2"; shift ;; -m|--max-time) shift ;; http*) url="$1" ;; esac; shift
done
if [[ "$url" != *"127.0.0.1:8090"* ]]; then echo "203.0.113.10"; exit 0; fi
echo "curl $url" >> $S/calls.log
body=$(mktemp); st=$(mktemp); chmod 0666 "$body" "$st"
RW=0; grep -q '^ReadWritePaths=-\?/usr/local/alphacp$' $S/loaded.conf 2>/dev/null && RW=1
unshare -m bash -c "
  mount --bind /usr /usr && mount -o remount,bind,ro /usr            # ProtectSystem=full
  if [[ $RW == 1 ]]; then mount --bind /usr/local/alphacp /usr/local/alphacp && mount -o remount,bind,rw /usr/local/alphacp; fi
  cd /usr/local/alphacp/panel && SIM_OUT=$body SIM_STATUS=$st runuser -u alphacp -- /usr/bin/php8.4 /tmp/acpsim/handler.php >/dev/null 2>&1
" || true
code=$(cat "$st"); code=${code:-500}
[[ -n "$out" ]] && cp "$body" "$out"
rm -f "$body" "$st"
echo "  -> HTTP $code (sandbox rw=$RW)" >> $S/calls.log
[[ -n "$fmt" ]] && printf '%s' "$code"
exit 0
EOF
chmod 0755 "${SIM}/bin/"*

run_doctor() {  # cwd = /root (jaise user sudo se chalata hai) — v1.6 wala cwd bug pakadne ke liye
  ( cd /root && env PATH="${SIM}/bin:${PATH}" bash "${DOCTOR}" ) 2>&1 | tee "${SIM}/doctor-$1.out" | sed 's/^/    | /'
}
pw_check() {  # $1 = password -> YES/NO (DB hash se)
  ( cd "${PANEL}" && runuser -u alphacp -- env P="$1" /usr/bin/php8.4 -r '
      require "vendor/autoload.php"; $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      $h = Illuminate\Support\Facades\DB::table("users")->where("username","admin")->value("password_hash");
      echo password_verify(getenv("P"), (string)$h) ? "YES" : "NO";' ) 2>/dev/null | tail -c 3
}
http_now() { env PATH="${SIM}/bin:${PATH}" curl -k -s -o /dev/null -w '%{http_code}' "https://127.0.0.1:8090/"; }

# ================================================================= Scenario A
echo; echo "=== Scenario A: server jaisi haalat (ProtectSystem=full + public password) ==="
c="$(http_now)"; [[ "$c" == "500" ]] && t_ok "doctor se pehle HTTP 500 (bug reproduce hua)" || t_fail "pehle 500 expected, mila $c"
run_doctor A
grep -q "v1.7" "${SIM}/doctor-A.out" && t_ok "banner v1.7" || t_fail "banner v1.7 nahi"
grep -q "SANDBOX CONFIRMED" "${SIM}/doctor-A.out" && t_ok "sandbox detect hua" || t_fail "sandbox detect nahi hua"
grep -q "sandbox fix CHAL GAYA" "${SIM}/doctor-A.out" && t_ok "drop-in fix laga" || t_fail "drop-in fix nahi laga"
grep -q "PANEL READY" "${SIM}/doctor-A.out" && t_ok "VERDICT: PANEL READY" || t_fail "PANEL READY nahi"
[[ -f /etc/systemd/system/php8.4-fpm.service.d/alphacp-panel.conf ]] && t_ok "drop-in file bani" || t_fail "drop-in file nahi"
c="$(http_now)"; [[ "$c" == "200" ]] && t_ok "doctor ke baad HTTP 200" || t_fail "baad me 200 expected, mila $c"
NEW="$(grep -E '^panel_pass=' ${ACP_HOME}/var/panel-admin.txt | cut -d= -f2-)"
[[ -n "$NEW" && "$NEW" != "$LEAKED" ]] && t_ok "panel-admin.txt me naya password" || t_fail "panel-admin.txt update nahi"
grep -qF "$NEW" "${SIM}/doctor-A.out" && t_ok "naya password VERDICT me dikha" || t_fail "VERDICT me password nahi"
[[ "$(pw_check "$NEW")" == "YES" ]] && t_ok "naya password DB me sahi (login chalega)" || t_fail "naya password DB me match nahi"
[[ "$(pw_check "$LEAKED")" == "NO" ]] && t_ok "public password ab FAIL (safe)" || t_fail "public password abhi bhi chal raha"
grep -q "^ACP_ADMIN_PASSWORD=${NEW}$" "${PANEL}/.env" && t_ok ".env ACP_ADMIN_PASSWORD sync" || t_fail ".env sync nahi"
grep -q "^Password : ${NEW}$" /root/.alphacp-admin-credentials && t_ok "/root credentials sync" || t_fail "/root credentials sync nahi"
[[ "$(stat -c '%U %a' "${PANEL}/.env")" == "alphacp 640" ]] && t_ok ".env owner/mode alphacp 0640" || t_fail ".env owner/mode: $(stat -c '%U %a' "${PANEL}/.env")"
[[ -f "${PANEL}/bootstrap/cache/config.php" ]] && t_ok "config:cache sach me chala (cwd fix)" || t_fail "config cache nahi bana (ASK cwd bug?)"
grep -q "APP_DEBUG=false" "${PANEL}/.env" && t_ok "APP_DEBUG=false restore" || t_fail "APP_DEBUG restore nahi"

# ================================================================= Scenario B
echo; echo "=== Scenario B: doctor dobara (healthy) — password nahi badalna chahiye ==="
run_doctor B
grep -q "PANEL READY" "${SIM}/doctor-B.out" && t_ok "re-run: PANEL READY" || t_fail "re-run: READY nahi"
NEW2="$(grep -E '^panel_pass=' ${ACP_HOME}/var/panel-admin.txt | cut -d= -f2-)"
[[ "$NEW2" == "$NEW" ]] && t_ok "re-run par password same raha" || t_fail "re-run par password badal gaya"
grep -q "public wala NAHI hai" "${SIM}/doctor-B.out" && t_ok "re-run: SAFE detect" || t_fail "re-run: SAFE nahi"

# ================================================================= Scenario C
echo; echo "=== Scenario C: user ne khud password badla ==="
( cd "${PANEL}" && runuser -u alphacp -- /usr/bin/php8.4 artisan alphacp:admin-password admin --password='MeraApnaPass99' >/dev/null )
run_doctor C
[[ "$(pw_check 'MeraApnaPass99')" == "YES" ]] && t_ok "user ka password untouched" || t_fail "user ka password badal diya!"
grep -q "public wala NAHI hai" "${SIM}/doctor-C.out" && t_ok "SAFE detect" || t_fail "SAFE nahi"

echo; echo "=== RESULT: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
