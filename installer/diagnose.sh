#!/usr/bin/env bash
# AlphaCP — Read-only diagnostic v1.0  (KUCH BHI change nahi karta)
# Boot error, http status, ownership, logs — sab print karta hai.
set -uo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
PHP_BIN="$(command -v php8.4 || command -v php || echo /usr/bin/php8.4)"

echo "=================================================="
echo " AlphaCP Diagnose v1.0 (read-only)"
echo "=================================================="
echo "-- php: $PHP_BIN"
"$PHP_BIN" -v 2>&1 | head -1

echo "-- http status (server ke andar se):"
curl -sk -o /dev/null -w '   /       => %{http_code}\n' "https://127.0.0.1:8090/" 2>/dev/null || echo "   (curl fail)"
curl -sk -o /dev/null -w '   /login  => %{http_code}\n' "https://127.0.0.1:8090/login" 2>/dev/null || true

echo "-- ownership:"
ls -ld "$PANEL/storage" "$PANEL/storage/logs" "$PANEL/storage/framework/views" "$PANEL/bootstrap/cache" 2>/dev/null

echo "-- storage/logs me files:"
ls -l "$PANEL"/storage/logs/ 2>/dev/null | tail -6

echo "-- artisan boot test (display_errors ON, root):"
cd "$PANEL" || exit 1
"$PHP_BIN" -d display_errors=1 -d error_reporting=E_ALL artisan --version 2>&1 | head -25

echo "-- artisan view:clear as www-data (display_errors ON):"
if [ "$(id -u)" = "0" ]; then
  sudo -u www-data "$PHP_BIN" -d display_errors=1 -d error_reporting=E_ALL artisan view:clear 2>&1 | head -15
else
  "$PHP_BIN" -d display_errors=1 -d error_reporting=E_ALL artisan view:clear 2>&1 | head -15
fi

echo "-- fpm log tail:"
tail -n 8 /var/log/php8.4-fpm.log 2>/dev/null \
  || journalctl -u php8.4-fpm -n 8 --no-pager 2>/dev/null \
  || echo "   (fpm log nahi mila)"

echo "-- nginx error log tail:"
tail -n 8 /var/log/nginx/error.log 2>/dev/null || echo "   (nginx error.log nahi mila)"

echo "=================================================="
echo " ==> DIAGNOSE END — output paste karein"
echo "=================================================="
