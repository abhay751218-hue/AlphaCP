#!/usr/bin/env bash
# AlphaCP — Storage ownership FINAL fix + debug-off  v1.0
# Web user ko nginx socket -> fpm pool -> user se detect karta hai (guess nahi),
# storage + bootstrap/cache ko us user ko deta hai, APP_DEBUG=false karta hai
# (security), fpm restart + http status print. Idempotent.
set -uo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"

echo "=================================================="
echo " AlphaCP Storage Ownership Final Fix  v1.0"
echo "=================================================="

# ---- 1) asli web user: nginx fastcgi socket -> pool conf -> user ----
SOCK="$(grep -rh 'fastcgi_pass' /etc/nginx/ 2>/dev/null | grep -v '^[[:space:]]*#' | grep -o 'unix:[^;]*' | head -1)"
SOCK="${SOCK#unix:}"
POOLCONF=""
[ -n "$SOCK" ] && POOLCONF="$(grep -rl "listen = $SOCK" /etc/php/*/fpm/pool.d/ 2>/dev/null | head -1 || true)"
PU=""
[ -n "$POOLCONF" ] && PU="$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/{gsub(/ /,"",$2); print $2; exit}' "$POOLCONF" 2>/dev/null | head -1 || true)"
PU="${PU:-alphacp}"
echo "  nginx socket : ${SOCK:-(none)}"
echo "  pool conf    : ${POOLCONF:-(default)}"
echo "  web user     : $PU"

# ---- 2) ownership + perms ----
chown -R "$PU":"$PU" "$PANEL/storage" "$PANEL/bootstrap/cache" 2>/dev/null \
  || chown -R "$PU" "$PANEL/storage" "$PANEL/bootstrap/cache" || true
chmod -R ug+rwX "$PANEL/storage" "$PANEL/bootstrap/cache" || true
ls -ld "$PANEL/storage"

# ---- 3) APP_DEBUG off (production security) ----
cd "$PANEL" || exit 1
if grep -q '^APP_DEBUG' .env 2>/dev/null; then
  sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
else
  echo 'APP_DEBUG=false' >> .env
fi
PHP_BIN="$(command -v php8.4 || command -v php || echo /usr/bin/php8.4)"
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
echo "  .env: $(grep -n '^APP_DEBUG' .env 2>/dev/null)"

# ---- 4) fpm restart + verify ----
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null \
  || service php8.4-fpm restart 2>/dev/null || true
sleep 1
echo "-- http status:"
curl -sk -o /dev/null -w '   /       => %{http_code}\n' "https://127.0.0.1:8090/" 2>/dev/null || true
curl -sk -o /dev/null -w '   /login  => %{http_code}\n' "https://127.0.0.1:8090/login" 2>/dev/null || true

echo "=================================================="
echo " ==> STORAGE FIX v1.0 APPLIED (user: $PU, debug off)"
echo "=================================================="
alphacp-sync || true
