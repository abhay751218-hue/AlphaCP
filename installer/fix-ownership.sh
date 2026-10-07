#!/usr/bin/env bash
# AlphaCP — Emergency storage-ownership fix + cache flush  v1.0
# v2.1 ne galti se storage ko root diya tha (master process detect hua tha);
# ye script ASLI fpm WORKER user detect karke ownership wapas deti hai,
# sahi php binary (/usr/bin/php8.4) se caches clear karti hai, fpm restart
# karti hai, aur aakhri exceptions print karti hai (debug ke liye).
set -uo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"

echo "=================================================="
echo " AlphaCP Emergency Ownership Fix  v1.0"
echo "=================================================="

# ---- sahi php binary (server par `php` PATH me nahi hai) ----
PHP_BIN="$(command -v php8.4 || command -v php || echo /usr/bin/php8.4)"
echo "  php bin : $PHP_BIN"

# ---- asli fpm WORKER user (workers master se zyada hote hain) ----
WU="$(ps axo user=,comm= 2>/dev/null | awk '$2 ~ /php-fpm/ {print $1}' | sort | uniq -c | sort -rn | awk 'NR==1 {print $2}')"
if [ -z "$WU" ] || [ "$WU" = "root" ]; then
  WU="$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/{gsub(/ /,"",$2); print $2; exit}' /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 || true)"
fi
WU="${WU:-www-data}"
echo "  fpm worker user: $WU"

# ---- ownership + permissions wapas ----
chown -R "$WU":"$WU" "$PANEL/storage" "$PANEL/bootstrap/cache" 2>/dev/null \
  || chown -R "$WU" "$PANEL/storage" "$PANEL/bootstrap/cache" || true
chmod -R ug+rwX "$PANEL/storage" "$PANEL/bootstrap/cache" || true
mkdir -p "$PANEL/storage/logs"
chown "$WU":"$WU" "$PANEL/storage/logs" 2>/dev/null || true
echo "  storage + bootstrap/cache -> $WU (writable)"

# ---- caches clear (sahi php ke saath) ----
cd "$PANEL" || exit 1
"$PHP_BIN" artisan view:clear    2>&1 | tail -1 || true
"$PHP_BIN" artisan config:clear  2>&1 | tail -1 || true
"$PHP_BIN" artisan route:clear   2>&1 | tail -1 || true
"$PHP_BIN" artisan cache:clear   2>&1 | tail -1 || true

# ---- fpm restart (opcache flush) ----
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null \
  || service php8.4-fpm restart 2>/dev/null || echo "  (fpm restart khud karein: sudo systemctl restart php8.4-fpm)"

# ---- aakhri exceptions print (debug) ----
LOGF="$(ls -t "$PANEL"/storage/logs/*.log 2>/dev/null | head -1)"
if [ -n "$LOGF" ]; then
  echo "  --- last errors ($(basename "$LOGF")) ---"
  grep -a "ERROR" "$LOGF" | tail -3 || echo "  (koi ERROR line nahi)"
else
  echo "  (abhi koi log file nahi — storage writable hai, ab likhegi)"
fi

echo "=================================================="
echo " ==> OWNERSHIP FIX v1.0 APPLIED"
echo "=================================================="
alphacp-sync || true
