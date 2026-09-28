#!/usr/bin/env bash
# =============================================================================
# AlphaCP — safe panel code updater
# Version 0.3.1 panel bundle / updater 0.1.0
#
# Use on an EXISTING AlphaCP server only. It preserves the current .env,
# APP_KEY, database, panel-admin credentials and storage, stages the new code,
# runs Composer/migrations as a preflight, then atomically swaps the panel.
# A failed HTTP health check automatically rolls back to the previous panel.
# =============================================================================
set -Eeuo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL_ROOT="${PANEL_ROOT:-${ACP_HOME}/panel}"
PANEL_USER="${PANEL_USER:-alphacp}"
PANEL_PORT="${PANEL_PORT:-8090}"
BUNDLE_URL="${ACP_PANEL_BUNDLE_URL:-https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/arena/01a0ea0d-alphacp/artifacts/panel-code-0.3.1.tar.gz}"
BUNDLE_SHA256="${ACP_PANEL_BUNDLE_SHA256:-32fe68cce8868d05a23b962821acf20d19e4f56b4d8711140b40aaa063b6494c}"
LOG_FILE="/var/log/alphacp-panel-update.log"
STAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASES="${ACP_HOME}/releases"
NEW_PANEL=""
BACKUP_PANEL=""
SWAPPED=0

C_BOLD=$'\033[1m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RESET=$'\033[0m'
say() { printf '%s\n' "$*"; }
log() { printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${LOG_FILE}" 2>/dev/null || true; }
ok() { log "OK   $*"; say "${C_GREEN}[OK]${C_RESET} $*"; }
info() { log "INFO $*"; say "[i] $*"; }
warn() { log "WARN $*"; say "${C_YELLOW}[!]${C_RESET} $*"; }
die() { log "FAIL $*"; say "${C_RED}[x]${C_RESET} $*"; say "Log: ${LOG_FILE}"; exit 1; }

cleanup_preflight() {
  if (( SWAPPED == 0 )) && [[ -n "${NEW_PANEL}" && -d "${NEW_PANEL}" ]]; then
    rm -rf "${NEW_PANEL}"
  fi
}
trap cleanup_preflight EXIT

[[ "${EUID}" -eq 0 ]] || die "root chahiye: sudo bash $0"
command -v curl >/dev/null 2>&1 || die "curl missing"
command -v sha256sum >/dev/null 2>&1 || die "sha256sum missing"
command -v tar >/dev/null 2>&1 || die "tar missing"
command -v composer >/dev/null 2>&1 || die "composer missing"
id -u "${PANEL_USER}" >/dev/null 2>&1 || die "panel user '${PANEL_USER}' missing"
[[ -f "${PANEL_ROOT}/artisan" ]] || die "existing panel nahi mila: ${PANEL_ROOT}"
[[ -f "${PANEL_ROOT}/.env" ]] || die "existing panel .env missing — update rok diya"
[[ -d "${PANEL_ROOT}/vendor" ]] || die "existing vendor missing — update rok diya"

cd /
mkdir -p "${RELEASES}"
: > "${LOG_FILE}" 2>/dev/null || true
chmod 0600 "${LOG_FILE}" 2>/dev/null || true

# Detect the PHP-FPM pool that owns the panel socket. Never guess the oldest PHP.
POOL_FILE="$(grep -rl 'alphacp-fpm.sock' /etc/php/*/fpm/pool.d/ 2>/dev/null | sort -V | tail -1 || true)"
FPM_VERSION=""
if [[ -n "${POOL_FILE}" ]]; then
  FPM_VERSION="$(printf '%s' "${POOL_FILE}" | cut -d/ -f4)"
fi
FPM_VERSION="${FPM_VERSION:-8.4}"
PHP_BIN="/usr/bin/php${FPM_VERSION}"
[[ -x "${PHP_BIN}" ]] || PHP_BIN="$(command -v php || true)"
[[ -x "${PHP_BIN}" ]] || die "PHP ${FPM_VERSION} CLI missing"
FPM_UNIT="php${FPM_VERSION}-fpm"

say ""
say "${C_BOLD}AlphaCP existing-server updater 0.1.0${C_RESET}"
say "Panel bundle: 0.3.1 (S2C license/trial + panel fixes)"
say "PHP-FPM: ${FPM_UNIT} · PHP: $(${PHP_BIN} -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
say ""

TMP_DIR="$(mktemp -d /tmp/alphacp-update.XXXXXX)"
trap 'rm -rf "${TMP_DIR}"; cleanup_preflight' EXIT

info "new panel artifact download ho raha hai"
curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 180 \
  "${BUNDLE_URL}" >"${TMP_DIR}/panel-code.tar.gz" \
  || die "artifact download fail"

ACTUAL_SHA="$(sha256sum "${TMP_DIR}/panel-code.tar.gz" | awk '{print $1}')"
[[ "${ACTUAL_SHA}" == "${BUNDLE_SHA256}" ]] \
  || die "checksum mismatch: got ${ACTUAL_SHA}, expected ${BUNDLE_SHA256}"
ok "artifact checksum verified: ${ACTUAL_SHA:0:16}…"
tar tzf "${TMP_DIR}/panel-code.tar.gz" >/dev/null 2>&1 || die "artifact corrupt"

NEW_PANEL="${RELEASES}/panel-${STAMP}"
mkdir -p "${NEW_PANEL}"
tar xzf "${TMP_DIR}/panel-code.tar.gz" -C "${NEW_PANEL}" --strip-components=1
[[ -f "${NEW_PANEL}/artisan" ]] || die "new artisan missing"
grep -q '"laravel/framework": "\^13' "${NEW_PANEL}/composer.json" \
  || die "new artifact Laravel 13 nahi hai"

# Preserve runtime state, APP_KEY, sessions and compiled user-facing assets.
cp -a "${PANEL_ROOT}/.env" "${NEW_PANEL}/.env"
if [[ -d "${PANEL_ROOT}/storage" ]]; then
  cp -a "${PANEL_ROOT}/storage" "${NEW_PANEL}/storage"
fi
mkdir -p "${NEW_PANEL}/storage/app/private" \
         "${NEW_PANEL}/storage/framework/cache/data" \
         "${NEW_PANEL}/storage/framework/sessions" \
         "${NEW_PANEL}/storage/framework/views" \
         "${NEW_PANEL}/storage/logs" \
         "${NEW_PANEL}/bootstrap/cache"
chown -R "${PANEL_USER}:${PANEL_USER}" "${NEW_PANEL}/storage" "${NEW_PANEL}/bootstrap/cache"
chown "${PANEL_USER}:${PANEL_USER}" "${NEW_PANEL}/.env"
chmod 0640 "${NEW_PANEL}/.env"

info "Composer dependencies install ho rahi hain"
(
  cd "${NEW_PANEL}"
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress
) >>"${LOG_FILE}" 2>&1 || die "composer install fail — ${LOG_FILE} dekho"
[[ -f "${NEW_PANEL}/vendor/autoload.php" ]] || die "new vendor missing"
ok "Composer install complete"

artisan_new() {
  (cd "${NEW_PANEL}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@")
}

info "Laravel preflight (database untouched except pending additive migrations)"
artisan_new --version >>"${LOG_FILE}" 2>&1 || die "new Laravel boot fail"
artisan_new config:clear >>"${LOG_FILE}" 2>&1 || die "config clear fail"
artisan_new view:clear >>"${LOG_FILE}" 2>&1 || die "view clear fail"
artisan_new migrate --force >>"${LOG_FILE}" 2>&1 || die "migration preflight fail"
# Do not cache config/routes while the app lives in /releases. Laravel stores
# absolute view/config paths; those paths would violate the FPM open_basedir
# allowlist after the atomic swap. Caches are rebuilt from the final panel path.
chown -R "${PANEL_USER}:${PANEL_USER}" "${NEW_PANEL}/storage" "${NEW_PANEL}/bootstrap/cache"
ok "Laravel preflight complete"

# Permanent fix for Ubuntu/Ondrej ProtectSystem=full before FPM restarts.
if [[ -d /run/systemd/system && -x "$(command -v systemctl)" ]]; then
  DROPIN="/etc/systemd/system/${FPM_UNIT}.service.d/alphacp-panel.conf"
  install -d "$(dirname "${DROPIN}")"
  cat >"${DROPIN}" <<EOF
# AlphaCP panel: php-fpm workers ko panel runtime me likhne do.
[Service]
ReadWritePaths=-${ACP_HOME}
ReadWritePaths=-/run/php
EOF
  chmod 0644 "${DROPIN}"
  systemctl daemon-reload >>"${LOG_FILE}" 2>&1 || die "systemd daemon-reload fail"
  ok "php-fpm sandbox allowlist ready"
fi

BACKUP_PANEL="${RELEASES}/panel-backup-${STAMP}"

rollback_current() {
  local reason="$1"
  warn "${reason}; automatic rollback start"
  local failed_panel="${RELEASES}/panel-failed-${STAMP}"
  if [[ -d "${PANEL_ROOT}" ]]; then
    mv "${PANEL_ROOT}" "${failed_panel}"
  fi
  if [[ -d "${BACKUP_PANEL}" ]]; then
    mv "${BACKUP_PANEL}" "${PANEL_ROOT}"
  else
    say "${C_RED}Backup panel directory missing: ${BACKUP_PANEL}${C_RESET}"
    exit 1
  fi
  systemctl restart "${FPM_UNIT}" >>"${LOG_FILE}" 2>&1 || true
  local rollback_code
  rollback_code="$(curl -k -sS -o /dev/null -w '%{http_code}' -m 20 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
  if [[ "${rollback_code}" == "200" ]]; then
    say "${C_YELLOW}Rollback successful — old panel HTTP 200 restored.${C_RESET}"
  else
    say "${C_RED}Rollback health check bhi HTTP ${rollback_code:-none}; ${LOG_FILE} turant check karein.${C_RESET}"
  fi
  exit 1
}

info "atomic panel swap ho raha hai"
mv "${PANEL_ROOT}" "${BACKUP_PANEL}"
mv "${NEW_PANEL}" "${PANEL_ROOT}"
SWAPPED=1
NEW_PANEL=""

# Rebuild all absolute-path caches only after the app is at its final path.
# This is essential because the FPM pool open_basedir allowlist contains
# /usr/local/alphacp/panel, not /usr/local/alphacp/releases/....
artisan_current() {
  (cd "${PANEL_ROOT}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@")
}
if ! artisan_current config:clear >>"${LOG_FILE}" 2>&1; then
  rollback_current "final config clear fail"
fi
if ! artisan_current view:clear >>"${LOG_FILE}" 2>&1; then
  rollback_current "final view clear fail"
fi
if ! artisan_current route:cache >>"${LOG_FILE}" 2>&1; then
  rollback_current "final route cache fail"
fi
if ! artisan_current config:cache >>"${LOG_FILE}" 2>&1; then
  rollback_current "final config cache fail"
fi
chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"

systemctl restart "${FPM_UNIT}" >>"${LOG_FILE}" 2>&1 || true
sleep 2

CODE="$(curl -k -sS -o "${TMP_DIR}/health.html" -w '%{http_code}' -m 20 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
if [[ "${CODE}" != "200" ]]; then
  rollback_current "health check HTTP ${CODE:-none}"
fi

ok "new panel health HTTP 200"
say ""
say "${C_GREEN}${C_BOLD}==> UPDATE COMPLETE ✅${C_RESET}"
say "New panel: 0.3.1 (license/trial client included)"
say "Backup: ${BACKUP_PANEL}"
say "URL: https://127.0.0.1:${PANEL_PORT}/"
say "Ab browser me existing admin login karke License & Trial tile check karein."
