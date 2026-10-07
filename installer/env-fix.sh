#!/usr/bin/env bash
# AlphaCP — etc/ env readability fix  v1.0  (PERMANENT 500 fix)
# Root cause (diagnose v1.0 se mila): /usr/local/alphacp/etc/panel.env panel
# user (www-data) se readable NAHI tha → app bootstrap throw → har request 500.
# Intended convention (app ke apne check ke mutabik): root:alphacp, mode 0640,
# aur www-data alphacp group me. Ye script wahi restore karti hai.
set -uo pipefail
ETCDIR="${ACP_ETC:-/usr/local/alphacp/etc}"
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
PHP_BIN="$(command -v php8.4 || command -v php || echo /usr/bin/php8.4)"

echo "=================================================="
echo " AlphaCP Env Readability Fix  v1.0"
echo "=================================================="

# ---- group + membership (idempotent) ----
getent group alphacp >/dev/null 2>&1 || { groupadd alphacp && echo "  + group alphacp created"; }
if id www-data >/dev/null 2>&1; then
  if id -nG www-data 2>/dev/null | tr ' ' '\n' | grep -qx alphacp; then
    echo "  = www-data already in alphacp group"
  else
    usermod -aG alphacp www-data && echo "  + www-data added to alphacp group"
  fi
else
  echo "  (!) www-data user nahi mila — files ko www-data group denge"
fi

# ---- env files: root:alphacp 0640 (panel user group-read) ----
for f in panel.env database.env install.env; do
  if [ -f "${ETCDIR}/${f}" ]; then
    chown root:alphacp "${ETCDIR}/${f}" 2>/dev/null || chown root:www-data "${ETCDIR}/${f}" || true
    chmod 0640 "${ETCDIR}/${f}"
    echo "  + ${f} -> $(stat -c '%U:%G %a' "${ETCDIR}/${f}" 2>/dev/null)"
  else
    echo "  = ${f} not present (skip)"
  fi
done

# ---- fpm restart (nayi group membership live ho) ----
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null \
  || service php8.4-fpm restart 2>/dev/null || echo "  (fpm restart khud karein)"

# ---- verify: www-data ab read kar sakta hai? ----
echo "-- verify (as www-data):"
if [ "$(id -u)" = "0" ]; then
  sudo -u www-data head -c 0 "${ETCDIR}/panel.env" 2>/dev/null && echo "   panel.env readable ✔" \
    || echo "   panel.env ABHI BHI unreadable ✘"
  cd "$PANEL" || exit 1
  sudo -u www-data "$PHP_BIN" artisan --version 2>&1 | head -3
else
  head -c 0 "${ETCDIR}/panel.env" 2>/dev/null && echo "   panel.env readable ✔" || echo "   unreadable ✘"
fi

# ---- final http status ----
echo "-- http status:"
curl -sk -o /dev/null -w '   /       => %{http_code}\n' "https://127.0.0.1:8090/" 2>/dev/null || true
curl -sk -o /dev/null -w '   /login  => %{http_code}\n' "https://127.0.0.1:8090/login" 2>/dev/null || true

echo "=================================================="
echo " ==> ENV FIX v1.0 APPLIED"
echo "=================================================="
alphacp-sync || true
