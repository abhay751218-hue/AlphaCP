#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — Step 2B-1 installer: PANEL UI (login + dashboard) on port 8090
#  Version 0.3.0
# -----------------------------------------------------------------------------
#  WHAT IT DOES
#   1. installs the PHP extensions + nginx the panel needs
#   2. deploys the panel app to /usr/local/alphacp/panel (Laravel 11)
#   3. composer install --no-dev  (creds stay in etc/database.env — no copies)
#   4. applies db/migrations/*.sql (0001 core, 0002 panel core)
#   5. creates the first admin user (random password, printed once)
#   6. serves it at  https://<server>:8090  via nginx + php-fpm (self-signed TLS
#      for now — free Let's Encrypt certificate arrives with the SSL module)
#   7. verifies end to end (login page must answer 200)
#
#  WHAT IT DOES **NOT** DO
#   - it does not touch customer websites (Apache keeps 80/443)
#   - it does not start mail/FTP services
#   - it never runs the panel as root (ADR-0002: privileged work = paneld only)
#
#  FLAGS
#   --dry-run            plan only, change nothing
#   --yes                no prompts
#   --force              redo steps that look done
#   --only=PHASES        comma list: preflight,packages,deploy,migrate,admin,serve,verify
#   --port=8090          panel port (default 8090)
#   --admin-user=NAME    first admin username (default: admin)
#   --admin-password=PW  set the password yourself (default: generated)
#   --allow-unsupported  non-Ubuntu Debian family (dev tests)
#
#  Generated file — do not edit by hand. Edit step2b-panel-install.sh.in instead.
#  Rebuild:  python3 tools/build-panel-installer.py
# =============================================================================
set -euo pipefail

ACP_PANEL_INSTALLER_VERSION="0.3.0"
TAR_SHA256="e078e7d9b78e495374ec44783138ebb5fb50ec12633d38ea592e59bdf45056ef"

ACP_HOME="/usr/local/alphacp"
PANEL_DIR="${ACP_HOME}/panel"
STAGE="/tmp/alphacp-panel-payload.$$"
LOG_FILE="/var/log/alphacp-panel-install.log"
DB_NAME="alphacp"
if [[ -f "${ACP_HOME}/etc/database.env" ]]; then
  # Step 2A writes ACP_DB_* keys; accept both spellings and never let a missing
  # key kill the script (this file is read before main() runs)
  _db="$( { grep -E '^(ACP_DB_NAME|DB_NAME)=' "${ACP_HOME}/etc/database.env" 2>/dev/null || true; } | head -1 | cut -d= -f2)"
  if [[ -n "$_db" ]]; then DB_NAME="$_db"; fi
fi

DRY_RUN=0; ASSUME_YES=0; FORCE=0; ALLOW_UNSUPPORTED=0
ONLY_PHASES=""; PANEL_PORT=8090; ADMIN_USER="admin"; ADMIN_PASSWORD=""
ORIG_ARGS=()

C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'

log() {
  local lvl="$1"; shift
  { printf '%s [%s] %s\n' "$(date '+%F %T')" "$lvl" "$*" >>"${LOG_FILE}"; } 2>/dev/null || true
  # keep a copy inside the ACP home too — that tree survives log rotation in /var/log
  mkdir -p "${ACP_HOME}/logs" 2>/dev/null || true
  { printf '%s [%s] %s\n' "$(date '+%F %T')" "$lvl" "$*" >>"${ACP_HOME}/logs/panel-install.log"; } 2>/dev/null || true
}
say()  { printf '%s\n' "$*"; }
info() { log INFO "$*";  say "${C_BLUE}[i]${C_RESET} $*"; }
ok()   { log OK "$*";    say "${C_GREEN}[OK]${C_RESET} $*"; }
warn() { log WARN "$*";  say "${C_YELLOW}[!]${C_RESET} $*"; }
err()  { log ERROR "$*"; say "${C_RED}[x]${C_RESET} $*" >&2; }
step() { say ""; say "${C_BOLD}${C_BLUE}==> $*${C_RESET}"; log STEP "$*"; }
die()  { err "$*"; say ""; say "Full log: ${LOG_FILE}"; exit 1; }

cleanup() { rm -rf "$STAGE" 2>/dev/null || true; }
trap cleanup EXIT

run()       { log CMD "$*"; if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi; "$@" >>"${LOG_FILE}" 2>&1; }
run_try()   { log CMD "(try) $*"; if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi; "$@" >>"${LOG_FILE}" 2>&1 || { log WARN "command failed (ignored): $*"; return 1; }; }

confirm() {
  if (( ASSUME_YES )) || (( DRY_RUN )); then return 0; fi
  local reply
  read -r -p "${C_BOLD}$1 [y/N]: ${C_RESET}" reply || true
  [[ "${reply,,}" == "y" || "${reply,,}" == "yes" ]]
}

usage() {
  cat <<'EOF'
AlphaCP panel installer (Step 2B-1) — login + dashboard on port 8090

Usage: sudo bash panel-install.sh [options]

  --dry-run              plan only, change nothing
  --yes|-y               no prompts (background-friendly)
  --force                redo steps that look done
  --only=PHASES          preflight,packages,deploy,migrate,admin,serve,verify
  --port=8090            panel port
  --admin-user=NAME      first admin username
  --admin-password=PW    set the password yourself
  --allow-unsupported    non-Ubuntu Debian family (dev tests)
  --help                 this help
EOF
}

parse_args() {
  for arg in "$@"; do
    case "$arg" in
      --dry-run)           DRY_RUN=1 ;;
      --yes|-y)            ASSUME_YES=1 ;;
      --force)             FORCE=1 ;;
      --allow-unsupported) ALLOW_UNSUPPORTED=1 ;;
      --only=*)            ONLY_PHASES="${arg#*=}" ;;
      --port=*)            PANEL_PORT="${arg#*=}" ;;
      --admin-user=*)      ADMIN_USER="${arg#*=}" ;;
      --admin-password=*)  ADMIN_PASSWORD="${arg#*=}" ;;
      --help|-h)           usage; exit 0 ;;
      *) usage; die "Unknown option: $arg" ;;
    esac
  done
  [[ "$PANEL_PORT" =~ ^[0-9]+$ ]] || die "--port must be a number"
  if [[ -n "$ONLY_PHASES" ]]; then
    local p
    IFS=',' read -r -a _phases <<< "$ONLY_PHASES"
    for p in "${_phases[@]}"; do
      case "$p" in
        preflight|packages|deploy|migrate|admin|serve|verify) ;;
        *) die "--only: unknown phase '${p}'" ;;
      esac
    done
  fi
}

should_run() {
  if [[ -z "$ONLY_PHASES" ]]; then return 0; fi
  if [[ ",${ONLY_PHASES}," == *",$1,"* ]]; then return 0; fi
  info "Skipping phase '$1' (not in --only list)"
  return 1
}

# -----------------------------------------------------------------------------
#  helpers
# -----------------------------------------------------------------------------
mysql_root()      { mariadb --protocol=socket -u root "$@"; }
sysd()            { if [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then return 0; else return 1; fi; }
os_id()           { . /etc/os-release 2>/dev/null || true; printf '%s' "${ID:-unknown}"; }
os_ver()          { . /etc/os-release 2>/dev/null || true; printf '%s' "${VERSION_ID:-0}"; }
php_primary() {
  local v c
  v="$(grep -E '^ACP_PHP_PRIMARY=' "${ACP_HOME}/etc/install.env" 2>/dev/null | cut -d= -f2)"
  if [[ -n "$v" && -x "/usr/bin/php${v}" ]]; then printf '%s' "$v"; return 0; fi
  # trust what is actually installed (Step 1 may have installed a different version)
  for c in 8.5 8.4 8.3 8.2 8.1 8.0 7.4; do
    if [[ -x "/usr/bin/php${c}" ]]; then printf '%s' "$c"; return 0; fi
  done
  printf '%s' "${v:-8.3}"
}
php_bin()         { local v; v="$(php_primary)"; if [[ -x "/usr/bin/php${v}" ]]; then printf '/usr/bin/php%s' "$v"; else command -v php; fi; }
php_sock()        { local v; v="$(php_primary)"; printf '/run/php/php%s-fpm.sock' "$v"; }
fpm_service()     { local v; v="$(php_primary)"; printf 'php%s-fpm' "$v"; }

extract_payload() {
  local b64
  b64="$(sed -n '/^# @@PAYLOAD_BEGIN@@$/,/^# @@PAYLOAD_END@@$/p' "$0" | sed '1d;$d')"
  [[ -n "$b64" ]] || die "payload missing from this script (re-download it)"

  rm -rf "$STAGE"; mkdir -p "$STAGE"
  printf '%s' "$b64" | base64 -d > "${STAGE}/payload.tar.gz" || die "payload decode failed"

  local got; got="$(sha256sum "${STAGE}/payload.tar.gz" | awk '{print $1}')"
  [[ "$got" == "${TAR_SHA256}" ]] || die "payload checksum mismatch — file corrupt, re-download"
  ok "payload verified (sha256 ${TAR_SHA256:0:16}…)"

  tar -xzf "${STAGE}/payload.tar.gz" -C "$STAGE" || die "payload extract failed"
}

# -----------------------------------------------------------------------------
#  PHASE 0 — preflight
# -----------------------------------------------------------------------------
phase_preflight() {
  step "Phase 0/6 — Preflight"

  if (( ! DRY_RUN )) && [[ "${EUID}" -ne 0 ]]; then
    if command -v sudo >/dev/null 2>&1 && [[ -f "${BASH_SOURCE[0]}" ]]; then
      info "Not root — re-running with sudo..."
      exec sudo -E bash "${BASH_SOURCE[0]}" "${ORIG_ARGS[@]}"
    fi
    die "Please run as root:  sudo bash $0"
  fi
  ok "root privileges confirmed"

  local id ver; id="$(os_id)"; ver="$(os_ver)"
  case "${id}:${ver}" in
    ubuntu:22.04|ubuntu:24.04) ok "OS supported: Ubuntu ${ver} LTS" ;;
    *)
      if (( ALLOW_UNSUPPORTED )); then warn "OS ${id} ${ver} not officially supported (continuing: --allow-unsupported)"
      else die "Unsupported OS: ${id} ${ver} (use --allow-unsupported for dev tests)"; fi ;;
  esac

  [[ -f "${ACP_HOME}/etc/database.env" ]] || die "panel needs the agent installed first — run installer/step2-install.sh (Step 2A)"
  mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MariaDB not reachable as root"
  ok "database + agent prerequisites present"

  command -v composer >/dev/null 2>&1 || die "composer missing (Step 1 installs it)"
  ok "composer: $(composer --version 2>/dev/null | head -1 | awk '{print $3}')"

  local missing=()
  local ext
  for ext in mbstring xml curl zip intl bcmath gd pdo_mysql openssl tokenizer fileinfo; do
    "$(php_bin)" -m 2>/dev/null | grep -qi "^${ext}$" || missing+=("$ext")
  done
  if (( ${#missing[@]} > 0 )); then
    info "PHP extensions missing: ${missing[*]} (packages phase installs them)"
  else
    ok "all required PHP extensions present"
  fi

  if [[ -f "${ACP_HOME}/etc/install.env" ]]; then ok "installer record found (Step 1)"; fi
}

# -----------------------------------------------------------------------------
#  PHASE 1 — packages
# -----------------------------------------------------------------------------
phase_packages() {
  step "Phase 1/6 — Packages (nginx + PHP extensions)"

  local v; v="$(php_primary)"
  local pkgs=(nginx "php${v}-fpm" "php${v}-cli" "php${v}-mbstring" "php${v}-xml" "php${v}-curl"
              "php${v}-zip" "php${v}-intl" "php${v}-bcmath" "php${v}-gd" "php${v}-mysql")

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} apt-get install -y ${pkgs[*]}"
    return 0
  fi

  if "$(php_bin)" -m 2>/dev/null | grep -qi '^mbstring$' && command -v nginx >/dev/null 2>&1; then
    ok "nginx + PHP extensions already installed"
  else
    info "installing: ${pkgs[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get update -qq >>"${LOG_FILE}" 2>&1 || true
    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
           -o Dpkg::Options::=--force-confnew -o Dpkg::Options::=--force-confold \
           "${pkgs[@]}" >>"${LOG_FILE}" 2>&1; then
      # this distro does not ship versioned packages (e.g. Debian) — use its default PHP
      warn "php${v}-* packages not available here — trying the distro default php-* packages"
      local dflt=(nginx php-fpm php-cli php-mbstring php-xml php-curl php-zip php-intl php-bcmath php-gd php-mysql)
      DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        -o Dpkg::Options::=--force-confnew -o Dpkg::Options::=--force-confold \
        "${dflt[@]}" >>"${LOG_FILE}" 2>&1 || die "package install failed — see ${LOG_FILE}"
      v="$(php_primary)"
      warn "using PHP ${v} (distro default)"
      ok "packages installed"
      return 0
    fi
    ok "packages installed"
  fi
}

# -----------------------------------------------------------------------------
#  PHASE 2 — deploy code
# -----------------------------------------------------------------------------
phase_deploy() {
  step "Phase 2/6 — Deploy panel code"

  [[ -d "${STAGE}/panel" ]] || die "payload has no panel/ directory"

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} copy panel -> ${PANEL_DIR} (keeping existing .env + storage)"
    say "    ${C_DIM}(dry-run)${C_RESET} composer install --no-dev --optimize-autoloader"
    return 0
  fi

  mkdir -p "${PANEL_DIR}"
  local keep_env=""
  if [[ -f "${PANEL_DIR}/.env" ]]; then keep_env="$(mktemp)"; cp "${PANEL_DIR}/.env" "$keep_env"; fi

  # rsync-free sync: copy source, never delete storage/ or .env
  rm -rf "${PANEL_DIR}/app.new"
  mkdir -p "${PANEL_DIR}/app.new"
  cp -a "${STAGE}/panel/." "${PANEL_DIR}/app.new/"
  # sync every entry except runtime state (vendor deps, storage, live .env)
  local part
  for part in $(ls -A "${PANEL_DIR}/app.new"); do
    case "$part" in
      vendor|storage|.env) continue ;;
    esac
    rm -rf "${PANEL_DIR:?}/${part}"
    mv "${PANEL_DIR}/app.new/${part}" "${PANEL_DIR}/${part}"
  done
  rm -rf "${PANEL_DIR}/app.new"
  if [[ -n "$keep_env" ]]; then cp "$keep_env" "${PANEL_DIR}/.env"; rm -f "$keep_env"; fi

  # writable runtime dirs (excluded from the payload on purpose — they are runtime state)
  mkdir -p "${PANEL_DIR}/storage/framework/cache/data" \
           "${PANEL_DIR}/storage/framework/sessions" \
           "${PANEL_DIR}/storage/framework/views" \
           "${PANEL_DIR}/storage/logs" \
           "${PANEL_DIR}/bootstrap/cache"
  ok "code deployed to ${PANEL_DIR}"

  # --- composer -------------------------------------------------------------
  if [[ -d "${PANEL_DIR}/vendor" ]] && (( ! FORCE )); then
    ok "vendor/ already present (use --force to reinstall dependencies)"
  else
    info "composer install (this downloads Laravel — ~1 minute)"
    ( cd "${PANEL_DIR}" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --no-interaction --optimize-autoloader --no-progress ) >>"${LOG_FILE}" 2>&1 \
      || die "composer install failed — see ${LOG_FILE}"
    ok "composer install complete"
  fi

  # --- .env -----------------------------------------------------------------
  if [[ ! -f "${PANEL_DIR}/.env" ]]; then
    cp -f "${PANEL_DIR}/.env.example" "${PANEL_DIR}/.env" \
      || die ".env.example missing in ${PANEL_DIR} — payload incomplete"
    local key
    key="base64:$("$(php_bin)" -r 'echo base64_encode(random_bytes(32));')"
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" "${PANEL_DIR}/.env"
    sed -i "s|^APP_URL=.*|APP_URL=https://$(hostname -I | awk '{print $1}'):${PANEL_PORT}|" "${PANEL_DIR}/.env"
    chown root:www-data "${PANEL_DIR}/.env"
    chmod 0640 "${PANEL_DIR}/.env"
    ok ".env created (APP_KEY generated, 0640 root:www-data)"
  else
    info ".env already present — keeping it"
  fi

  # --- ownership / permissions ---------------------------------------------
  # panel code: root-owned, readable by the web user; storage stays writable
  chown -R root:www-data "${PANEL_DIR}"
  chmod -R 0750 "${PANEL_DIR}"
  chmod -R 0770 "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"
  find "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache" -type f -exec chmod 0660 {} \; 2>/dev/null || true
  chmod 0755 "${PANEL_DIR}/artisan"

  # credentials file: root-owned, group-readable by the web user (0640)
  chown root:www-data "${ACP_HOME}/etc/database.env"
  chmod 0640 "${ACP_HOME}/etc/database.env"
  ok "permissions set (panel runs as www-data, never root)"
}

# -----------------------------------------------------------------------------
#  PHASE 3 — migrations
# -----------------------------------------------------------------------------
phase_migrate() {
  step "Phase 3/6 — Migrations"

  [[ -d "${STAGE}/db/migrations" ]] || die "payload has no db/migrations"

  if (( DRY_RUN )); then
    ls "${STAGE}/db/migrations"/*.sql 2>/dev/null | while read -r f; do say "    ${C_DIM}(dry-run)${C_RESET} apply $(basename "$f")"; done
    return 0
  fi

  mysql_root "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS schema_migrations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      version VARCHAR(40) NOT NULL,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_schema_version (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

  local f version applied
  for f in $(ls "${STAGE}/db/migrations"/*.sql | sort); do
    version="$(basename "$f" .sql)"
    applied="$(mysql_root -N -B "${DB_NAME}" -e "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}'" 2>/dev/null || echo 0)"
    if [[ "$applied" != "0" && $FORCE -eq 0 ]]; then
      info "migration ${version} already applied — skipping"
      continue
    fi
    if mysql_root "${DB_NAME}" < "$f"; then
      mysql_root "${DB_NAME}" -e "INSERT IGNORE INTO schema_migrations (version) VALUES ('${version}')"
      ok "applied ${version}"
    else
      die "migration ${version} FAILED — fix and re-run"
    fi
  done
}

# -----------------------------------------------------------------------------
#  PHASE 4 — admin user
# -----------------------------------------------------------------------------
phase_admin() {
  step "Phase 4/6 — Panel admin user"

  local exists="0"
  exists="$(mysql_root -N -B "${DB_NAME}" -e "SELECT COUNT(*) FROM users WHERE username='${ADMIN_USER}'" 2>/dev/null || echo 0)"

  if (( DRY_RUN )); then
    if [[ "$exists" != "0" && -z "$ADMIN_PASSWORD" ]]; then
      say "    ${C_DIM}(dry-run)${C_RESET} admin '${ADMIN_USER}' already exists — password left unchanged"
    else
      say "    ${C_DIM}(dry-run)${C_RESET} php artisan alphacp:create-admin --username=${ADMIN_USER} …"
    fi
    return 0
  fi

  # an existing admin keeps its password unless one is given explicitly —
  # silently rotating it on every re-run locked people out of their own panel.
  if [[ "$exists" != "0" && -z "$ADMIN_PASSWORD" ]]; then
    ok "admin user '${ADMIN_USER}' already exists — password unchanged"
    info "password badalna ho to: --admin-password='NayaPassword' ke saath dobara chalao"
    return 0
  fi

  if [[ -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$("$(php_bin)" -r 'echo rtrim(strtr(base64_encode(random_bytes(18)), "+/", "AZ"), "=");')"
  fi

  ( cd "${PANEL_DIR}" && ACP_HOME="${ACP_HOME}" "$(php_bin)" artisan alphacp:create-admin \
      --username="${ADMIN_USER}" --password="${ADMIN_PASSWORD}" ) >>"${LOG_FILE}" 2>&1 \
      || die "admin user creation failed — see ${LOG_FILE}"

  mkdir -p "${ACP_HOME}/var"
  umask 077
  printf 'panel_user=%s\npanel_pass=%s\npanel_port=%s\ncreated_at=%s\n' \
    "${ADMIN_USER}" "${ADMIN_PASSWORD}" "${PANEL_PORT}" "$(date -Is)" \
    > "${ACP_HOME}/var/panel-admin.txt"
  ok "admin user '${ADMIN_USER}' ready (password saved to ${ACP_HOME}/var/panel-admin.txt, root-only)"
}

# -----------------------------------------------------------------------------
#  PHASE 5 — serve (nginx + TLS on 8090)
# -----------------------------------------------------------------------------
phase_serve() {
  step "Phase 5/6 — nginx service on port ${PANEL_PORT}"

  # is the port already taken by something else?
  local holder
  holder="$(ss -ltnp 2>/dev/null | awk -v p=":${PANEL_PORT}$" '$4 ~ p {print $6}' | head -1)"
  if [[ -n "$holder" && "$holder" != *nginx* ]]; then
    warn "port ${PANEL_PORT} is already held by: ${holder}"
    warn "nginx will not be able to bind — stop that process first (e.g. a leftover 'php artisan serve')"
  fi

  if ! command -v nginx >/dev/null 2>&1; then
    warn "nginx not installed (skip the web server phase — packages phase needed)"
    return 0
  fi

  local cert_dir="/etc/ssl/alphacp"

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} self-signed cert (${cert_dir}/panel.crt)"
    say "    ${C_DIM}(dry-run)${C_RESET} write /etc/nginx/sites-available/alphacp-panel.conf (listen ${PANEL_PORT} ssl)"
    say "    ${C_DIM}(dry-run)${C_RESET} disable default site + enable alpha panel site + nginx -t + start nginx"
    return 0
  fi

  # --- TLS ------------------------------------------------------------------
  if [[ ! -f "${cert_dir}/panel.crt" ]]; then
    mkdir -p "$cert_dir"
    a=$(hostname -I | awk '{print $1}')
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout "${cert_dir}/panel.key" -out "${cert_dir}/panel.crt" \
      -subj "/CN=alphacp-panel" \
      -addext "subjectAltName=IP:${a},DNS:$(hostname -f 2>/dev/null || hostname)" >>"${LOG_FILE}" 2>&1 \
      || die "self-signed certificate generation failed"
    chmod 0600 "${cert_dir}/panel.key"
    ok "self-signed certificate created (browser will warn until Let's Encrypt arrives)"
  else
    info "certificate already present"
  fi

  # --- vhost ----------------------------------------------------------------
  cat > /etc/nginx/sites-available/alphacp-panel.conf <<NGINX
# AlphaCP panel — generated by installer/panel-install.sh (safe to re-generate)
server {
    listen ${PANEL_PORT} ssl;
    listen [::]:${PANEL_PORT} ssl;
    http2 on;
    server_name _;

    root ${PANEL_DIR}/public;
    index index.php;

    ssl_certificate     ${cert_dir}/panel.crt;
    ssl_certificate_key ${cert_dir}/panel.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    client_max_body_size 128m;
    server_tokens off;

    # never serve dotfiles, the framework's internals or logs
    location ~ /\\.(?!well-known).* { deny all; }
    location ~ ^/(storage|vendor|bootstrap|config|database|app|routes)/ { deny all; }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$(php_sock);
        fastcgi_read_timeout 120;
    }

    location ~* \\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)\$ {
        expires 7d;
        access_log off;
    }
}
NGINX
  ok "vhost written: /etc/nginx/sites-available/alphacp-panel.conf"

  # the distro default site listens on :80 and would fight Apache for customer sites
  if [[ -e /etc/nginx/sites-enabled/default ]]; then
    rm -f /etc/nginx/sites-enabled/default
    ok "disabled nginx default site (port 80 belongs to Apache)"
  fi
  ln -sfn /etc/nginx/sites-available/alphacp-panel.conf /etc/nginx/sites-enabled/alphacp-panel.conf

  if ! nginx -t >>"${LOG_FILE}" 2>&1; then
    err "nginx config test failed — diagnostics:"
    nginx -t 2>&1 | tail -5
    die "fix the config above and re-run with --only=serve"
  fi
  ok "nginx config test: OK"

  # --- php-fpm pool socket ------------------------------------------------
  if sysd; then
    systemctl enable "$(fpm_service)" >>"${LOG_FILE}" 2>&1 || true
    systemctl restart "$(fpm_service)" >>"${LOG_FILE}" 2>&1 || true
    sleep 1
    if systemctl is-active --quiet "$(fpm_service)"; then ok "$(fpm_service): running"
    else warn "$(fpm_service) did not start — check: journalctl -u $(fpm_service)"; fi

    systemctl enable nginx >>"${LOG_FILE}" 2>&1 || true
    systemctl restart nginx >>"${LOG_FILE}" 2>&1 || true
    sleep 1
    if systemctl is-active --quiet nginx; then ok "nginx: running (panel on :${PANEL_PORT})"
    else
      err "nginx failed to start — diagnostics:"
      systemctl status nginx --no-pager -l 2>&1 | head -12
      journalctl -u nginx --no-pager -n 10 2>&1 | tail -10
      die "nginx start failed"
    fi
  else
    warn "systemd not available — start nginx manually (dev container)"
  fi
}

# -----------------------------------------------------------------------------
#  PHASE 6 — verify
# -----------------------------------------------------------------------------
phase_verify() {
  step "Phase 6/6 — Verify (end to end)"

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} curl -k https://127.0.0.1:${PANEL_PORT}/login  (expect 200)"
    return 0
  fi

  mkdir -p "${ACP_HOME}/logs" 2>/dev/null || true
  local report; report="${ACP_HOME}/logs/verify-panel-$(date +%Y%m%d-%H%M%S).txt"
  local code="" tries=0

  while (( tries < 10 )); do
    tries=$((tries + 1))
    code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:${PANEL_PORT}/login" 2>/dev/null || true)"
    if [[ "$code" == "200" ]]; then break; fi
    sleep 2
  done

  {
    echo "===== AlphaCP panel verification — $(date -Is) ====="
    echo "  panel dir      : ${PANEL_DIR}"
    echo "  port           : ${PANEL_PORT}"
    echo "  login page     : HTTP ${code:-none} (after ${tries} tries)"
    echo "  nginx          : $(command -v systemctl >/dev/null 2>&1 && systemctl is-active nginx 2>/dev/null || echo 'n/a')"
    echo "  $(fpm_service) : $(command -v systemctl >/dev/null 2>&1 && systemctl is-active "$(fpm_service)" 2>/dev/null || echo 'n/a')"
    echo "  apache2 (sites): $(command -v systemctl >/dev/null 2>&1 && systemctl is-active apache2 2>/dev/null || echo 'n/a')"
    echo "  paneld         : $(command -v systemctl >/dev/null 2>&1 && systemctl is-active paneld 2>/dev/null || echo 'n/a')"
    echo
    echo "--- last panel log lines ---"
    tail -5 "${ACP_HOME}/logs/panel-install.log" 2>/dev/null || true
    echo
    echo "--- laravel log (if any) ---"
    tail -5 "${PANEL_DIR}/storage/logs/laravel.log" 2>/dev/null || echo "  (clean)"
  } > "$report" 2>&1

  say ""
  if [[ "$code" == "200" ]]; then
    ok "login page answered HTTP 200 — panel is live"
  else
    err "login page returned '${code:-no response}' — diagnostics:"
    tail -20 "$report" | sed 's/^/  /'
    die "panel not reachable on port ${PANEL_PORT}"
  fi

  # Apache must still own the customer websites
  local site_code; site_code="$(curl -s -o /dev/null -w '%{http_code}' -m 8 http://127.0.0.1/ 2>/dev/null || true)"
  if [[ "$site_code" == "200" ]]; then ok "customer websites still served by Apache (HTTP 200)"
  else warn "Apache check returned '${site_code:-none}' — is apache2 running?"; fi

  local ip; ip="$(hostname -I | awk '{print $1}')"
  say ""
  say "${C_GREEN}${C_BOLD}Panel live:${C_RESET}  https://${ip}:${PANEL_PORT}/"
  if [[ -f "${ACP_HOME}/var/panel-admin.txt" ]]; then
    say "  username: $(grep '^panel_user=' "${ACP_HOME}/var/panel-admin.txt" | cut -d= -f2)"
    say "  password: (${ACP_HOME}/var/panel-admin.txt me hai — root-only file)"
    say "  ${C_DIM}password dekhne ke liye:  sudo cat ${ACP_HOME}/var/panel-admin.txt${C_RESET}"
  fi
  say ""
  say "  browser me kholte waqt 'Not secure' warning aayega — self-signed certificate hai."
  say "  (Let's Encrypt wala asli certificate SSL module ke saath aayega.)"
  say ""
  say "  report: ${report}"
  say "  log   : ${LOG_FILE}"
}

# -----------------------------------------------------------------------------
main() {
  ORIG_ARGS=("$@")
  parse_args "$@"
  if (( ! DRY_RUN )); then mkdir -p "$(dirname "$LOG_FILE")" "${ACP_HOME}/logs" 2>/dev/null || true; fi

  say ""
  say "${C_BOLD}AlphaCP panel installer v${ACP_PANEL_INSTALLER_VERSION}${C_RESET} — panel UI (login + dashboard)"
  say "${C_DIM}port ${PANEL_PORT} · log: ${LOG_FILE}${C_RESET}"

  if should_run preflight; then phase_preflight; fi
  extract_payload
  if should_run packages;  then phase_packages;  fi
  if should_run deploy;    then phase_deploy;    fi
  if should_run migrate;   then phase_migrate;   fi
  if should_run admin;     then phase_admin;     fi
  if should_run serve;     then phase_serve;     fi
  if should_run verify;    then phase_verify;    fi

  say ""
  say "${C_GREEN}${C_BOLD}Panel install complete.${C_RESET} Browser me kholo aur login karke batao kaisa laga."
  say ""
}

# `set -e` stays in force INSIDE main() on purpose: a failed step must stop the
# install (wrapping this call in `||` or `set +e` would silently disable that).
main "$@"
# The base64 payload below is DATA, not code — stop before bash reaches it.
exit 0

# @@PAYLOAD_BEGIN@@
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
AA==
# @@PAYLOAD_END@@
