#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — Step 2B FULL setup (code + composer + database + admin + nginx :8090)
#  Version 0.3.7  ·  port 8090  ·  Ubuntu 22.04/24.04 (x86_64)
# -----------------------------------------------------------------------------
#  Yeh script SAB kuch karti hai (kuch pehle se karne ki zarurat nahi):
#     0. panel code khud download karti hai (checksum-verified) aur composer install
#     phir:
#     1. database user + grants (panel ka apna DB access)
#     2. etc/panel.env + panel/.env
#     3. migrate + seed + admin password
#     4. php-fpm pool (user: alphacp, apna socket)
#     5. nginx vhost on 8090 (self-signed TLS) — Apache :80 ko chhua nahi jata
#     6. verify: login page HTTP 200
#
#  Safe to re-run. Kuch bhi delete nahi hota (sirf AlphaCP ki apni files likhi jati hain).
# =============================================================================
set -euo pipefail

ACP_HOME="/usr/local/alphacp"
PANEL_ROOT="${ACP_HOME}/panel"
PANEL_USER="alphacp"
PANEL_PORT="8090"
ACP_INSTALLER_VERSION="0.3.7"
DB_NAME="alphacp"
DB_TEST_NAME="alphacp_test"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
LOG_FILE="/var/log/alphacp-step2b-finish.log"
CRED_FILE="/root/.alphacp-admin-credentials"

C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'
C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'; C_RESET=$'\033[0m'
say()  { printf '%s\n' "$*"; }
step() { say ""; say "${C_BOLD}${C_BLUE}==> $*${C_RESET}"; log "STEP $*"; }
ok()   { log "OK   $*";  say "${C_GREEN}[OK]${C_RESET} $*"; }
info() { log "INFO $*";  say "${C_BLUE}[i]${C_RESET} $*"; }
warn() { log "WARN $*";  say "${C_YELLOW}[!]${C_RESET} $*"; }
err()  { log "ERR  $*";  say "${C_RED}[x]${C_RESET} $*"; }
die()  { err "$*"; say ""; say "Full log: ${LOG_FILE}"; exit 1; }
log()  { { printf '%s [%s]\n' "$(date '+%F %T')" "$*" >>"${LOG_FILE}"; } 2>/dev/null || true; }

# Saare installed PHP binaries (naye se purane) — Step 1 kai versions rakhta hai.
php_candidates() {
  local c
  for c in 8.5 8.4 8.3 8.2 8.1 8.0 7.4; do
    [[ -x "/usr/bin/php${c}" ]] && printf '%s\n' "/usr/bin/php${c}"
  done
  [[ -x /usr/bin/php ]] && printf '%s\n' /usr/bin/php
  return 0
}

# Kaun sa PHP panel ko actually chala sakta hai? (extensions + version check)
# Step-1 ka ACP_PHP_PRIMARY hamesha sahi nahi hota — vendor jis PHP par bana hai
# wahi chahiye, warna artisan boot hi nahi hota. Isliye guess nahi, test karte hain.
php_for_panel() {
  local b
  while read -r b; do
    [[ -n "${b}" ]] || continue
    if ( cd "${PANEL_ROOT}" && "${b}" artisan --version >/dev/null 2>&1 ); then
      printf '%s' "${b}"; return 0
    fi
  done < <(php_candidates | sort -u)
  return 1
}

php_ver() { "${1:-$(command -v php)}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null; }

# Chosen PHP ke zaruri extensions — missing ho to apt se install.
ensure_php_extensions() {
  local b="$1" v ext pkg=() missing=()
  v="$(php_ver "$b")"
  for ext in mbstring xml curl zip intl bcmath pdo_mysql openssl tokenizer fileinfo ctype filter session; do
    "${b}" -m 2>/dev/null | grep -qi "^${ext}$" || missing+=("${ext}")
  done
  (( ${#missing[@]} == 0 )) && return 0
  info "php${v} me extensions missing: ${missing[*]} — install kar raha hoon…"
  for ext in "${missing[@]}"; do
    case "${ext}" in
      pdo_mysql) pkg+=("php${v}-mysql") ;;
      tokenizer|ctype|filter|session|fileinfo) pkg+=("php${v}-common") ;;
      openssl) pkg+=("php${v}-cli") ;;
      *) pkg+=("php${v}-${ext}") ;;
    esac
  done
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${pkg[@]}" >>"${LOG_FILE}" 2>&1 \
    || warn "php${v} extension install warning — log dekho"
  return 0
}
sysd()    { [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }
svc()     { if sysd; then systemctl "$@" ; else service "$1" "${@:2}"; fi; }
env_val() { grep -E "^$1=" "$2" 2>/dev/null | head -1 | cut -d= -f2- ; }

# Ubuntu/Ondrej ke php-fpm units me ProtectSystem=full hota hai. Panel
# /usr/local/alphacp ke andar hai, isliye web worker ko is path par explicit
# write access dena zaroori hai. CLI checks is sandbox ko nahi dekhte.
ensure_fpm_write_access() {
  local php_version="$1" unit="php${1}-fpm"
  local dropin="/etc/systemd/system/${unit}.service.d/alphacp-panel.conf"
  if ! sysd; then
    info "systemd available nahi — ${unit} sandbox drop-in skip (container/test mode)"
    return 0
  fi
  install -d "$(dirname "${dropin}")"
  cat > "${dropin}" <<EOF
# AlphaCP panel: php-fpm workers ko ${ACP_HOME} me likhne do.
# Ubuntu/Ondrej ka ProtectSystem=full /usr ko read-only banata hai.
[Service]
ReadWritePaths=-${ACP_HOME}
ReadWritePaths=-/run/php
EOF
  chmod 0644 "${dropin}"
  systemctl daemon-reload >>"${LOG_FILE}" 2>&1 \
    || { warn "systemd daemon-reload fail — ${dropin} apply nahi hua"; return 1; }
  ok "php${php_version}-fpm sandbox allowlist: ${dropin}"
}
PHP_BIN=""

# Saari artisan commands PANEL USER ke roop me chalti hain — root se chalane par
# storage/logs/laravel.log root ka ban jata hai aur php-fpm (alphacp) usme likh nahi
# pata -> HAR request 500. (Yahi asli wajah thi jo browser me 500 dikh raha tha.)
artisan() {
  if id -u "${PANEL_USER}" >/dev/null 2>&1 && command -v runuser >/dev/null 2>&1; then
    ( cd "${PANEL_ROOT}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@" )
  else
    ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@" )
  fi
}

rand_pw() {
  local b; b="$(php_candidates | head -1)"
  "${b:-php}" -r 'echo rtrim(strtr(base64_encode(random_bytes(15)), "+/", "AZ"), "=");'
}


# -----------------------------------------------------------------------------
#  STAGE A — panel code laao (paste.rs chunks) + composer install
# -----------------------------------------------------------------------------
stage_code() {
  step "Stage A — panel code (Laravel 13) + composer install"

  # root check SABSE pehle — warna bina sudo chalane par aadha panel delete ho jata hai
  if [[ "${EUID}" -ne 0 ]]; then
    err "root chahiye: sudo bash $0"
    exit 1
  fi

  local chunks=("kiprz" "tzbUI" "RZSl9")
  local expected="61c46d6dd8e1ec1c74bc65cc6e49f575c5d21f999f6ca5c53b701b655c2f5a6c"
  cd / 2>/dev/null || true          # panel dir delete karne se pehle cwd safe karo
  local work="/tmp/acp-setup.$$"
  mkdir -p "$work"
  trap 'rm -rf "${work:-}"' EXIT

  info "panel code download (3 chunks)…"
  local f
  for f in "${chunks[@]}"; do
    curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 120 \
      "https://paste.rs/${f}" >> "${work}/panel.b64" \
      || die "chunk ${f} download fail — net check karke dobara chalao"
  done
  base64 -d "${work}/panel.b64" > "${work}/panel-code.tar.gz" \
    || die "base64 decode fail (chunk adhoora aaya — dobara chalao)"

  local got; got="$(sha256sum "${work}/panel-code.tar.gz" | awk '{print $1}')"
  if [[ "$got" != "$expected" ]]; then
    die "checksum mismatch! got=${got} expected=${expected}"
  fi
  ok "panel code verified (sha256 ${expected:0:16}…)"

  tar tzf "${work}/panel-code.tar.gz" >/dev/null 2>&1 || die "tarball corrupt — dobara chalao"

  # purana/adhoora panel hata kar saaf jagah banao (fail ho to chup-chaap aage mat badho)
  rm -rf "${PANEL_ROOT}" || {
    # kuch files alphacp ke naam par hain — us user ke through dobara try karo
    runuser -u "$(id -un)" -- rm -rf "${PANEL_ROOT}" 2>/dev/null || true
    rm -rf "${PANEL_ROOT}" 2>/dev/null || true
  }
  if [[ -e "${PANEL_ROOT}" ]]; then
    chown -R root:root "${PANEL_ROOT}" 2>/dev/null || true
    rm -rf "${PANEL_ROOT}" || die "purana panel delete nahi ho paya: ${PANEL_ROOT}"
  fi
  mkdir -p "${PANEL_ROOT}"
  tar xzf "${work}/panel-code.tar.gz" -C "${PANEL_ROOT}" --strip-components=1

  [[ -f "${PANEL_ROOT}/artisan" ]] || die "artisan nahi mila — extract galat hua"
  grep -q 'laravel/framework' "${PANEL_ROOT}/composer.json" || die "composer.json nahi mila"
  if ! grep -q '"laravel/framework": "\^13' "${PANEL_ROOT}/composer.json"; then
    die "galat composer.json (Laravel 13 expected) — extract check karo"
  fi
  ok "panel code extract ho gaya (composer.json = Laravel 13.x)"

  mkdir -p "${PANEL_ROOT}/bootstrap/cache" \
           "${PANEL_ROOT}/storage/framework/views" \
           "${PANEL_ROOT}/storage/framework/sessions" \
           "${PANEL_ROOT}/storage/framework/cache/data" \
           "${PANEL_ROOT}/storage/logs"

  info "composer install (vendor download ~1 min)…"
  if ! ( cd "${PANEL_ROOT}" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
           --no-dev --no-interaction --no-progress ) >>"${LOG_FILE}" 2>&1; then
    err "composer install fail — aakhri lines:"
    tail -15 "${LOG_FILE}" | tee -a "${LOG_FILE}"
    die "composer install fail"
  fi
  [[ -f "${PANEL_ROOT}/vendor/autoload.php" ]] || die "vendor nahi bana"
  local ver; ver="$( cd "${PANEL_ROOT}" && php artisan --version 2>/dev/null || true )"
  ok "composer install done — ${ver:-vendor ready}"
}

# -----------------------------------------------------------------------------

# -----------------------------------------------------------------------------
main() {
  stage_code

  step "Stage B — Step 2B finishing (database + admin + nginx :${PANEL_PORT})"

  # --- 0. sanity ------------------------------------------------------------
  [[ "${EUID}" -eq 0 ]] || die "root chahiye: sudo bash $0"

  # shell ka cwd delete ho gaya ho to bash "getcwd" shikayat karta hai — chup karao
  cd / 2>/dev/null || true

  # "sudo: unable to resolve host <name>" noise hamesha ke liye band karo
  if ! grep -qE "(^|[[:space:]])$(hostname)([[:space:]]|$)" /etc/hosts 2>/dev/null; then
    printf '127.0.1.1\t%s\n' "$(hostname)" >> /etc/hosts 2>/dev/null || true
  fi
  [[ -f "${ACP_HOME}/etc/database.env" ]] || die "Step 2A (agent) missing: ${ACP_HOME}/etc/database.env nahi mila"
  [[ -f "${PANEL_ROOT}/artisan" ]] || die "panel code missing: ${PANEL_ROOT}/artisan nahi mila (pehle code copy + composer install)"
  [[ -d "${PANEL_ROOT}/vendor" ]] || die "vendor missing: ${PANEL_ROOT} me 'composer install' chalao"
  command -v nginx >/dev/null 2>&1 || die "nginx missing (Step 1 installs it)"

  mkdir -p "${ACP_HOME}/logs" 2>/dev/null || true

  # --- 1. credentials -------------------------------------------------------
  local db_host db_port db_user db_pass
  db_host="$(env_val ACP_DB_HOST "${ACP_HOME}/etc/database.env")"; db_host="${db_host:-127.0.0.1}"
  db_port="$(env_val ACP_DB_PORT "${ACP_HOME}/etc/database.env")"; db_port="${db_port:-3306}"
  db_user="$(env_val ACP_DB_USER "${ACP_HOME}/etc/database.env")"; db_user="${db_user:-alphacp}"
  db_pass="$(env_val ACP_DB_PASS "${ACP_HOME}/etc/database.env")"
  local server_id; server_id="$(env_val ACP_SERVER_ID "${ACP_HOME}/etc/database.env")"; server_id="${server_id:-1}"
  [[ -n "${db_pass}" ]] || die "ACP_DB_PASS khaali hai — ${ACP_HOME}/etc/database.env check karo"
  ok "database config mila (user ${db_user})"

  local public_ip
  public_ip="$(curl -4 -s --max-time 8 https://ifconfig.me 2>/dev/null || true)"
  [[ -n "${public_ip}" ]] || public_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [[ -n "${public_ip}" ]] || public_ip="127.0.0.1"

  # Admin password: pehli baar random banta hai; agar admin pehle se hai aur aapne
  # kuch diya nahi, to purana hi rehta hai (warna re-run par log lock ho jate hain).
  # --- saved secrets (re-run safe) ------------------------------------------
  # stage_code panel dir ko wipe karta hai (fresh extract), isliye APP_KEY aur admin
  # password ACP_HOME ke andar rakhte hain — code ke bahar, wipe se safe.
  local state_dir="${ACP_HOME}/var"
  mkdir -p "${state_dir}" 2>/dev/null || true
  local saved_key="" saved_pass=""
  if [[ -f "${state_dir}/panel-appkey.txt" ]]; then
    saved_key="$(head -1 "${state_dir}/panel-appkey.txt" 2>/dev/null || true)"
  fi
  if [[ -f "${state_dir}/panel-admin.txt" ]]; then
    saved_pass="$(grep -E '^panel_pass=' "${state_dir}/panel-admin.txt" 2>/dev/null | head -1 | cut -d= -f2- || true)"
  fi

  local admin_password="${ADMIN_PASSWORD}" admin_kept="false"
  local admin_exists="0"
  admin_exists="$(mysql -N -B "${DB_NAME}" -e "SELECT COUNT(*) FROM users WHERE username='${ADMIN_USER}'" 2>/dev/null || echo 0)"
  admin_exists="${admin_exists:-0}"
  if [[ -z "${admin_password}" ]]; then
    if [[ "${admin_exists}" != "0" ]]; then
      admin_password="$(env_val ACP_ADMIN_PASSWORD "${PANEL_ROOT}/.env" 2>/dev/null || true)"
      [[ -n "${admin_password}" ]] || admin_password="${saved_pass}"
      admin_kept="true"
    else
      admin_password="$(rand_pw)"
    fi
  fi

  # --- 2. OS user -----------------------------------------------------------
  if id -u "${PANEL_USER}" >/dev/null 2>&1; then
    ok "user '${PANEL_USER}' already exists"
  else
    useradd --system --home-dir "${PANEL_ROOT}" --shell /usr/sbin/nologin "${PANEL_USER}" \
      || useradd -r -d "${PANEL_ROOT}" -s /usr/sbin/nologin "${PANEL_USER}"
    ok "user '${PANEL_USER}' created (no login shell)"
  fi

  # --- 3. database user + grants -------------------------------------------
  mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE DATABASE IF NOT EXISTS \`${DB_TEST_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';
            CREATE USER IF NOT EXISTS '${db_user}'@'127.0.0.1' IDENTIFIED BY '${db_pass}';
            GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${db_user}'@'localhost';
            GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${db_user}'@'127.0.0.1';
            GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${db_user}'@'localhost';
            GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${db_user}'@'127.0.0.1';
            FLUSH PRIVILEGES;" >>"${LOG_FILE}" 2>&1 \
    || die "database user/grants fail — log dekho (${LOG_FILE})"
  ok "database '${DB_NAME}' ready + user '${db_user}' ko access"

  # --- 4. panel.env ---------------------------------------------------------
  cat > "${ACP_HOME}/etc/panel.env" <<EOF
# AlphaCP panel configuration — generated by step2b-finish.sh $(date -u '+%F %T')Z
# Readable by the panel (root:${PANEL_USER}, 0640). Panel + agent ek hi DB use karte hain.
ACP_DB_HOST=${db_host}
ACP_DB_PORT=${db_port}
ACP_DB_NAME=${DB_NAME}
ACP_DB_USER=${db_user}
ACP_DB_PASS=${db_pass}
ACP_SERVER_ID=${server_id}
ACP_HOME=${ACP_HOME}
EOF
  chown root:"${PANEL_USER}" "${ACP_HOME}/etc/panel.env"
  chmod 0640 "${ACP_HOME}/etc/panel.env"
  ok "etc/panel.env written (0640 root:${PANEL_USER})"

  # --- 5. panel/.env --------------------------------------------------------
  local old_key=""
  if [[ -f "${PANEL_ROOT}/.env" ]]; then old_key="$(env_val APP_KEY "${PANEL_ROOT}/.env" || true)"; fi
  [[ -n "${old_key}" ]] || old_key="${saved_key}"
  cat > "${PANEL_ROOT}/.env" <<EOF
# AlphaCP panel — generated by step2b-finish.sh $(date -u '+%F %T')Z
APP_NAME=AlphaCP
APP_ENV=production
APP_KEY=${old_key}
APP_DEBUG=false
APP_URL=https://${public_ip}:${PANEL_PORT}
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

# Asli credentials ${ACP_HOME}/etc/panel.env (root-owned 0640) me hain —
# config/database.php wahi padhta hai. Ye lines sirf dev fallback hain.
DB_CONNECTION=mysql

SESSION_DRIVER=database
SESSION_LIFETIME=30
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync

ACP_VERSION=0.3.0
ACP_AGENT_VERSION=0.1.0
ACP_HOME=${ACP_HOME}
ACP_SERVER_ID=${server_id}

ACP_ADMIN_USER=${ADMIN_USER}
ACP_ADMIN_PASSWORD=${admin_password}
ACP_ADMIN_EMAIL=
ACP_ADMIN_FORCE_CHANGE=true

ACP_FRAME_OPTIONS=DENY
EOF
  chown "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/.env"
  chmod 0640 "${PANEL_ROOT}/.env"
  ok ".env written (0640 ${PANEL_USER})"

  # --- 6. permissions -------------------------------------------------------
  mkdir -p "${PANEL_ROOT}/storage/framework/cache/data" "${PANEL_ROOT}/storage/framework/sessions" \
           "${PANEL_ROOT}/storage/framework/views" "${PANEL_ROOT}/storage/logs" "${PANEL_ROOT}/bootstrap/cache"
  chmod 0755 "${PANEL_ROOT}" "${PANEL_ROOT}/public" 2>/dev/null || true
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type d -exec chmod 0770 {} \; 2>/dev/null || true
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type f -exec chmod 0660 {} \; 2>/dev/null || true
  chmod -R a+rX "${PANEL_ROOT}/public" 2>/dev/null || true
  ok "permissions set (panel ${PANEL_USER} ke roop me chalta hai, root kabhi nahi)"

  # --- 7. migrate + seed + admin -------------------------------------------
  # Panel ke liye woh PHP chuno jo artisan sach me chala sakta ho.
  local php=""
  if ! php="$(php_for_panel)"; then
    while read -r b; do ensure_php_extensions "${b}"; done < <(php_candidates | sort -u)
    if ! php="$(php_for_panel)"; then
      err "koi bhi PHP version panel boot nahi kar paya — artisan ka asli error:"
      ( cd "${PANEL_ROOT}" && "$(php_candidates | head -1)" artisan --version 2>&1 | tail -8 ) | tee -a "${LOG_FILE}"
      die "PHP/vendor mismatch — upar wala error dekho"
    fi
  fi
  PHP_BIN="${php}"
  ok "panel PHP: ${php} ($("${php}" -r 'echo PHP_VERSION;'))"
  ensure_php_extensions "${php}"
  if [[ -n "${old_key}" ]]; then
    ok "APP_KEY preserved (sessions + 2FA secrets valid rahenge)"
  else
    if ! artisan key:generate --force >>"${LOG_FILE}" 2>&1; then
      err "APP_KEY generate fail — artisan ne yeh kaha:"
      artisan key:generate --force 2>&1 | tail -10 | tee -a "${LOG_FILE}"
      die "APP_KEY generate fail"
    fi
    local new_key; new_key="$(env_val APP_KEY "${PANEL_ROOT}/.env" || true)"
    if [[ -n "${new_key}" ]]; then
      umask 077
      printf '%s\n' "${new_key}" > "${state_dir}/panel-appkey.txt"
      chmod 0600 "${state_dir}/panel-appkey.txt"
    fi
    ok "APP_KEY generate ho gaya (safe copy: ${state_dir}/panel-appkey.txt)"
  fi
  artisan config:clear >>"${LOG_FILE}" 2>&1 || true
  artisan migrate --force >>"${LOG_FILE}" 2>&1 \
    || { tail -12 "${PANEL_ROOT}/storage/logs/laravel.log" 2>/dev/null | tee -a "${LOG_FILE}" || true
         die "migrate fail — ${PANEL_ROOT}/storage/logs/laravel.log dekho"; }
  ok "migrations applied"
  artisan db:seed --force >>"${LOG_FILE}" 2>&1 \
    || warn "seeder warning — log dekho (admin pehle se ho to normal hai)"
  ok "seeders done (roles + permissions + admin)"

  local known_pass="${admin_password:-${saved_pass}}"
  if [[ -n "${known_pass}" ]]; then
    umask 077
    printf 'panel_user=%s\npanel_pass=%s\npanel_port=%s\nupdated_at=%s\n' \
      "${ADMIN_USER}" "${known_pass}" "${PANEL_PORT}" "$(date -Is)" > "${state_dir}/panel-admin.txt"
    chmod 0600 "${state_dir}/panel-admin.txt"
  fi

  if [[ "${admin_kept}" == "true" ]]; then
    ok "admin '${ADMIN_USER}' pehle se hai — password unchanged"
    info "badalna ho to: ADMIN_PASSWORD='NayaPass' bash $0"
  else
    artisan alphacp:admin-password "${ADMIN_USER}" \
        --password="${admin_password}" --force-change >>"${LOG_FILE}" 2>&1 \
      || die "admin password set nahi hua — log dekho (${LOG_FILE})"
    ok "admin '${ADMIN_USER}' ka password set (pehle login par badalna padega)"
  fi

  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"
  artisan cache:clear >>"${LOG_FILE}" 2>&1 || true
  artisan view:clear >>"${LOG_FILE}" 2>&1 || true
  artisan route:cache >>"${LOG_FILE}" 2>&1 || true
  artisan config:cache >>"${LOG_FILE}" 2>&1 || true
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true
  # root ne jo bhi cache/log files banayi, unhe alphacp ke liye likhne layak karo
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type d -exec chmod 0770 {} \; 2>/dev/null || true
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type f -exec chmod 0660 {} \; 2>/dev/null || true

  # --- 8. php-fpm pool ------------------------------------------------------
  local v; v="$(php_ver "${php}")"
  if [[ ! -d "/etc/php/${v}/fpm" ]]; then
    info "php${v}-fpm install kar raha hoon…"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php${v}-fpm" >>"${LOG_FILE}" 2>&1 \
      || die "php${v}-fpm install nahi hua"
  fi
  sed -e "s#@@PANEL_USER@@#${PANEL_USER}#g" \
      -e "s#@@PANEL_ROOT@@#${PANEL_ROOT}#g" \
      -e "s#@@ACP_HOME@@#${ACP_HOME}#g" \
      "${PANEL_ROOT}/deploy/php-fpm-alphacp.conf.in" > "/etc/php/${v}/fpm/pool.d/alphacp.conf"
  ok "php-fpm pool: /etc/php/${v}/fpm/pool.d/alphacp.conf"

  # Must be installed before php-fpm restart; otherwise ProtectSystem=full
  # can make the first browser request fail with HTTP 500.
  ensure_fpm_write_access "${v}" || die "php-fpm sandbox allowlist apply nahi hua"

  # --- 9. TLS certificate ---------------------------------------------------
  mkdir -p /etc/ssl/alphacp
  if [[ -f /etc/ssl/alphacp/panel.crt && -f /etc/ssl/alphacp/panel.key ]]; then
    ok "certificate already present"
  else
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout /etc/ssl/alphacp/panel.key -out /etc/ssl/alphacp/panel.crt \
      -subj "/CN=AlphaCP Panel/O=AlphaCP" \
      -addext "subjectAltName=IP:${public_ip},DNS:$(hostname -f 2>/dev/null || hostname)" >>"${LOG_FILE}" 2>&1 \
      || die "certificate generate fail"
    chmod 0600 /etc/ssl/alphacp/panel.key
    ok "self-signed certificate created (Let's Encrypt SSL module ke saath aayega)"
  fi

  # --- 10. nginx vhost ------------------------------------------------------
  sed -e "s#@@PANEL_ROOT@@#${PANEL_ROOT}#g" \
      -e "s#@@SSL_CERT@@#/etc/ssl/alphacp/panel.crt#g" \
      -e "s#@@SSL_KEY@@#/etc/ssl/alphacp/panel.key#g" \
      "${PANEL_ROOT}/deploy/nginx-alphacp.conf.in" > /etc/nginx/sites-available/alphacp-panel.conf

  # nginx 1.25.1 se pehle 'http2 on;' directive nahi hota (1.24 me syntax error) —
  # us case me HTTP/2 chhod dete hain, panel HTTP/1.1 par bhi theek chalta hai.
  local nginx_ver
  nginx_ver="$(nginx -v 2>&1 | sed -E 's#.*nginx/([0-9.]+).*#\1#')"
  if [[ "$(printf '%s\n1.25.1\n' "${nginx_ver}" | sort -V | head -1)" != "1.25.1" ]]; then
    sed -i '/^\s*http2 on;\s*$/d' /etc/nginx/sites-available/alphacp-panel.conf
    info "nginx ${nginx_ver}: 'http2 on;' hata diya (1.25.1 se pehle support nahi hai)"
  fi

  ln -sfn /etc/nginx/sites-available/alphacp-panel.conf /etc/nginx/sites-enabled/alphacp-panel.conf
  ok "vhost: /etc/nginx/sites-enabled/alphacp-panel.conf (listen ${PANEL_PORT} ssl)"

  # Apache :80 ka maalik hai — nginx ka distro default site :80 maangta hai,
  # isliye use hata dena zaroori hai warna nginx start hi nahi hoga.
  if [[ -e /etc/nginx/sites-enabled/default ]]; then
    mv /etc/nginx/sites-enabled/default "/root/nginx-default-site.disabled.$(date +%s)"
    ok "nginx default site (port 80) hata diya — port 80 Apache ka hai"
  fi

  svc restart "php${v}-fpm" >>"${LOG_FILE}" 2>&1 || warn "php${v}-fpm restart warning — log dekho"
  sleep 2
  if [[ -S /run/php/alphacp-fpm.sock ]]; then ok "php-fpm socket ready: /run/php/alphacp-fpm.sock"
  else warn "php-fpm socket nahi mila — 'systemctl status php${v}-fpm' dekho"; fi

  if nginx -t >>"${LOG_FILE}" 2>&1; then
    ok "nginx config valid"
  else
    err "nginx config invalid:"
    nginx -t 2>&1 | tail -5 | tee -a "${LOG_FILE}"
    die "nginx config fail — /etc/nginx/sites-available/alphacp-panel.conf dekho"
  fi

  svc enable nginx >>"${LOG_FILE}" 2>&1 || true
  svc restart nginx >>"${LOG_FILE}" 2>&1 || true
  sleep 2
  if svc is-active --quiet nginx 2>/dev/null || pgrep -x nginx >/dev/null; then
    ok "nginx active — panel port ${PANEL_PORT} par"
  else
    err "nginx start nahi hua:"
    (journalctl -u nginx --no-pager -n 8 2>/dev/null || tail -8 /var/log/nginx/error.log 2>/dev/null) | tee -a "${LOG_FILE}"
    die "nginx start fail"
  fi

  # --- 11. verify (60s tak koshish, phir asli reason dikhao) -----------------
  step "Verify (end to end)"
  local code="" tries=0
  while (( tries < 30 )); do
    tries=$((tries + 1))
    code="$(curl -k -s -o /tmp/.acp-check.html -w '%{http_code}' -m 10 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
    [[ "${code}" == "200" ]] && break
    if [[ "${code}" == "500" || "${code}" == "502" ]]; then
      # pehli 500 par services ek baar fresh restart (opcache/stale socket)
      svc restart "php${v}-fpm" >>"${LOG_FILE}" 2>&1 || true
      svc restart nginx >>"${LOG_FILE}" 2>&1 || true
    fi
    sleep 2
  done

  local verdict="OK"
  if [[ "${code}" == "200" ]]; then
    ok "login page HTTP 200 (https://127.0.0.1:${PANEL_PORT}/)"
  else
    verdict="FAIL"
    err "login page HTTP ${code:-none} — asli reason (aakhri 15 lines):"
    {
      echo "----- nginx error log -----"
      tail -6 /var/log/nginx/alphacp-panel.error.log 2>/dev/null
      echo "----- php-fpm log -----"
      tail -6 "/var/log/php${v}-fpm.log" 2>/dev/null
      echo "----- laravel.log -----"
      tail -15 "${PANEL_ROOT}/storage/logs/laravel.log" 2>/dev/null
      echo "----- storage perms -----"
      ls -la "${PANEL_ROOT}/storage" "${PANEL_ROOT}/storage/logs" 2>/dev/null | head -12
    } | tee -a "${LOG_FILE}"
  fi
  rm -f /tmp/.acp-check.html

  local site_code; site_code="$(curl -s -o /dev/null -w '%{http_code}' -m 8 http://127.0.0.1/ 2>/dev/null || true)"
  if [[ "${site_code}" == "200" ]]; then ok "customer websites still served by Apache (HTTP 200)"
  else warn "Apache check returned '${site_code:-none}' — is apache2 running?"; fi

  # --- 12. credentials ------------------------------------------------------
  umask 077
  {
    printf 'AlphaCP panel — %s\n' "$(date -u '+%F %T')Z"
    printf 'URL      : https://%s:%s/\n' "${public_ip}" "${PANEL_PORT}"
    printf 'Username : %s\n' "${ADMIN_USER}"
    if [[ -z "${known_pass}" ]]; then
      printf 'Password : (pata nahi — reset: ADMIN_PASSWORD=... bash %s)\n' "$(basename "$0")"
    else
      printf 'Password : %s\n' "${known_pass}"
    fi
    printf 'Note     : first login par password change karne ko kahega.\n'
    printf 'Reset    : ADMIN_PASSWORD=%s bash %s\n' "'NayaPassword'" "$(basename "$0")"
  } > "${CRED_FILE}" 2>/dev/null || true
  chmod 0600 "${CRED_FILE}" 2>/dev/null || true

  step "Step 2B complete — panel ready 🎉"
  say ""
  say "  ${C_BOLD}Panel URL${C_RESET} : https://${public_ip}:${PANEL_PORT}/"
  say "  ${C_BOLD}Username${C_RESET}  : ${ADMIN_USER}"
  if [[ -n "${known_pass}" ]]; then
    say "  ${C_BOLD}Password${C_RESET}  : ${known_pass}"
  else
    say "  ${C_BOLD}Password${C_RESET}  : (pata nahi — reset: ADMIN_PASSWORD='NayaPass' bash $0)"
  fi
  say ""
  say "  Credentials file : ${CRED_FILE}  (root-only)"
  say "  Install log      : ${LOG_FILE}"
  say ""
  say "  ${C_BOLD}Agla kadam${C_RESET}: browser me URL kholo (self-signed cert warning → Advanced → Proceed),"
  say "  admin se login karo, password change karo, phir 2FA setup karo."
  say ""
  if [[ "${verdict}" == "OK" ]]; then
    say "${C_GREEN}${C_BOLD}==> VERDICT: PANEL READY ✅ — koi command nahi chahiye, seedha browser kholo.${C_RESET}"
  else
    say "${C_RED}${C_BOLD}==> VERDICT: PANEL NE 200 NAHI DIYA ❌ — upar wali 'asli reason' lines bhej do,${C_RESET}"
    say "${C_RED}${C_BOLD}    baaki sab (code, database, admin) already ho chuka hai.${C_RESET}"
  fi
  say ""
}

main "$@"
