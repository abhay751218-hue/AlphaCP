#!/usr/bin/env bash
# AlphaCP panel — permission repair (HTTP 500 ka ilaaj bina reinstall)
# Root se chali hui artisan commands storage/logs + bootstrap/cache ko root ka
# bana deti hain; php-fpm (user alphacp) likh nahi paata -> har page 500.
set -u
ROOT=/usr/local/alphacp/panel
for d in "$ROOT/storage" "$ROOT/bootstrap/cache"; do
  [[ -d "$d" ]] || continue
  chown -R alphacp:alphacp "$d" 2>/dev/null || true
  find "$d" -type d -exec chmod 0770 {} \; 2>/dev/null || true
  find "$d" -type f -exec chmod 0660 {} \; 2>/dev/null || true
done
rm -f "$ROOT/storage/logs/"*.log 2>/dev/null || true
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.4)-fpm" 2>/dev/null || true
sleep 2
curl -k -s -o /dev/null -w "==> PANEL: HTTP %{http_code}\n" https://127.0.0.1:8090/
