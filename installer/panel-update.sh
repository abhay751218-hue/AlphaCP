#!/usr/bin/env bash
# =============================================================================
# AlphaCP — safe panel code updater
# updater 0.47.0  ·  default panel bundle 0.47.0  ·  agent 0.40.0  ·  alphacp-sync v1.2
#
# 0.47.0: Step 9 — panel 0.47.0 (Global Email Routing) + agent 0.40.0 (mail.globalrouting)
#
# 0.46.0: Step 9 — panel 0.46.0 (Zone Templates) + agent 0.39.0 (dns.templates)
#
# 0.45.0: Step 9 — panel 0.45.0 (Hostname A) + agent 0.38.0 (dns.hostname)
#
# 0.44.0: Step 9 — panel 0.44.0 (Add/Delete DNS Zone) + agent 0.37.0 (domain.add/remove + dns.zone)
#
# 0.43.0: Step 9 — panel 0.43.0 (DNS Zone Manager) + agent 0.37.0 (dns.zone reuse)
#
# 0.42.0: Step 9 — panel 0.42.0 (Track DNS) + agent 0.37.0 (dns.track)
#
# 0.41.0: Step 9 — panel 0.41.0 (Dynamic DNS) + agent 0.36.0 (dns.dynamic)
#
# 0.40.0: Step 9 — panel 0.40.0 (Zone Editor) + agent 0.35.0 (dns.zone)
#
# 0.39.0: Step 8 — panel 0.39.0 (Remote MySQL) + agent 0.34.0 (db.remote)
#
# 0.38.0: Step 8 — panel 0.38.0 (phpMyAdmin) + agent 0.33.0 (db.phpmyadmin)
#
# 0.37.0: Step 8 — panel 0.37.0 (Database Wizard) + agent 0.32.0 (db.set reuse)
#
# 0.36.0: Step 8 — panel 0.36.0 (MySQL Databases) + agent 0.32.0 (db.set)
#
# 0.35.0: Step 7 — panel 0.35.0 (Webmail) + agent 0.31.0 (mail.webmail)
#
# 0.34.0: Step 7 — panel 0.34.0 (Email Disk Usage) + agent 0.30.0 (mail.usage)
#
# 0.33.0: Step 7 — panel 0.33.0 (Calendar) + agent 0.29.0 (mail.calendar)
#
# 0.32.0: Step 7 — panel 0.32.0 (BoxTrapper) + agent 0.28.0 (mail.boxtrapper)
#
# 0.31.0: Step 7 — panel 0.31.0 (Encryption) + agent 0.27.0 (mail.encrypt)
#
# 0.30.0: Step 7 — panel 0.30.0 (Address Importer) + agent 0.26.0 (mail.set reuse)
#
# 0.29.0: Step 7 — panel 0.29.0 (Global Email Filters) + agent 0.26.0 (mail.gfilter)
#
# 0.28.0: Step 7 — panel 0.28.0 (Track Delivery) + agent 0.25.0 (mail.track)
#
# 0.27.0: Step 7 — panel 0.27.0 (Email Routing) + agent 0.24.0 (mail.routing)
#
# 0.26.0: Step 7 — panel 0.26.0 (Mailing Lists) + agent 0.23.0 (mail.list)
#
# 0.25.0: Step 7 — panel 0.25.0 (Spam Filters) + agent 0.22.0 (mail.spam)
#
# 0.24.0: Step 7 — panel 0.24.0 (Deliverability) + agent 0.21.0 (mail.deliverability)
#
# 0.23.0: Step 7 — panel 0.23.0 (Email Filters) + agent 0.20.0 (mail.filter)
#
# 0.22.0: Step 7 — panel 0.22.0 (Default Address) + agent 0.19.0 (mail.catchall)
#
# 0.21.0: Step 7 — panel 0.21.0 (Autoresponders) + agent 0.18.0 (mail.autorespond)
#
# 0.20.0: Step 7 — panel 0.20.0 (Forwarders) + agent 0.17.0 (mail.forward)
#
# 0.19.0: Step 7 — panel 0.19.0 (Email Accounts) + agent 0.16.0 (mail.set)
#
# 0.18.0: Step 6 — panel 0.18.0 (SSH Access) + agent 0.15.0 (ssh.set)
#
# 0.17.0: Step 6 — panel 0.17.0 (Disk Usage) + agent 0.14.0 (files.usage)
#
# 0.16.0: Step 6 — panel 0.16.0 (Directory Privacy) + agent 0.13.0 (privacy.set)
#
# 0.15.0: Step 6 — panel 0.15.0 (File Manager) + agent 0.12.0 (files.list/set)
#
# 0.14.0: Step 5 — panel 0.14.0 (Apache Handlers) + agent 0.11.0 (handlers.set)
#
# 0.13.0: Step 5 — panel 0.13.0 (MIME Types) + agent 0.10.0 (mime.set)
#
# 0.12.0: Step 5 — panel 0.12.0 (Indexes) + agent 0.9.0 (indexes.set)
#
# 0.11.0: Step 5 — panel 0.11.0 (Error Pages) + agent 0.8.0 (errorpages.set)
#
# 0.10.0: Step 5 — panel 0.10.0 (MultiPHP INI Editor) + agent 0.7.0 (php.setIni)
#
# 0.9.0: Step 5 — panel 0.9.0 (AutoSSL Let's Encrypt) + agent 0.6.0 (ssl.issue letsencrypt)
#
# 0.8.0: Step 5 — panel 0.8.0 (SSL/TLS Status) + agent 0.5.0 (ssl.issue/remove)
#
# 0.7.0: Step 5 — panel 0.7.0 (MultiPHP + Cron) + agent 0.4.0 (php.setVersion, cron.set)
#
# 0.6.0: Step 5 — panel 0.6.0 (WHM vs cPanel shell, Domains) + agent 0.3.0 (domain.add/remove)
#
# 0.5.0: Step 4 — panel 0.5.0 (Packages UI, upgrade/quota). Agent 0.2.0 same.
#
# 0.4.0: Step 3 — panel 0.4.0 (Accounts UI) + paneld agent 0.2.0 (account.* tasks)
#        pehle agent, phir panel swap. Agent fail ho to panel nahi chheḍte.
#
# 0.3.0: PRIVATE repo support — artifact/sync-tool pehle `alphacp-sync get` (deploy key) se,
#        na ho to public raw.githubusercontent (fallback). sha256 dono raaston par check.
#
# 0.2.1: alphacp-sync pehle se setup ho to use v1.1 par upgrade (sha-verified), phir sync.
#
# 0.2.0: version/URL/SHA ek jagah (commit-pinned URL, branch nahi), .env ACP_VERSION update,
#        sirf aakhri 3 backups rakhta hai, end me alphacp-sync (GitHub auto-update).
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
UPDATER_VERSION="0.47.0"
PANEL_VERSION="${ACP_PANEL_VERSION:-0.47.0}"
REPO_SLUG="abhay751218-hue/AlphaCP"
BUNDLE_COMMIT="${ACP_PANEL_BUNDLE_COMMIT:-74f2d5f766169cbb17cfcf6698bc66d085ea565e}"
BUNDLE_PATH="artifacts/panel-code-${PANEL_VERSION}.tar.gz"
BUNDLE_URL="${ACP_PANEL_BUNDLE_URL:-}"   # custom URL diya ho to sirf curl
BUNDLE_SHA256="${ACP_PANEL_BUNDLE_SHA256:-0bdfbc08dc937d9dd446eccfab968b30df5d41f41c76a6f220040c4c4dd92d03}"
AGENT_VERSION="${ACP_AGENT_VERSION:-0.40.0}"
AGENT_COMMIT="${ACP_AGENT_BUNDLE_COMMIT:-74f2d5f766169cbb17cfcf6698bc66d085ea565e}"
AGENT_PATH="artifacts/agent-${AGENT_VERSION}.tar.gz"
AGENT_SHA256="${ACP_AGENT_BUNDLE_SHA256:-b1ed1538f8766072a14fefcf240ea923ac190b9111d19af15b0c76fce85d0e8c}"
KEEP_BACKUPS="${ACP_KEEP_BACKUPS:-3}"
SYNC_TOOL_VERSION="1.2"
SYNC_TOOL_COMMIT="${ACP_SYNC_TOOL_COMMIT:-4b4573f96f55927ee1fbf526037785dcdb82aea1}"
SYNC_TOOL_SHA256="${ACP_SYNC_TOOL_SHA256:-c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852}"
SYNC_BIN="${ACP_HOME}/bin/alphacp-sync"

# repo file laao: $1 commit  $2 path  $3 out  $4 sha256
# 1) alphacp-sync get (deploy key — private repo me bhi)  2) public raw URL (fallback)
fetch_repo_file() {
  local commit="$1" path="$2" out="$3" sha="$4"
  rm -f "${out}"
  if [[ -x "${SYNC_BIN}" ]] && grep -q 'MODE="get"' "${SYNC_BIN}" 2>/dev/null; then
    if "${SYNC_BIN}" get "${commit}" "${path}" "${out}" "${sha}" >>"${LOG_FILE}" 2>&1; then
      FETCH_VIA="alphacp-sync get (deploy key)"; return 0
    fi
    log "WARN alphacp-sync get fail (${path}); public URL try"
  fi
  if curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 180 \
       "https://raw.githubusercontent.com/${REPO_SLUG}/${commit}/${path}" -o "${out}" 2>>"${LOG_FILE}"; then
    FETCH_VIA="raw.githubusercontent (public)"; return 0
  fi
  return 1
}
FETCH_VIA=""
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
say "${C_BOLD}AlphaCP existing-server updater ${UPDATER_VERSION}${C_RESET}   (yahan '${UPDATER_VERSION}' dikhe = sahi command)"
say "Panel bundle: ${PANEL_VERSION}  ·  agent: ${AGENT_VERSION}"
say "PHP-FPM: ${FPM_UNIT} · PHP: $(${PHP_BIN} -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
say ""

TMP_DIR="$(mktemp -d /tmp/alphacp-update.XXXXXX)"
trap 'rm -rf "${TMP_DIR}"; cleanup_preflight' EXIT

info "new panel artifact download ho raha hai"
if [[ -n "${BUNDLE_URL}" ]]; then
  curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 180 \
    "${BUNDLE_URL}" >"${TMP_DIR}/panel-code.tar.gz" || die "artifact download fail (${BUNDLE_URL})"
  FETCH_VIA="custom URL"
else
  fetch_repo_file "${BUNDLE_COMMIT}" "${BUNDLE_PATH}" "${TMP_DIR}/panel-code.tar.gz" "${BUNDLE_SHA256}" \
    || die "artifact download fail — repo private hai to pehle alphacp-sync v1.2 chahiye (COMMANDS.md)"
fi
info "artifact source: ${FETCH_VIA}"

ACTUAL_SHA="$(sha256sum "${TMP_DIR}/panel-code.tar.gz" | awk '{print $1}')"
[[ "${ACTUAL_SHA}" == "${BUNDLE_SHA256}" ]] \
  || die "checksum mismatch: got ${ACTUAL_SHA}, expected ${BUNDLE_SHA256}"
ok "artifact checksum verified: ${ACTUAL_SHA:0:16}…"
tar tzf "${TMP_DIR}/panel-code.tar.gz" >/dev/null 2>&1 || die "artifact corrupt"

info "agent ${AGENT_VERSION} download ho raha hai"
fetch_repo_file "${AGENT_COMMIT}" "${AGENT_PATH}" "${TMP_DIR}/agent.tar.gz" "${AGENT_SHA256}" \
  || die "agent artifact download fail — repo private hai to pehle alphacp-sync v1.2 chahiye (COMMANDS.md)"
AGENT_ACTUAL="$(sha256sum "${TMP_DIR}/agent.tar.gz" | awk '{print $1}')"
[[ "${AGENT_ACTUAL}" == "${AGENT_SHA256}" ]] \
  || die "agent checksum mismatch: got ${AGENT_ACTUAL}, expected ${AGENT_SHA256}"
tar tzf "${TMP_DIR}/agent.tar.gz" >/dev/null 2>&1 || die "agent artifact corrupt"
ok "agent checksum verified: ${AGENT_ACTUAL:0:16}…"

AGENT_ROOT="${ACP_HOME}/agent"
AGENT_STAGE="${TMP_DIR}/agent-new"
mkdir -p "${AGENT_STAGE}"
tar xzf "${TMP_DIR}/agent.tar.gz" -C "${AGENT_STAGE}"
[[ -x "${AGENT_STAGE}/agent/bin/paneld" || -f "${AGENT_STAGE}/agent/bin/paneld" ]] || die "agent paneld missing"
grep -q 'account.create' "${AGENT_STAGE}/agent/config/tasks.php" || die "agent 0.2.0 tasks missing (account.create)"
if [[ -d "${AGENT_ROOT}" ]]; then
  rm -rf "${RELEASES}/agent-backup-${STAMP}"
  cp -a "${AGENT_ROOT}" "${RELEASES}/agent-backup-${STAMP}"
fi
rm -rf "${AGENT_ROOT}"
mv "${AGENT_STAGE}/agent" "${AGENT_ROOT}"
chmod 0755 "${AGENT_ROOT}/bin/paneld"
ok "agent ${AGENT_VERSION} installed → ${AGENT_ROOT}"

install -d "${ACP_HOME}/share/suspended"
cat > "${ACP_HOME}/share/suspended/index.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Account suspended</title></head>
<body style="font-family:system-ui;padding:48px;background:#1b1020;color:#fca5a5">
<h1>Account suspended</h1><p>This hosting account is suspended. Contact your provider.</p>
</body></html>
HTML
chmod 0644 "${ACP_HOME}/share/suspended/index.html"
if command -v a2enmod >/dev/null 2>&1; then
  a2enmod proxy_fcgi rewrite headers >/dev/null 2>&1 || warn "a2enmod proxy_fcgi/rewrite skip"
fi
if [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
  systemctl restart paneld >>"${LOG_FILE}" 2>&1 && ok "paneld restarted" || warn "paneld restart skip (unit missing?)"
fi
grep -q 'issueLetsEncrypt' "${AGENT_ROOT}/src/AccountOs.php" || die "agent AutoSSL (issueLetsEncrypt) missing"

if [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]]; then
  if [[ -x /usr/bin/certbot ]]; then
    ok "certbot present"
  elif command -v apt-get >/dev/null 2>&1; then
    info "certbot install ho raha hai (Let's Encrypt AutoSSL)"
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot >>"${LOG_FILE}" 2>&1; then
      ok "certbot installed"
    else
      warn "certbot install fail — AutoSSL later; self-signed chalega"
    fi
  else
    warn "certbot missing (apt-get nahi) — AutoSSL later"
  fi
fi

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
# SQLite DB panel ke andar ho (dev/test setups) to wo bhi saath le jao — warna swap ke baad DB "gayab".
# (dev-srv1 MariaDB use karta hai; ye sirf safety net hai.) Swap se theek pehle dobara copy hota hai.
preserve_sqlite() {
  local f
  for f in "${PANEL_ROOT}"/database/*.sqlite "${PANEL_ROOT}"/database/*.sqlite-wal "${PANEL_ROOT}"/database/*.sqlite-shm; do
    [[ -f "${f}" ]] && cp -a "${f}" "${NEW_PANEL}/database/"
  done
  return 0
}
preserve_sqlite
mkdir -p "${NEW_PANEL}/storage/app/private" \
         "${NEW_PANEL}/storage/framework/cache/data" \
         "${NEW_PANEL}/storage/framework/sessions" \
         "${NEW_PANEL}/storage/framework/views" \
         "${NEW_PANEL}/storage/logs" \
         "${NEW_PANEL}/bootstrap/cache"
chown -R "${PANEL_USER}:${PANEL_USER}" "${NEW_PANEL}/storage" "${NEW_PANEL}/bootstrap/cache"
# naye panel ki .env me version (rollback par purani .env wapas aati hai)
if grep -q '^ACP_VERSION=' "${NEW_PANEL}/.env"; then
  sed -i "s/^ACP_VERSION=.*/ACP_VERSION=${PANEL_VERSION}/" "${NEW_PANEL}/.env"
else
  printf '\nACP_VERSION=%s\n' "${PANEL_VERSION}" >> "${NEW_PANEL}/.env"
fi
if grep -q '^ACP_AGENT_VERSION=' "${NEW_PANEL}/.env"; then
  sed -i "s/^ACP_AGENT_VERSION=.*/ACP_AGENT_VERSION=${AGENT_VERSION}/" "${NEW_PANEL}/.env"
else
  printf '\nACP_AGENT_VERSION=%s\n' "${AGENT_VERSION}" >> "${NEW_PANEL}/.env"
fi
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
preserve_sqlite
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
say "New panel: ${PANEL_VERSION}"
say "Backup: ${BACKUP_PANEL}"
say "URL: https://127.0.0.1:${PANEL_PORT}/"

# purane backups: sirf aakhri KEEP_BACKUPS rakho (disk na bhare)
mapfile -t OLD_BACKUPS < <(ls -1d "${RELEASES}"/panel-backup-* 2>/dev/null | sort | head -n "-${KEEP_BACKUPS}")
for d in "${OLD_BACKUPS[@]}"; do [[ -n "${d}" && -d "${d}" ]] && rm -rf "${d}" && info "purana backup hataya: $(basename "${d}")"; done

# alphacp-sync tool upgrade (sirf agar pehle se setup hai; deploy key wahi rehti hai)
if [[ -x "${SYNC_BIN}" ]] && ! grep -q "^SYNC_VERSION=\"${SYNC_TOOL_VERSION}\"" "${SYNC_BIN}"; then
  if fetch_repo_file "${SYNC_TOOL_COMMIT}" installer/alphacp-sync.sh "${TMP_DIR}/alphacp-sync.sh" "${SYNC_TOOL_SHA256}" \
     && [[ "$(sha256sum "${TMP_DIR}/alphacp-sync.sh" | awk '{print $1}')" == "${SYNC_TOOL_SHA256}" ]] \
     && bash -n "${TMP_DIR}/alphacp-sync.sh"; then
    install -m 0755 "${TMP_DIR}/alphacp-sync.sh" "${SYNC_BIN}"
    ok "alphacp-sync v${SYNC_TOOL_VERSION} install hua"
  else
    warn "alphacp-sync v${SYNC_TOOL_VERSION} download/checksum fail — purana sync tool hi chalega"
  fi
fi

# GitHub ko bhi update karo (alphacp-sync setup ho to) — fail ho to bhi update safal hai
if command -v alphacp-sync >/dev/null 2>&1; then
  info "GitHub sync (alphacp-sync) chala raha hoon"
  alphacp-sync </dev/null >>"${LOG_FILE}" 2>&1 && ok "GitHub updated (server-snapshot)" || warn "GitHub sync fail — baad me: sudo alphacp-sync"
fi
say "— panel-update ${UPDATER_VERSION}"
