#!/usr/bin/env bash
# =============================================================================
# AlphaCP — safe panel code updater
# updater 0.72.0  ·  default panel bundle 0.72.0  ·  agent 0.65.0  ·  alphacp-sync v1.2
#
# 0.71.0: S10 — cpmove MySQL restore (`db.restore`: two-phase sanitised import, dumps streamed)
# 0.70.1: agent fix — useradd GECOS comment me colon (“Create Account” asli host par fail hota tha)
# 0.72.0: S10 — remote pull: cpmove archive purane server se SSH (scp) se lao (host key pinning,
#         password/key auth, atomic download) — panel 0.72.0 + agent 0.65.0; openssh-client install
# 0.71.0: S10 — cpmove archive ke mysql/*.sql dumps asli MariaDB me restore (db.restore);
#         panel 0.71.0 + agent 0.64.0
# 0.70.0: S8 — real MariaDB databases/users/GRANTs (socket-auth client, SQL on stdin) + MySQL Users page
# 0.69.0: S10 — real cPanel account import (cpmove/legacy/nested, sha256-verified, home swap,
#         pre-restore copy) + transfer/restore job history + import drop dir (FPM read allowlist)
# 0.68.0: S10 — scheduled backups: cron (schedule:run) + hourly alphacp:scheduled-backups, window marker
# 0.67.0: S10 — safe home restore (whole home or one subtree, pre-restore copy); panel 0.67.0 + agent 0.60.0
# 0.66.0: Security — symlink root-write escape fix (agent files.set/list/usage); panel 0.66.0 + agent 0.59.0
# 0.65.0: S10 — real, verified home tar.gz archive + account-scoped download; backup storage open_basedir
# 0.64.0: Step 10 — panel 0.64.0 (Review Transfers and Restores) + agent 0.57.0 (backup.review)
#
# 0.63.0: Step 10 — panel 0.63.0 (Transfer or Restore a cPanel Account) + agent 0.56.0 (backup.cpanel)
#
# 0.62.0: Step 10 — panel 0.62.0 (Transfer Tool) + agent 0.55.0 (backup.transfer)
#
# 0.61.0: Step 10 — panel 0.61.0 (File and Directory Restoration) + agent 0.54.0 (backup.filedir)
#
# 0.60.0: Step 10 — panel 0.60.0 (Backup User Selection) + agent 0.53.0 (backup.users)
#
# 0.59.0: Step 10 — panel 0.59.0 (Backup Restoration) + agent 0.52.0 (backup.restoration)
#
# 0.58.0: Step 10 — panel 0.58.0 (Backup Config) + agent 0.51.0 (backup.config)
#
# 0.57.0: Step 10 — panel 0.57.0 (File Restoration) + agent 0.50.0 (backup.restore)
#
# 0.56.0: Step 10 — panel 0.56.0 (Backup Wizard) + agent 0.49.0 (backup.wizard)
#
# 0.55.0: Step 10 — panel 0.55.0 (Backup) + agent 0.48.0 (backup.create)
#
# 0.54.0: Step 9 — panel 0.54.0 (Nameserver Selection) + agent 0.47.0 (dns.nameserver)
#
# 0.53.0: Step 9 — panel 0.53.0 (Synchronize DNS) + agent 0.46.0 (dns.sync)
#
# 0.52.0: Step 9 — panel 0.52.0 (Domain Forwarding) + agent 0.45.0 (dns.forward)
#
# 0.51.0: Step 9 — panel 0.51.0 (Set Zone TTL) + agent 0.44.0 (dns.ttl)
#
# 0.50.0: Step 9 — panel 0.50.0 (DNS Cleanup) + agent 0.43.0 (dns.cleanup)
#
# 0.49.0: Step 9 — panel 0.49.0 (Park a Domain) + agent 0.42.0 (dns.park)
#
# 0.48.0: Step 9 — panel 0.48.0 (NS Record Report) + agent 0.41.0 (dns.nsreport)
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
UPDATER_VERSION="0.74.4"
PANEL_VERSION="${ACP_PANEL_VERSION:-0.74.0}"
REPO_SLUG="abhay751218-hue/AlphaCP"
BUNDLE_COMMIT="${ACP_PANEL_BUNDLE_COMMIT:-2654506a92e7f975d0d468afc16873803e992f3d}"
BUNDLE_PATH="artifacts/panel-code-${PANEL_VERSION}.tar.gz"
BUNDLE_URL="${ACP_PANEL_BUNDLE_URL:-}"   # custom URL diya ho to sirf curl
BUNDLE_SHA256="${ACP_PANEL_BUNDLE_SHA256:-7396d88339f2d716889dc72058eded0e0e156f47668656b28a1c998dc479f4b5}"
AGENT_VERSION="${ACP_AGENT_VERSION:-0.65.0}"
AGENT_COMMIT="${ACP_AGENT_BUNDLE_COMMIT:-2654506a92e7f975d0d468afc16873803e992f3d}"
AGENT_PATH="artifacts/agent-${AGENT_VERSION}.tar.gz"
AGENT_SHA256="${ACP_AGENT_BUNDLE_SHA256:-f02dc0d558b0313a966d047718e419497eb1eff5e537ddccdee947f060468c5e}"
KEEP_BACKUPS="${ACP_KEEP_BACKUPS:-3}"
SYNC_TOOL_VERSION="1.2"
SYNC_TOOL_COMMIT="${ACP_SYNC_TOOL_COMMIT:-4b4573f96f55927ee1fbf526037785dcdb82aea1}"
SYNC_TOOL_SHA256="${ACP_SYNC_TOOL_SHA256:-c1ac1b491bc8c8fd1c7d2b9ae71e0a6610937773475fc7fd8fe83f598b022852}"
SYNC_BIN="${ACP_HOME}/bin/alphacp-sync"
CRON_FILE="${ACP_PANEL_CRON_FILE:-/etc/cron.d/alphacp-panel}"

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
POOL_BACKUP=""
POOL_CHANGED=0
POOL_FILE=""
PANEL_GROUP="${ACP_PANEL_GROUP:-${PANEL_USER}}"

C_BOLD=$'\033[1m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RESET=$'\033[0m'
say() { printf '%s\n' "$*"; }
log() { printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${LOG_FILE}" 2>/dev/null || true; }
ok() { log "OK   $*"; say "${C_GREEN}[OK]${C_RESET} $*"; }
info() { log "INFO $*"; say "[i] $*"; }
warn() { log "WARN $*"; say "${C_YELLOW}[!]${C_RESET} $*"; }
die() { log "FAIL $*"; say "${C_RED}[x]${C_RESET} $*"; say "Log: ${LOG_FILE}"; exit 1; }

cleanup_preflight() {
  if (( SWAPPED == 0 && POOL_CHANGED == 1 )) && [[ -n "${POOL_BACKUP}" && -f "${POOL_BACKUP}" && -n "${POOL_FILE}" ]]; then
    cp -a "${POOL_BACKUP}" "${POOL_FILE}" 2>/dev/null || true
  fi
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

ensure_backup_read_access() {
  local backup_root="${ACP_HOME}/backups"
  [[ -n "${POOL_FILE}" && -f "${POOL_FILE}" ]] || die "AlphaCP PHP-FPM pool file missing; cannot safely enable backup downloads"
  [[ ! -L "${backup_root}" ]] || die "backup storage path is a symlink: ${backup_root}"
  [[ ! -e "${backup_root}" || -d "${backup_root}" ]] || die "backup storage path is not a directory: ${backup_root}"
  getent group "${PANEL_GROUP}" >/dev/null 2>&1 || die "panel group missing: ${PANEL_GROUP}"
  install -d -o root -g "${PANEL_GROUP}" -m 0750 "${backup_root}" || die "backup storage directory setup failed"

  local open_basedir_line
  open_basedir_line="$(grep -F -m1 'php_admin_value[open_basedir]' "${POOL_FILE}" || true)"
  [[ -n "${open_basedir_line}" ]] || die "FPM pool has no open_basedir directive: ${POOL_FILE}"
  if [[ "${open_basedir_line}" == *"${backup_root}"* ]]; then
    ok "backup download path already allowlisted in PHP-FPM"
    return 0
  fi

  POOL_BACKUP="${TMP_DIR}/alphacp-fpm-pool.original"
  cp -a "${POOL_FILE}" "${POOL_BACKUP}" || die "cannot back up PHP-FPM pool config"
  POOL_CHANGED=1
  sed -i -E "/^[[:space:]]*php_admin_value\\[open_basedir\\][[:space:]]*=/ s#\$#:${backup_root}#" "${POOL_FILE}"
  if ! grep -Fq "${backup_root}" "${POOL_FILE}"; then
    die "could not add backup path to PHP-FPM open_basedir"
  fi
  local fpm_binary
  fpm_binary="$(command -v "php-fpm${FPM_VERSION}" 2>/dev/null || true)"
  if [[ -n "${fpm_binary}" ]]; then
    "${fpm_binary}" -t >>"${LOG_FILE}" 2>&1 || die "PHP-FPM config test failed after open_basedir update"
  fi
  ok "private backup download path allowlisted in PHP-FPM"
}

# cPanel account import (0.69.0): the operator drops a migration archive
# (cpmove-<user>.tar.gz) into ${ACP_HOME}/incoming and the WHM transfer pages
# offer it. The panel only *reads names/sizes* from that one directory, so it is
# added to the pool open_basedir the same way the backup download path is.
ensure_import_read_access() {
  local drop_root="${ACP_HOME}/incoming"
  [[ -n "${POOL_FILE}" && -f "${POOL_FILE}" ]] || die "AlphaCP PHP-FPM pool file missing; cannot safely enable cPanel import"
  [[ ! -L "${drop_root}" ]] || die "cPanel import drop dir is a symlink: ${drop_root}"
  [[ ! -e "${drop_root}" || -d "${drop_root}" ]] || die "cPanel import drop dir is not a directory: ${drop_root}"
  getent group "${PANEL_GROUP}" >/dev/null 2>&1 || die "panel group missing: ${PANEL_GROUP}"
  install -d -o root -g "${PANEL_GROUP}" -m 0750 "${drop_root}" || die "cPanel import drop dir setup failed"
  ok "cPanel import drop dir ready → ${drop_root} (0750 root:${PANEL_GROUP})"

  local open_basedir_line
  open_basedir_line="$(grep -F -m1 'php_admin_value[open_basedir]' "${POOL_FILE}" || true)"
  [[ -n "${open_basedir_line}" ]] || die "FPM pool has no open_basedir directive: ${POOL_FILE}"
  if [[ "${open_basedir_line}" == *"${drop_root}"* ]]; then
    ok "cPanel import path already allowlisted in PHP-FPM"
    return 0
  fi

  if (( POOL_CHANGED == 0 )); then
    POOL_BACKUP="${TMP_DIR}/alphacp-fpm-pool.original"
    cp -a "${POOL_FILE}" "${POOL_BACKUP}" || die "cannot back up PHP-FPM pool config"
  fi
  POOL_CHANGED=1
  sed -i -E "/^[[:space:]]*php_admin_value\\[open_basedir\\][[:space:]]*=/ s#\$#:${drop_root}#" "${POOL_FILE}"
  if ! grep -Fq "${drop_root}" "${POOL_FILE}"; then
    die "could not add cPanel import path to PHP-FPM open_basedir"
  fi
  local fpm_binary
  fpm_binary="$(command -v "php-fpm${FPM_VERSION}" 2>/dev/null || true)"
  if [[ -n "${fpm_binary}" ]]; then
    "${fpm_binary}" -t >>"${LOG_FILE}" 2>&1 || die "PHP-FPM config test failed after open_basedir update"
  fi
  ok "cPanel import drop path allowlisted in PHP-FPM"
}

say ""
say "${C_BOLD}AlphaCP existing-server updater ${UPDATER_VERSION}${C_RESET}   (yahan '${UPDATER_VERSION}' dikhe = sahi command)"
say "Panel bundle: ${PANEL_VERSION}  ·  agent: ${AGENT_VERSION}"
say "PHP-FPM: ${FPM_UNIT} · PHP: $(${PHP_BIN} -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
say ""

TMP_DIR="$(mktemp -d /tmp/alphacp-update.XXXXXX)"
trap 'cleanup_preflight; rm -rf "${TMP_DIR}"' EXIT

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
grep -q "'db.user.create'" "${AGENT_ROOT}/config/tasks.php" || die "agent S8 MariaDB tasks missing"
grep -q "'db.restore'" "${AGENT_ROOT}/config/tasks.php" || die "agent S10 MySQL restore task missing"
grep -q "'backup.pull'" "${AGENT_ROOT}/config/tasks.php" || die "agent S10 remote pull task missing"
grep -q "'dns.bind'" "${AGENT_ROOT}/config/tasks.php" || die "agent S9 BIND9 task missing"

# S8: the agent talks to MariaDB through the client binary (socket auth, SQL on
# stdin). Install it when it is missing; the admin MariaDB server is assumed to
# be the distro package the server already runs.
if [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]] && [[ ! -x /usr/bin/mariadb && ! -x /usr/bin/mysql ]]; then
  if command -v apt-get >/dev/null 2>&1; then
    info "mariadb-client install ho raha hai (MySQL Databases provisioning)"
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-client >>"${LOG_FILE}" 2>&1; then
      ok "mariadb-client installed"
    else
      warn "mariadb-client install fail — db.* tasks client ke bina chalenge nahi"
    fi
  else
    warn "mariadb-client missing (apt-get nahi) — db.* tasks client ke bina chalenge nahi"
  fi
fi

# S10 remote pull + remote backup destinations: scp/ssh/ssh-keyscan/ssh-keygen
# chahiye (openssh-client). sshpass sirf password auth ke liye — na mile to key
# auth chalta rahega (agent saaf message dega).
if [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]]; then
  if command -v apt-get >/dev/null 2>&1; then
    if [[ ! -x /usr/bin/scp || ! -x /usr/bin/ssh || ! -x /usr/bin/ssh-keyscan || ! -x /usr/bin/ssh-keygen ]]; then
      info "openssh-client install ho raha hai (remote pull + backup destinations: scp/ssh/ssh-keyscan)"
      if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openssh-client >>"${LOG_FILE}" 2>&1; then
        ok "openssh-client installed"
      else
        warn "openssh-client install fail — backup.pull (remote se archive lana) kaam nahi karega"
      fi
    fi
    if [[ ! -x /usr/bin/sshpass ]]; then
      if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq sshpass >>"${LOG_FILE}" 2>&1; then
        ok "sshpass installed (password auth ke liye)"
      else
        warn "sshpass install nahi hua — remote pull sirf SSH key auth se chalega (theek hai)"
      fi
    fi
  fi
fi

# S9 DNS: real authoritative zones. bina bind9 ke dns.bind task JSON likh kar
# chhod deta hai — install hone ke baad hi named-checkzone/rndc/dig milte hain.
# Ubuntu 24.04 rndc ko /usr/sbin me rakhta hai par named-checkconf/named-checkzone
# /usr/bin me — isliye ek hi path check karne se "installed" jhooth bolta hai.
# Har tool ke liye sab candidates dhoondo (agent bhi yahi karta hai).
bind_find() {  # $1 tool
  local d=""
  command -v "$1" >/dev/null 2>&1 && { command -v "$1"; return 0; }
  for d in /usr/sbin /usr/bin /sbin /bin /usr/local/sbin /usr/local/bin; do
    [[ -x "${d}/$1" ]] && { printf '%s' "${d}/$1"; return 0; }
  done
  return 1
}
if [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]]; then
  BIND_CC="$(bind_find named-checkconf || true)"
  BIND_CZ="$(bind_find named-checkzone || true)"
  BIND_RC="$(bind_find rndc || true)"
  BIND_DG="$(bind_find dig || true)"
  if [[ -n "${BIND_CC}" && -n "${BIND_CZ}" && -n "${BIND_RC}" ]]; then
    ok "bind9 present (checkconf=${BIND_CC} checkzone=${BIND_CZ} rndc=${BIND_RC} dig=${BIND_DG:-none})"
  elif command -v apt-get >/dev/null 2>&1; then
    info "bind9 + bind9-utils + dnsutils install ho rahe hain (S9: asli DNS zones)"
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq bind9 bind9-utils dnsutils >>"${LOG_FILE}" 2>&1; then
      BIND_CC="$(bind_find named-checkconf || true)"
      BIND_CZ="$(bind_find named-checkzone || true)"
      if [[ -n "${BIND_CC}" && -n "${BIND_CZ}" ]]; then
        ok "bind9 installed (checkconf=${BIND_CC} checkzone=${BIND_CZ})"
      else
        warn "apt-get ne bind9 install kaha par named-checkconf/checkzone nahi mile — dns.bind sirf JSON likhega"
      fi
    else
      warn "bind9 install fail — dns.bind task JSON-only rahega, zones nahi banenge"
    fi
  else
    warn "bind9 missing (apt-get nahi) — dns.bind task JSON-only rahega"
  fi
fi

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

# S7 Email: Exim4 (MTA) + Dovecot (IMAP/POP3). Packages abhi install hote hain,
# configuration agle release me (`mail.server` task) — tabhi service enable hogi.
# Abhi install isliye: agle release me sirf config likhna aur verify karna bache,
# aur apt ka waqt (sabse dheema hissa) abhi nikal jaye.
if [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]]; then
  MAIL_MISSING=""
  for b in /usr/sbin/exim4 /usr/sbin/dovecot; do
    [[ -x "${b}" ]] || MAIL_MISSING="${MAIL_MISSING} ${b}"
  done
  if [[ -z "${MAIL_MISSING}" ]]; then
    ok "mail server packages present (exim4=$(/usr/sbin/exim4 -bV 2>/dev/null | head -1 | awk '{print $3}') dovecot=$(/usr/sbin/dovecot --version 2>/dev/null))"
  elif command -v apt-get >/dev/null 2>&1; then
    info "exim4 + dovecot install ho rahe hain (S7: asli email)"
    # debconf ke sawaal chup karane ke liye (warn: interactive prompt na aaye)
    {
      echo "exim4-config exim4/dc_eximconfig_configtype select internet"
      echo "exim4-config exim4/dc_mailname string $(hostname -f 2>/dev/null || hostname)"
      echo "exim4-config exim4/dc_local_interfaces string 127.0.0.1 ; ::1"
      echo "exim4-config exim4/dc_other_hostnames string"
      echo "exim4-config exim4/dc_localdelivery select maildir_home"
      echo "exim4-config exim4/dc_use_split_config boolean false"
      echo "exim4-config exim4/dc_relay_domains string"
      echo "exim4-config exim4/dc_relay_nets string"
      echo "exim4-config exim4/dc_smarthost string"
      echo "exim4-config exim4/dc_minimaldns boolean false"
      echo "exim4-config exim4/no_config boolean false"
    } | debconf-set-selections >/dev/null 2>&1 || true
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq exim4 exim4-daemon-light dovecot-core dovecot-imapd dovecot-pop3d >>"${LOG_FILE}" 2>&1; then
      ok "exim4 + dovecot installed"
    else
      warn "mail packages install fail — S7 email config agle release me dobara koshish karega"
    fi
  else
    warn "exim4/dovecot missing (apt-get nahi) — S7 email nahi chalega"
  fi

  # Configuration abhi adhuri hai: agle release (`mail.server`) tak service band
  # rahe — adha-configured mail server public port 25 par na khula rahe.
  if [[ -x /usr/sbin/exim4 || -x /usr/sbin/dovecot ]]; then
    MAIL_VERSIONS="exim4=$(/usr/sbin/exim4 -bV 2>/dev/null | head -1 | awk '{print $3}') dovecot=$(/usr/sbin/dovecot --version 2>/dev/null)"
    if [[ ! -f "${ACP_HOME}/etc/mail-server-configured" ]]; then
      systemctl stop exim4 >/dev/null 2>&1 || true
      systemctl stop dovecot >/dev/null 2>&1 || true
      systemctl disable exim4 >/dev/null 2>&1 || true
      systemctl disable dovecot >/dev/null 2>&1 || true
      info "mail services abhi band hain — agle release me configure hoke start honge (${MAIL_VERSIONS})"
    else
      ok "mail server pehle se configured hai (${MAIL_VERSIONS})"
    fi
  fi
fi

NEW_PANEL="${RELEASES}/panel-${STAMP}"
mkdir -p "${NEW_PANEL}"
tar xzf "${TMP_DIR}/panel-code.tar.gz" -C "${NEW_PANEL}" --strip-components=1
[[ -f "${NEW_PANEL}/artisan" ]] || die "new artisan missing"
# Panel ship-checks (artifact ke andar hi — NEW_PANEL ab set hai):
grep -q "MysqlUsersController" "${NEW_PANEL}/app/Http/Controllers/MysqlUsersController.php" \
  || die "panel MySQL Users page missing"
grep -q "db[.]restore" "${NEW_PANEL}/app/Support/BackupProvisioner.php" \
  || die "panel db.restore wiring missing"
grep -q "mysql_only" "${NEW_PANEL}/resources/views/transfer-restore/index.blade.php" \
  || die "panel cPanel MySQL restore option missing"
grep -q "enqueueRemotePull" "${NEW_PANEL}/app/Support/BackupProvisioner.php" \
  || die "panel remote pull wiring missing"
grep -q "transfer-tool.pull" "${NEW_PANEL}/routes/web.php" \
  || die "panel remote pull routes missing"
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

ensure_backup_read_access
ensure_import_read_access

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
  if (( POOL_CHANGED == 1 )) && [[ -f "${POOL_BACKUP}" ]]; then
    cp -a "${POOL_BACKUP}" "${POOL_FILE}" && POOL_CHANGED=0 \
      || warn "PHP-FPM pool config rollback failed; check ${POOL_FILE}"
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

# ---------------------------------------------------------------------------
# S10 scheduled backups: cron tick for Laravel's scheduler.
#
# `* * * * *` runs `artisan schedule:run`; the hourly `alphacp:scheduled-backups`
# entry then decides (from the WHM backup config + window marker) whether real
# archives are due. Without this file scheduled backups simply never fire, so it
# is installed with the update — idempotent, and any foreign file at that path is
# kept as a .bak instead of being silently dropped.
# ---------------------------------------------------------------------------
install -d -m 0755 "${ACP_HOME}/logs"
if [[ -n "${CRON_FILE}" ]]; then
  CRON_DIR="$(dirname "${CRON_FILE}")"
  CRON_CONTENT="# AlphaCP panel scheduler — S10 scheduled backups (panel ${PANEL_VERSION}). Managed by panel-update; do not edit.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${PANEL_USER} cd ${PANEL_ROOT} && env ACP_HOME=${ACP_HOME} ${PHP_BIN} artisan schedule:run >> ${PANEL_ROOT}/storage/logs/panel-schedule.log 2>&1"
  if [[ -f "${CRON_FILE}" ]] && ! grep -q '^# AlphaCP panel scheduler' "${CRON_FILE}"; then
    mv "${CRON_FILE}" "${CRON_FILE}.bak-${STAMP}" \
      && warn "${CRON_FILE} pehle se maujood tha (hamara nahi) — backup: ${CRON_FILE}.bak-${STAMP}"
  fi
  if install -d "${CRON_DIR}" 2>/dev/null && printf '%s\n' "${CRON_CONTENT}" > "${CRON_FILE}" 2>/dev/null; then
    chmod 0644 "${CRON_FILE}"
    chown root:root "${CRON_FILE}" 2>/dev/null || true
    ok "panel scheduler cron installed → ${CRON_FILE} (har minute schedule:run)"
  else
    rm -f "${CRON_FILE}" 2>/dev/null || true
    warn "cron file likha nahi ja saka (${CRON_FILE}) — scheduled backups tab tak nahi chalenge; root se dobara run karein"
  fi
  # cron daemon? (Ubuntu par 'cron' package; nahi ho to scheduled backups chup-chaap band rahenge)
  if ! command -v crontab >/dev/null 2>&1 && command -v apt-get >/dev/null 2>&1 && [[ -z "${ACP_SKIP_EXTRA_PACKAGES:-}" ]]; then
    info "cron package install ho raha hai (scheduled backups ke liye)"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq cron >>"${LOG_FILE}" 2>&1 \
      && ok "cron package installed" || warn "cron package install fail — scheduled backups manually check karein"
  fi
  if [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now cron >>"${LOG_FILE}" 2>&1 || warn "systemctl enable --now cron fail"
    if systemctl is-active --quiet cron; then
      ok "cron daemon active"
    else
      warn "cron daemon active nahi hai — scheduled backups nahi chalenge"
    fi
  fi
fi

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
