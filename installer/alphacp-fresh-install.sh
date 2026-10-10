#!/usr/bin/env bash
# =============================================================================
#  AlphaCP FRESH INSTALL (B4 part 2) — naye server par panel + agent + license
# -----------------------------------------------------------------------------
#  Ye script bootstrap (`install`) se chalti hai — alphacp-step1.sh (base stack)
#  ke BAAD. Pack = AWS live server ka exact replica (vendor samet, secrets nahi).
#
#  Phases:
#    1. PHP 8.4 pin + extensions (PHP 8.5 hazard guard)
#    2. panel user + pack extract -> /usr/local/alphacp
#    3. MariaDB database + panel.env (root:alphacp 0640)
#    4. .env + APP_KEY + migrate + seed (admin user env se)
#    5. fpm pool + certs + nginx vhosts (2083/2087/2098/8090) + cron + paneld
#    6. firewall + health checks + summary (admin password EK BAAR print)
#
#  License: ACP_LICENSE_KEY env (khali = 15-din trial auto) +
#           ACP_LICENSE_API_URL env (bootstrap AWS ka URL deta hai)
# =============================================================================
set -u -o pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL_ROOT="${ACP_HOME}/panel"
PANEL_USER="${PANEL_USER:-alphacp}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
PHP_V="8.4"
DB_NAME="${DB_NAME:-alphacp}"
DB_USER="${DB_USER:-alphacp}"
ETC_NGX_AVAIL="${ETC_NGX_AVAIL:-/etc/nginx/sites-available}"
ETC_NGX_ENABLED="${ETC_NGX_ENABLED:-/etc/nginx/sites-enabled}"
ETC_POOL="${ETC_POOL:-/etc/php/8.4/fpm/pool.d}"
ETC_SYSD="${ETC_SYSD:-/etc/systemd/system}"
ETC_CRON="${ETC_CRON:-/etc/cron.d}"
DEF_WWW="${DEF_WWW:-/var/www/alphacp-default}"
CERT_DIR="${CERT_DIR:-/etc/ssl/alphacp}"
SKIP_APT="${SKIP_APT:-0}"
LOG_FILE="/var/log/alphacp-fresh-install.log"; touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-fresh-install.log"
STAMP="$(date +%Y%m%d%H%M%S)"

PACK=""; ASSUME_YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pack) PACK="${2:-}"; shift ;;
    --pack=*) PACK="${1#*=}" ;;
    --yes) ASSUME_YES=1 ;;
    *) echo "unknown option: $1"; exit 1 ;;
  esac
  shift
done

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; say "    Log: ${LOG_FILE}"; exit 1; }
run_artisan() {
  ( cd "${PANEL_ROOT}" && sudo -u "${PANEL_USER}" env ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@" ) >>"${LOG_FILE}" 2>&1
}

say ""
say "${C_B}== AlphaCP FRESH INSTALL v1.0 ==${C_0}"
[[ "$(id -u)" -eq 0 ]] || die "root chahiye (sudo -i)"
[[ -n "${PACK}" && -f "${PACK}" ]] || die "--pack <alphacp-server.tar.gz> chahiye"
tar -tzf "${PACK}" >/dev/null 2>&1 || die "pack corrupt — dobara download karo"

LIC_KEY="${ACP_LICENSE_KEY:-}"
LIC_URL="${ACP_LICENSE_API_URL:-}"

# ---- SAFETY GUARD: galat server par na chale -------------------------------
if [[ -d /var/www/alphacp-get ]]; then
  die "Ye tumhara LICENSE/GET server (AWS) lagta hai — fresh install yahan NAHI chalani! Ye command BADE (naye) server ke liye hai."
fi
if [[ -d "${PANEL_ROOT}" && "${ACP_FORCE_REINSTALL:-0}" != "1" ]]; then
  die "AlphaCP yahan pehle se installed hai (${PANEL_ROOT}). Dobara install nahi hoga. Agar sach me reinstall chahiye to: ACP_FORCE_REINSTALL=1 ke saath chalao."
fi

# ---- Phase 1: PHP 8.4 pin (PHP 8.5 guard) -----------------------------------
say ""
say "-- Phase 1: PHP ${PHP_V} + extensions --"
if [[ "${SKIP_APT}" != "1" ]]; then
  export DEBIAN_FRONTEND=noninteractive
  if ! command -v "php${PHP_V}" >/dev/null 2>&1; then
    add-apt-repository -y ppa:ondrej/php >>"${LOG_FILE}" 2>&1 || true
    apt-get update -qq >>"${LOG_FILE}" 2>&1 || true
  fi
  apt-get install -y -qq "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-mysql" \
    "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-intl" \
    "php${PHP_V}-bcmath" "php${PHP_V}-mbstring" "php${PHP_V}-opcache" "php${PHP_V}-gd" \
    >>"${LOG_FILE}" 2>&1 || die "php${PHP_V} packages install fail"
fi
command -v "php${PHP_V}" >/dev/null 2>&1 || [[ -x "${PHP_BIN}" ]] || die "php${PHP_V} nahi mila"
ok "PHP ${PHP_V} ready (${PHP_BIN})"

# ---- Phase 2: panel user + pack extract -------------------------------------
say ""
say "-- Phase 2: pack extract -> ${ACP_HOME} --"
id -u "${PANEL_USER}" >/dev/null 2>&1 || useradd --system --home "${ACP_HOME}" --shell /usr/sbin/nologin "${PANEL_USER}" || die "user create fail"
STAGE="$(mktemp -d /tmp/alphacp-fresh.XXXXXX)"
trap 'rm -rf "${STAGE}"' EXIT
tar -xzf "${PACK}" -C "${STAGE}" || die "pack extract fail"
[[ -f "${STAGE}/alphacp/panel/artisan" && -d "${STAGE}/alphacp/panel/vendor" ]] || die "pack me panel/vendor nahi — pack kharab hai"
mkdir -p "${ACP_HOME}"
for d in panel agent bin share; do
  [[ -d "${STAGE}/alphacp/${d}" ]] || { warn "pack me ${d}/ nahi — skip"; continue; }
  [[ -d "${ACP_HOME}/${d}" ]] && mv "${ACP_HOME}/${d}" "${ACP_HOME}/${d}.bak-fresh-${STAMP}"
  cp -r "${STAGE}/alphacp/${d}" "${ACP_HOME}/${d}"
done
mkdir -p "${ACP_HOME}/logs" "${ACP_HOME}/etc" "${ACP_HOME}/var"
mkdir -p "${PANEL_ROOT}/storage/framework/cache/data" "${PANEL_ROOT}/storage/framework/sessions" \
         "${PANEL_ROOT}/storage/framework/views" "${PANEL_ROOT}/storage/logs" \
         "${PANEL_ROOT}/storage/app/private" "${PANEL_ROOT}/bootstrap/cache"
chown -R root:root "${ACP_HOME}/agent" "${ACP_HOME}/bin" "${ACP_HOME}/share" 2>/dev/null || true
chmod +x "${ACP_HOME}"/bin/* "${ACP_HOME}/agent/bin/paneld" 2>/dev/null || true
if [[ -f "${ACP_HOME}/bin/alphacp" ]]; then
  install -m 0755 "${ACP_HOME}/bin/alphacp" /usr/local/bin/alphacp 2>/dev/null || true
fi
chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"
find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type d -exec chmod 0770 {} \; 2>/dev/null || true
find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type f -exec chmod 0660 {} \; 2>/dev/null || true
chmod -R a+rX "${PANEL_ROOT}/public" 2>/dev/null || true
ok "code extract + permissions done"

# ---- Phase 3: database + panel.env ------------------------------------------
say ""
say "-- Phase 3: MariaDB database --"
systemctl start mariadb >>"${LOG_FILE}" 2>&1 || systemctl start mysql >>"${LOG_FILE}" 2>&1 || true
mysql -e "SELECT 1" >/dev/null 2>&1 || die "MariaDB nahi chal raha (step1 install karta hai) — systemctl status mariadb dekho"
PANEL_ENV="${ACP_HOME}/etc/panel.env"
if [[ -f "${PANEL_ENV}" ]] && grep -q ACP_DB_PASS "${PANEL_ENV}"; then
  DB_PASS="$(grep -m1 '^ACP_DB_PASS=' "${PANEL_ENV}" | cut -d= -f2-)"
  ok "purana panel.env reuse"
else
  DB_PASS="$(openssl rand -hex 16)"
fi
mysql >>"${LOG_FILE}" 2>&1 <<SQL || die "database create fail"
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
cat > "${PANEL_ENV}" <<EOF
# AlphaCP DB credentials — alphacp-fresh-install.sh $(date -u '+%F %T')Z
ACP_DB_HOST=127.0.0.1
ACP_DB_PORT=3306
ACP_DB_NAME=${DB_NAME}
ACP_DB_USER=${DB_USER}
ACP_DB_PASS=${DB_PASS}
EOF
chown "root:${PANEL_USER}" "${PANEL_ENV}" && chmod 0640 "${PANEL_ENV}"
ok "database '${DB_NAME}' + panel.env ready"

# ---- Phase 4: .env + APP_KEY + migrate + seed ---------------------------------
say ""
say "-- Phase 4: panel .env + database tables + admin --"
PUBIP="$(curl -s --max-time 5 https://checkip.amazonaws.com 2>/dev/null | tr -d '\n' || true)"
[[ -n "${PUBIP}" ]] || PUBIP="$(hostname -I 2>/dev/null | awk '{print $1}')"
ADMIN_PASS="$(openssl rand -base64 12 | tr -d '/+=' | head -c 14)"
OLD_KEY=""
[[ -f "${PANEL_ROOT}/.env" ]] && OLD_KEY="$(grep -m1 '^APP_KEY=' "${PANEL_ROOT}/.env" | cut -d= -f2- || true)"
cat > "${PANEL_ROOT}/.env" <<EOF
# AlphaCP panel — alphacp-fresh-install.sh $(date -u '+%F %T')Z
APP_NAME=AlphaCP
APP_ENV=production
APP_KEY=${OLD_KEY}
APP_DEBUG=false
APP_URL=https://${PUBIP}:2087
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql

SESSION_DRIVER=database
SESSION_LIFETIME=30
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync

ACP_HOME=${ACP_HOME}
ACP_SERVER_ID=1
ACP_SERVER_NAME=$(hostname -s)

ACP_ADMIN_USER=admin
ACP_ADMIN_PASSWORD=${ADMIN_PASS}
ACP_ADMIN_EMAIL=
ACP_ADMIN_FORCE_CHANGE=true

ACP_FRAME_OPTIONS=DENY

# ---- License (AWS license server) ----
ACP_LICENSE_KEY=${LIC_KEY}
ACP_LICENSE_API_URL=${LIC_URL}
ACP_LICENSE_INSECURE=true
EOF
PACK_VER="$(tr -d '[:space:]' < "${ACP_HOME}/share/VERSION" 2>/dev/null || true)"
if [[ -n "${PACK_VER}" ]]; then
  printf 'ACP_VERSION=%s\nACP_AGENT_VERSION=%s\n' "${PACK_VER}" "${PACK_VER}" >> "${PANEL_ROOT}/.env"
fi
chown "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/.env" && chmod 0640 "${PANEL_ROOT}/.env"
if [[ -z "${OLD_KEY}" ]]; then
  run_artisan key:generate --force || die "APP_KEY generate fail"
fi
run_artisan migrate --force || die "migrate fail — ${PANEL_ROOT}/storage/logs/laravel.log dekho"
run_artisan db:seed --class=RolesAndPermissionsSeeder --force || warn "roles seed warning (log dekho)"
run_artisan db:seed --class=AdminUserSeeder --force || die "admin seed fail"
# Seeder purane admin ko update nahi karta — printed password HAMESHA sahi ho,
# isliye password yahan explicitly set karo (bcrypt).
ADMIN_HASH="$(sudo -u "${PANEL_USER}" "${PHP_BIN}" -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")"
mysql "${DB_NAME}" -e "UPDATE users SET password_hash='${ADMIN_HASH}', force_password_change=1 WHERE username='admin';" >>"${LOG_FILE}" 2>&1 || warn "admin password sync warning"
run_artisan view:clear || true
run_artisan route:clear || true
run_artisan config:clear || true
ok "tables (70 migrations) + roles + admin user ready"

# ---- Phase 5: fpm pool + certs + nginx + cron + paneld -------------------------
say ""
say "-- Phase 5: services (fpm, nginx, paneld, cron) --"

# Webmail (Roundcube) + phpMyAdmin — cPanel-style companion apps
say "   webmail (roundcube) + phpmyadmin install..."
export DEBIAN_FRONTEND=noninteractive
echo "roundcube-core roundcube/dbconfig-install boolean true" | debconf-set-selections 2>/dev/null || true
echo "phpmyadmin phpmyadmin/dbconfig-install boolean true" | debconf-set-selections 2>/dev/null || true
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections 2>/dev/null || true
apt-get install -y roundcube roundcube-mysql phpmyadmin >>"${LOG_FILE}" 2>&1 \
  && ok "roundcube + phpmyadmin installed" \
  || warn "roundcube/phpmyadmin install warning (log: ${LOG_FILE}) — webmail/pma baad me install ho sakte hain"

# ---- SSO bundle apply (pma shim + roundcube plugin + secrets) ----
apply_sso_bundle() {
  local SSOB="$1"
  [[ -d "${SSOB}" ]] || return 0
  if [[ -f "${SSOB}/pma/acp-signon.php" && -d /usr/share/phpmyadmin ]]; then
    install -m 0644 "${SSOB}/pma/acp-signon.php" /usr/share/phpmyadmin/acp-signon.php
  fi
  if [[ -f "${SSOB}/pma/conf.d-acp-signon.php" && -d /etc/phpmyadmin ]]; then
    mkdir -p /etc/phpmyadmin/conf.d
    install -m 0644 "${SSOB}/pma/conf.d-acp-signon.php" /etc/phpmyadmin/conf.d/acp-signon.php
  fi
  if [[ -d "${SSOB}/roundcube/acp_sso" && -d /usr/share/roundcube/plugins ]]; then
    rm -rf /usr/share/roundcube/plugins/acp_sso
    cp -r "${SSOB}/roundcube/acp_sso" /usr/share/roundcube/plugins/acp_sso
    chmod -R a+rX /usr/share/roundcube/plugins/acp_sso
    # Debian: roundcube web-root /var/lib/roundcube — plugin symlink zaroori
    if [[ -d /var/lib/roundcube/plugins && ! -e /var/lib/roundcube/plugins/acp_sso ]]; then
      ln -s /usr/share/roundcube/plugins/acp_sso /var/lib/roundcube/plugins/acp_sso
    fi
  fi
  local RC_CFG=/etc/roundcube/config.inc.php
  if [[ -f "${RC_CFG}" ]] && ! grep -q "acp_sso" "${RC_CFG}"; then
    printf '\n$config["plugins"][] = "acp_sso"; // AlphaCP webmail one-click SSO\n' >> "${RC_CFG}"
  fi
  # panel user ko www-data group me daalo (secrets root:www-data 0640 — dono readers cover)
  if id -u "${PANEL_USER:-alphacp}" >/dev/null 2>&1; then
    id -nG "${PANEL_USER:-alphacp}" | grep -qw www-data || usermod -aG www-data "${PANEL_USER:-alphacp}" 2>/dev/null || true
  fi
  local sf f
  for sf in pma-sso.secret webmail-sso.secret; do
    f="${ACP_HOME}/etc/${sf}"
    if [[ ! -s "${f}" ]]; then openssl rand -hex 32 > "${f}"; fi
    chown root:www-data "${f}" 2>/dev/null || true; chmod 0640 "${f}"
  done
  if [[ -d /etc/phpmyadmin && ! -s /etc/phpmyadmin/acp-blowfish.secret ]]; then
    openssl rand -hex 16 > /etc/phpmyadmin/acp-blowfish.secret
    chown root:www-data /etc/phpmyadmin/acp-blowfish.secret 2>/dev/null || true
    chmod 0640 /etc/phpmyadmin/acp-blowfish.secret
  fi
  ok "SSO bundle applied (pma shim + roundcube plugin + secrets)"
}

# ---- Mail stack (exim4 + dovecot) install + agent setup ----
ensure_mail_stack() {
  local need=0 p
  for p in exim4-daemon-heavy dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve; do
    dpkg -s "$p" >/dev/null 2>&1 || need=1
  done
  if [[ "$need" == "1" ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y exim4 exim4-daemon-heavy \
      dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve \
      dovecot-managesieved >>"${LOG_FILE}" 2>&1 \
      && ok "mail stack installed (exim4 + dovecot)" \
      || warn "mail packages install fail — log dekho: ${LOG_FILE}"
  fi
  # agent se exim+dovecot ki AlphaCP config lagao (idempotent)
  if [[ -x /usr/local/alphacp/agent/bin/paneld || -f /usr/local/alphacp/agent/bin/paneld ]]; then
    if php8.4 /usr/local/alphacp/agent/bin/paneld --run mail.server '{"action":"setup"}' >>"${LOG_FILE}" 2>&1; then
      ok "mail.server setup applied (exim+dovecot AlphaCP config)"
    else
      warn "mail.server setup fail — log: ${LOG_FILE} (WHM se dobara chala sakte ho)"
    fi
  fi
  systemctl enable --now dovecot exim4 >>"${LOG_FILE}" 2>&1 || true
  systemctl restart dovecot >>"${LOG_FILE}" 2>&1 || true
  systemctl restart exim4 >>"${LOG_FILE}" 2>&1 || true
}


EB="${STAGE}/etc-bundle"
apply_sso_bundle "${EB}/sso"
ensure_mail_stack
install -m 644 "${EB}/pool.d/alphacp.conf" "${ETC_POOL}/alphacp.conf" || die "fpm pool install fail"
[[ -d "${EB}/systemd/php8.4-fpm.service.d" ]] && cp -r "${EB}/systemd/php8.4-fpm.service.d" "${ETC_SYSD}/"
systemctl daemon-reload >>"${LOG_FILE}" 2>&1 || true
systemctl restart "php${PHP_V}-fpm" >>"${LOG_FILE}" 2>&1 || die "php-fpm restart fail"
ok "fpm pool (user ${PANEL_USER}, socket alphacp-fpm.sock)"

if [[ ! -f "${CERT_DIR}/panel.crt" ]]; then
  mkdir -p "${CERT_DIR}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout "${CERT_DIR}/panel.key" -out "${CERT_DIR}/panel.crt" -subj "/CN=alphacp-panel" >>"${LOG_FILE}" 2>&1 || die "cert fail"
fi
ok "SSL cert ready (self-signed; domain par asli lagega)"

ADDED=()
for f in "${EB}"/nginx/alphacp-*.conf; do
  [[ -f "$f" ]] || continue
  b="$(basename "$f")"
  install -m 644 "$f" "${ETC_NGX_AVAIL}/${b}"
  ln -sf "${ETC_NGX_AVAIL}/${b}" "${ETC_NGX_ENABLED}/${b}"
  ADDED+=("${b}")
done
[[ "${#ADDED[@]}" -gt 0 ]] || die "pack me nginx vhosts nahi"
# Ubuntu ki stock "default" site :80 maangti hai — :80 Apache ka hai (customer
# sites). Hatao warna nginx start hi nahi hoga.
rm -f "${ETC_NGX_ENABLED}/default"
if nginx -t >>"${LOG_FILE}" 2>&1; then
  systemctl enable nginx >>"${LOG_FILE}" 2>&1 || true
  systemctl restart nginx >>"${LOG_FILE}" 2>&1 || die "nginx start fail — systemctl status nginx dekho"
  ok "nginx vhosts live: ${ADDED[*]}"
else
  for b in "${ADDED[@]}"; do rm -f "${ETC_NGX_ENABLED}/${b}" "${ETC_NGX_AVAIL}/${b}"; done
  die "nginx -t fail — naye vhosts hata diye (nginx config pack check karo)"
fi

mkdir -p "${DEF_WWW}" && [[ -d "${EB}/default-www" ]] && cp -r "${EB}/default-www/." "${DEF_WWW}/"
for f in "${EB}"/cron.d/*; do [[ -f "$f" ]] && install -m 644 "$f" "${ETC_CRON}/$(basename "$f")"; done
ok "cron jobs installed (scheduler + entry-ports)"

# ---- agent plumbing: database.env + server registration (paneld inke bina mute) ----
DBENV="${ACP_HOME}/etc/database.env"
install -m 0640 -o root -g www-data /dev/null "${DBENV}"
cat > "${DBENV}" <<EOF
# AlphaCP database credentials — generated by alphacp-fresh-install.sh
ACP_DB_HOST=127.0.0.1
ACP_DB_PORT=3306
ACP_DB_NAME=${DB_NAME}
ACP_DB_USER=${DB_USER}
ACP_DB_PASS=${DB_PASS}
ACP_SERVER_ID=1
EOF
chown root:www-data "${DBENV}"; chmod 0640 "${DBENV}"

HOST_SHORT="$(hostname -s)"
PRIV_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
PUB_IP="$(curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null || true)"; [[ -n "${PUB_IP}" ]] || PUB_IP="${PRIV_IP}"
OS_PRETTY="$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-unknown}")"
CPU_N="$(nproc)"; RAM_MB="$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)"
DISK_GB="$(df -BG / | awk 'NR==2{print $2}' | tr -dc '0-9')"
mysql "${DB_NAME}" >>"${LOG_FILE}" 2>&1 <<SQL || die "server register fail"
INSERT INTO servers (name, hostname, role, public_ip, private_ip, os, arch, panel_version, specs, created_at, updated_at)
VALUES ('${HOST_SHORT}', '${HOST_SHORT}', 'central', '${PUB_IP}', '${PRIV_IP}', '${OS_PRETTY}',
        '$(uname -m)', '0.2.0', JSON_OBJECT('cpu', ${CPU_N}, 'ram_mb', ${RAM_MB}, 'disk_gb', ${DISK_GB}), NOW(), NOW())
ON DUPLICATE KEY UPDATE
  public_ip = VALUES(public_ip), private_ip = VALUES(private_ip), os = VALUES(os),
  arch = VALUES(arch), specs = VALUES(specs), updated_at = NOW();
SQL
SRV_ID="$(mysql -N -B "${DB_NAME}" -e "SELECT id FROM servers WHERE hostname='${HOST_SHORT}' LIMIT 1")"
[[ -n "${SRV_ID}" ]] || die "server id read fail"
sed -i "s/^ACP_SERVER_ID=.*/ACP_SERVER_ID=${SRV_ID}/" "${DBENV}"
ok "agent plumbing ready (database.env + server id=${SRV_ID})"

sed "s#^ExecStart=.*#ExecStart=${PHP_BIN} ${ACP_HOME}/agent/bin/paneld --daemon#" "${EB}/systemd/paneld.service" > "${ETC_SYSD}/paneld.service"
systemctl daemon-reload >>"${LOG_FILE}" 2>&1 || true
systemctl enable --now paneld >>"${LOG_FILE}" 2>&1 || die "paneld start fail"
ok "paneld agent running (auto-restart + boot par auto-start)"

# ---- Phase 6: firewall + health + summary --------------------------------------
say ""
say "-- Phase 6: firewall + health checks --"
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  for p in 2083 2087 2098 8090; do ufw allow "${p}/tcp" >>"${LOG_FILE}" 2>&1 || true; done
  ok "ufw: panel ports open"
fi
sleep 2
FAIL=0
for p in 2083 2087; do
  code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://127.0.0.1:${p}/login" 2>/dev/null || echo 000)"
  [[ "${code}" == "200" || "${code}" == "302" ]] && ok "health :${p} -> HTTP ${code}" || { warn "health :${p} -> HTTP ${code}"; FAIL=1; }
done
[[ "${FAIL}" -eq 0 ]] || warn "koi health check fail — panel phir bhi aage check karo (self-signed + first boot cache ho sakta hai)"

say ""
say "${C_G}==> ALPHACP FRESH INSTALL COMPLETE ✅${C_0}"
say "    WHM (admin)   : ${C_B}https://${PUBIP}:2087/${C_0}"
say "    cPanel        : ${C_B}https://${PUBIP}:2083/${C_0}"
say "    Webmail       : ${C_B}https://${PUBIP}:2098/${C_0}"
say "    Login         : ${C_B}admin${C_0}"
say "    Password      : ${C_B}${ADMIN_PASS}${C_0}   <- ABHI NOTE KARO (pehle login par change hoga)"
if [[ -n "${LIC_KEY}" ]]; then
  say "    License       : key set — panel pehli baar khulne par AWS se activate karega"
else
  say "    License       : 15-din TRIAL auto — AWS :2087 License Server se key banakar"
  say "                    ${PANEL_ROOT}/.env me ACP_LICENSE_KEY= set karna"
fi
say "    (Cloud firewall me 2083/2087/2098 TCP open karna mat bhulna)"
exit 0
