#!/usr/bin/env bash
# ============================================================================
#  ports-ctrl sim — installer ko SIM=1 + fake roots par chalata hai, phir
#  PortsNginx harness se vhost-regen / restore / port-change prove karta hai.
#  Chalta hai: sandbox (php+pdo_sqlite chahiye) YA live server (/tmp me, zero
#  system mutation — SIM=1 sab gates rokta hai).
# ============================================================================
set -uo pipefail

REPO="${REPO:-$(cd "$(dirname "$0")/../.." && pwd)}"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
[[ -n "$PHPBIN" ]] || { echo "php nahi mila (PHPBIN=/path)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-portsctrl.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/etc" "$FAKE/ngx/sites-available" "$FAKE/ngx/sites-enabled" \
         "$FAKE/rcplugins/acp_sso" "$FAKE/agent" "$FAKE/logs" "$FAKE/releases"

PASS=0; FAIL=0
t() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  \033[0;32mok\033[0m   %s\n' "$name"; else FAIL=$((FAIL+1)); printf '  \033[0;31mFAIL\033[0m %s\n' "$name"; fi; }
grepc() { if [[ "${1:-}" == "--" ]]; then shift; grep -c -- "${1}" "${2}" 2>/dev/null || echo 0; else grep -c "$1" "$2" 2>/dev/null || echo 0; fi; }

# ---- seeds ----------------------------------------------------------------
# served-panel-vhost jaisa template source (fastcgi_pass + ssl lines)
cat > "$FAKE/ngx/sites-available/alphacp-panel.conf" <<'NEOF'
server {
    listen 8090 ssl http2;
    listen [::]:8090 ssl http2;
    server_name panel.local;
    ssl_certificate /etc/ssl/alphacp/panel.crt;
    ssl_certificate_key /etc/ssl/alphacp/panel.key;
    root /x/panel/public;
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/alphacp-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
NEOF
# webmail vhost (listen sync test)
printf 'server {\n    listen 2096 ssl http2;\n    server_name _;\n    root /usr/share/roundcube;\n}\n' > "$FAKE/ngx/sites-available/alphacp-webmail.conf"
# roundcube plugin config (8090 hardcoded — agent ko sync karna hai)
cat > "$FAKE/rcplugins/acp_sso/config.inc.php" <<'CEOF'
<?php
$config['acp_sso_internal_url']  = 'https://127.0.0.1:8090/internal/webmail-sso';
$config['acp_sso_secret_file']   = '/x/etc/webmail-sso.secret';
CEOF
# fake agent tree: src + config + tests (suite SIM me yahi chalegi)
cp -r "$AGENT_TREE/src" "$FAKE/agent/src"
mkdir -p "$FAKE/agent/config" "$FAKE/agent/tests" "$FAKE/agent/bin"
cp "$AGENT_TREE/config/tasks.php" "$FAKE/agent/config/tasks.php"
cp "$AGENT_TREE/tests/run-tests.php" "$FAKE/agent/tests/run-tests.php"
cp "$AGENT_TREE/tests/FakeCommandExecutor.php" "$FAKE/agent/tests/FakeCommandExecutor.php"
cp "$AGENT_TREE/bin/paneld" "$FAKE/agent/bin/paneld"; chmod +x "$FAKE/agent/bin/paneld"
# fake panel tree (installer payloads likhega)
mkdir -p "$FAKE/panel"

AGENT_TREE="${AGENT_TREE:-$REPO/server-snapshot/files/usr/local/alphacp/agent}"
FIX="${FIX:-$REPO/installer/ports-ctrl.sh}"
[[ -f "$FIX" ]] || { echo "installer/ports-ctrl.sh missing — build pehle"; exit 2; }

export SIM=1 ACP_HOME="$FAKE" ACP_NGX_ROOT="$FAKE/ngx" ACP_RC_PLUGINS="$FAKE/rcplugins" \
       PHPBIN="$PHPBIN"

echo "== apply #1 (SIM) =="
A1="$(bash "$FIX" 2>&1)"; A1_RC=$?
printf '%s\n' "$A1" > "$FAKE/.a1"
if [[ "$A1_RC" -ne 0 ]]; then echo "----- A1 tail (debug) -----"; tail -30 "$FAKE/.a1"; echo "---------------------------"; fi
if ! grep -q "failed: 0" "$FAKE/.a1" 2>/dev/null; then
  echo "----- suite FAIL detail -----"
  ( cd "$FAKE/agent" && php tests/run-tests.php 2>&1 | grep -a -A2 "^  FAIL" | head -30 ) || true
  echo "-----------------------------"
fi
t "installer exit 0"               test "$A1_RC" -eq 0
t "ports.json default bana"        bash -c "grep -q '\"whm\": 2087' '$FAKE/etc/ports.json'"
t "PortMap payload likhi"          test -f "$FAKE/panel/app/Support/PortMap.php"
t "guard bootstrap me register"    grep -q AcpPortGuard "$FAKE/panel/bootstrap/app.php"
t "login blade WHM branding"       grep -q 'WHM Login' "$FAKE/panel/resources/views/auth/login.blade.php"
t "tasks.php me ports.apply"       grep -q "'ports.apply'" "$FAKE/agent/config/tasks.php"
t "suite GREEN (SIM)"              grep -q "failed: 0" "$FAKE/.a1"

# ---- PortsNginx harness ----------------------------------------------------
cat > "$FAKE/harness.php" <<'HEOF'
<?php
declare(strict_types=1);
require $argv[2] . '/src/CommandResult.php';
require $argv[2] . '/src/CommandExecutor.php';
require $argv[2] . '/src/TaskLogger.php';
require $argv[2] . '/src/TaskRejectedException.php';
require $argv[2] . '/src/PortsNginx.php';
require $argv[2] . '/tests/FakeCommandExecutor.php';

use Alphacp\Agent\PortsNginx;
use Alphacp\Agent\TaskLogger;
use Alphacp\Agent\Tests\FakeCommandExecutor;

$fake = getenv('FAKE_ROOT');
$cmd  = new FakeCommandExecutor();
if (getenv('FAIL_NGINX_T') === '1') {
    $cmd->failWhenContains = 'nginx -t';
}
$log  = new TaskLogger(new PDO('sqlite::memory:'), null, false);
$p    = new PortsNginx($cmd, $log, $fake, $fake . '/ngx', $fake . '/rcplugins');
$res  = $p->apply();
echo json_encode($res), "\n";
HEOF

run_harness() { FAKE_ROOT="$FAKE" FAIL_NGINX_T="${FAIL_NGINX_T:-0}" "$PHPBIN" "$FAKE/harness.php" x "$FAKE/agent" 2>&1; }

echo "== harness: apply (default map) =="
H1="$(run_harness)"; H1_RC=$?
t "harness exit 0"                 test "$H1_RC" -eq 0
t "applied true"                   bash -c "echo '$H1' | grep -q '\"applied\":true'"
AV="$FAKE/ngx/sites-available"
t "whm vhost listen 2087"          grep -q 'listen 2087 ssl' "$AV/alphacp-whm.conf"
t "whm vhost me internal NAHI"     bash -c "! grep -q 'location /internal/' '$AV/alphacp-whm.conf'"
t "cpanel vhost listen 2083"       grep -q 'listen 2083 ssl' "$AV/alphacp-cpanel.conf"
t "cpanel vhost internal loc"      grep -q 'location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files' "$AV/alphacp-cpanel.conf"
t "link page listen 8090"          grep -q 'listen 8090 ssl' "$AV/alphacp-link.conf"
t 'link page $host links'          bash -c "grep -q 'https://\$host:2087/' '$AV/alphacp-link.conf'"
t "webmail listen sync"            grep -q 'listen 2096 ssl' "$AV/alphacp-webmail.conf"
t "plugin url sync 2083"           grep -q "127.0.0.1:2083/internal/webmail-sso" "$FAKE/rcplugins/acp_sso/config.inc.php"
t "purana panel vhost retired"     bash -c "test ! -f '$AV/alphacp-panel.conf'"
t "template bana"                  test -f "$FAKE/etc/panel-vhost.template"

echo "== harness: nginx -t fail → restore =="
printf 'CANARY-OLD-WHM\n' > "$AV/alphacp-whm.conf"
H2="$(FAIL_NGINX_T=1 run_harness)"
t "applied false on nginx -t fail" bash -c "echo '$H2' | grep -q '\"applied\":false'"
t "whm conf CANARY wapas (restore)" grep -q 'CANARY-OLD-WHM' "$AV/alphacp-whm.conf"

echo "== harness: owner map change (whm 2097, link off) =="
printf '{\n "whm": 2097,\n "cpanel": 2083,\n "webmail": 2096,\n "link": 8090,\n "link_enabled": false\n}\n' > "$FAKE/etc/ports.json"
H3="$(run_harness)"
t "whm listen 2097 (owner change)" grep -q 'listen 2097 ssl' "$AV/alphacp-whm.conf"
t "link conf removed (owner off)"  bash -c "test ! -f '$AV/alphacp-link.conf'"

echo "== apply #2 (SIM, idempotent) =="
A2="$(bash "$FIX" 2>&1)"; A2_RC=$?
if [[ "$A2_RC" -ne 0 ]]; then echo "----- A2 tail (debug) -----"; printf '%s\n' "$A2" | tail -30; echo "---------------------------"; fi
t "re-apply exit 0"                test "$A2_RC" -eq 0
t "re-apply suite GREEN"           bash -c "echo '$A2' | grep -q 'failed: 0'"

echo ""
echo "----------------------------------------"
echo "ports-ctrl-sim: passed=$PASS failed=$FAIL"
[[ "$FAIL" -eq 0 ]] || exit 1
