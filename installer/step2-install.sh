#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — Step 2 installer: paneld agent + task queue + alphacp CLI v0.2
#  Version 0.2.0
# -----------------------------------------------------------------------------
#  WHAT IT DOES
#    1. creates the `alphacp` MariaDB database + app user (random password)
#    2. applies db/migrations/*.sql (tracked in schema_migrations)
#    3. installs the root task agent to /usr/local/alphacp/agent (paneld)
#    4. installs the new `alphacp` CLI v0.2 (task run/list/show/doctor)
#    5. registers this server in the `servers` table
#    6. starts paneld via systemd and runs an end-to-end self test
#
#  WHAT IT DOES **NOT** DO
#    - no customer accounts, no mail, no DNS changes (those are later steps)
#    - it never touches /home, /etc/<service> confs or running websites
#
#  PAYLOAD
#    The agent source + CLI + migrations are embedded (base64 tar) so this is
#    a true one-file install. Rebuild after code changes with:
#        python3 tools/build-step2-installer.py
#
#  FLAGS
#    --dry-run        plan only, change nothing
#    --yes            no prompts
#    --force          redo steps that look already done
#    --allow-unsupported   run on non-Ubuntu Debian-family (dev/test only)
#
#  Generated file — do not edit by hand. Edit step2-install.sh.in instead.
# =============================================================================
set -euo pipefail

ACP_STEP2_VERSION="0.2.0"
TAR_SHA256="598e2394e8931421d46e248f60660416f1ecbbeecc2dea9a15209ab8c2ca92b8"

ACP_HOME="/usr/local/alphacp"
STAGE="/tmp/alphacp-step2-payload.$$"
LOG_FILE="/var/log/alphacp-step2-install.log"
DB_NAME="alphacp"
DB_USER="alphacp"
DB_HOST="localhost"

DRY_RUN=0; ASSUME_YES=0; FORCE=0; ALLOW_UNSUPPORTED=0
SERVER_NAME=""   # --server-name=NAME (default: short hostname)
ORIG_ARGS=()

C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'

log() { local lvl="$1"; shift; { printf '%s [%s] %s\n' "$(date '+%F %T')" "$lvl" "$*" >>"${LOG_FILE}"; } 2>/dev/null || true; }
say()  { printf '%s\n' "$*"; }
info() { log INFO "$*";  say "${C_BLUE}[i]${C_RESET} $*"; }
ok()   { log OK "$*";    say "${C_GREEN}[OK]${C_RESET} $*"; }
warn() { log WARN "$*";  say "${C_YELLOW}[!]${C_RESET} $*"; }
err()  { log ERROR "$*"; say "${C_RED}[x]${C_RESET} $*" >&2; }
step() { say ""; say "${C_BOLD}${C_BLUE}==> $*${C_RESET}"; log STEP "$*"; }
die()  { err "$*"; say ""; say "Full log: ${LOG_FILE}"; exit 1; }

cleanup() { rm -rf "$STAGE" 2>/dev/null || true; }
trap cleanup EXIT

run() { # run a command (respects --dry-run, output goes to the log)
  log CMD "$*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" >>"${LOG_FILE}" 2>&1
}
run_try() {
  log CMD "(try) $*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" >>"${LOG_FILE}" 2>&1 || { log WARN "command failed (ignored): $*"; return 1; }
}
run_out() {
  log CMD "$*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" 2>&1 | tee -a "${LOG_FILE}"
}

confirm() {
  (( ASSUME_YES )) && return 0
  (( DRY_RUN )) && return 0
  local reply
  read -r -p "${C_BOLD}$1 [y/N]: ${C_RESET}" reply || true
  [[ "${reply,,}" == "y" || "${reply,,}" == "yes" ]]
}

usage() {
  cat <<'EOF'
AlphaCP Step 2 installer — paneld agent + task queue + CLI v0.2

Usage: sudo bash step2-install.sh [options]

  --dry-run              plan only, change nothing
  --yes|-y               no prompts (background-friendly)
  --force                redo steps that look already done
  --allow-unsupported    allow non-Ubuntu Debian-family (dev/test only)
  --server-name=NAME     name this server in the panel (default: short hostname)
  --help                 this help
EOF
}

parse_args() {
  for arg in "$@"; do
    case "$arg" in
      --dry-run)            DRY_RUN=1 ;;
      --yes|-y)             ASSUME_YES=1 ;;
      --force)              FORCE=1 ;;
      --allow-unsupported)  ALLOW_UNSUPPORTED=1 ;;
      --server-name=*)      SERVER_NAME="${arg#*=}" ;;
      --help|-h)            usage; exit 0 ;;
      *) usage; die "Unknown option: $arg" ;;
    esac
  done
}

# -----------------------------------------------------------------------------
#  helpers
# -----------------------------------------------------------------------------
mysql_root() { mariadb --protocol=socket -u root "$@"; }

sysd() { [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }

os_id() { . /etc/os-release 2>/dev/null || true; printf '%s' "${ID:-unknown}"; }
os_ver() { . /etc/os-release 2>/dev/null || true; printf '%s' "${VERSION_ID:-0}"; }

detect_php() {
  if command -v php >/dev/null 2>&1; then command -v php; return 0; fi
  local v
  for v in 8.4 8.3 8.2 8.1; do
    [[ -x "/usr/bin/php${v}" ]] && { echo "/usr/bin/php${v}"; return 0; }
  done
  return 1
}

extract_payload() {
  local b64
  b64="$(sed -n '/^# @@PAYLOAD_BEGIN@@$/,/^# @@PAYLOAD_END@@$/p' "$0" | sed '1d;$d')"
  [[ -n "$b64" ]] || die "payload missing from this script (re-download it)"

  rm -rf "$STAGE"; mkdir -p "$STAGE"
  printf '%s' "$b64" | base64 -d > "${STAGE}/payload.tar.gz" || die "payload decode failed"

  local got; got="$(sha256sum "${STAGE}/payload.tar.gz" | awk '{print $1}')"
  if [[ "$got" != "${TAR_SHA256}" ]]; then
    die "payload checksum mismatch (got ${got:0:12}…, expected ${TAR_SHA256:0:12}…) — file is corrupt, re-download"
  fi
  ok "payload verified (sha256 ${TAR_SHA256:0:16}…)"

  tar -xzf "${STAGE}/payload.tar.gz" -C "$STAGE" || die "payload extract failed"
}

# -----------------------------------------------------------------------------
#  PHASE 0 — preflight
# -----------------------------------------------------------------------------
phase_preflight() {
  step "Phase 0/4 — Preflight"

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
      if (( ALLOW_UNSUPPORTED )); then
        warn "OS ${id} ${ver} not officially supported (continuing: --allow-unsupported)"
      else
        die "Unsupported OS: ${id} ${ver}. Supported: Ubuntu 22.04 / 24.04 (use --allow-unsupported for dev tests)"
      fi ;;
  esac

  command -v mariadb >/dev/null 2>&1 || die "mariadb client missing — run installer/install.sh (Step 1) first"
  mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MariaDB root access failed — is mariadb running? (Step 1)"
  ok "MariaDB reachable"

  local php_bin; php_bin="$(detect_php)" || die "PHP CLI missing (Step 1 installs php-fpm/cli)"
  [[ "$(php -m 2>/dev/null | grep -ci pdo_mysql)" -ge 1 ]] || die "php pdo_mysql missing — apt install php-mysql"
  ok "PHP CLI: ${php_bin} ($(php -r 'echo PHP_VERSION;')) with pdo_mysql"

  if ! (( FORCE )) && [[ -f "${ACP_HOME}/agent/bin/paneld" ]]; then
    info "paneld already installed — use --force to reinstall/upgrade"
  fi

  mkdir -p "${ACP_HOME}"/{agent,etc,logs,var,releases,bin}
  ok "layout ready: ${ACP_HOME}"
}

# -----------------------------------------------------------------------------
#  PHASE 1 — database
# -----------------------------------------------------------------------------
phase_database() {
  step "Phase 1/4 — Database"

  local env_file="${ACP_HOME}/etc/database.env"
  local pass=""

  if [[ -f "$env_file" ]] && ! (( FORCE )); then
    pass="$(grep -E '^ACP_DB_PASS=' "$env_file" | cut -d= -f2-)"
    info "existing credentials found — keeping them"
  fi
  if [[ -z "$pass" ]]; then
    pass="$(openssl rand -base64 32 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 28)"
    [[ -n "$pass" ]] || die "could not generate a password (openssl missing?)"
  fi

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} create database ${DB_NAME} + user ${DB_USER}@localhost"
  else
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${pass}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    ok "database + user ready"
  fi

  # credentials for PHP (parsed by paneld)
  if (( ! DRY_RUN )); then
    install -m 0600 /dev/null "$env_file"
    cat > "$env_file" <<EOF
# AlphaCP database credentials — generated by step2-install.sh
# mode 0600, root-only. Parsed by paneld (agent) and the panel app.
ACP_DB_HOST=${DB_HOST}
ACP_DB_PORT=3306
ACP_DB_NAME=${DB_NAME}
ACP_DB_USER=${DB_USER}
ACP_DB_PASS=${pass}
AAP_SERVER_ID_PLACEHOLDER
EOF
  else
    say "    ${C_DIM}(dry-run)${C_RESET} write ${env_file} (0600)"
  fi

  # credentials for the CLI (mariadb client config, also 0600)
  if (( ! DRY_RUN )); then
    install -m 0600 /dev/null "${ACP_HOME}/etc/my.cnf"
    cat > "${ACP_HOME}/etc/my.cnf" <<EOF
[client]
user=${DB_USER}
password="${pass}"
host=${DB_HOST}
port=3306
database=${DB_NAME}
EOF
  fi
  ok "credentials stored (0600): etc/database.env + etc/my.cnf"
}

# -----------------------------------------------------------------------------
#  PHASE 2 — migrations
# -----------------------------------------------------------------------------
phase_migrations() {
  step "Phase 2/4 — Migrations"

  [[ -d "${STAGE}/db/migrations" ]] || die "payload has no db/migrations"

  if (( DRY_RUN )); then
    ls "${STAGE}/db/migrations"/*.sql | while read -r f; do say "    ${C_DIM}(dry-run)${C_RESET} apply $(basename "$f")"; done
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
    if [[ "$applied" != "0" ]]; then
      info "migration ${version} already applied — skipping"
      continue
    fi
    if mysql_root "${DB_NAME}" < "$f"; then
      mysql_root "${DB_NAME}" -e "INSERT INTO schema_migrations (version) VALUES ('${version}')"
      ok "applied ${version}"
    else
      die "migration ${version} FAILED — DB left as-is, fix and re-run"
    fi
  done
}

# -----------------------------------------------------------------------------
#  PHASE 3 — code install (agent + CLI) + systemd
# -----------------------------------------------------------------------------
phase_code() {
  step "Phase 3/4 — Agent, CLI and service"

  # ---- agent files ---------------------------------------------------------
  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} install agent -> ${ACP_HOME}/agent (bin/config/src/systemd/tests)"
  else
    rm -rf "${ACP_HOME}/agent.new"
    mkdir -p "${ACP_HOME}/agent.new"
    cp -a "${STAGE}/agent/." "${ACP_HOME}/agent.new/"
    chmod 0755 "${ACP_HOME}/agent.new/bin/paneld"
    # atomic-ish swap so a running agent never sees half-written code
    if [[ -d "${ACP_HOME}/agent" ]]; then mv "${ACP_HOME}/agent" "${ACP_HOME}/agent.old.$(date +%s)"; fi
    mv "${ACP_HOME}/agent.new" "${ACP_HOME}/agent"
    ok "agent installed: ${ACP_HOME}/agent"
  fi

  # ---- CLI -----------------------------------------------------------------
  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} install CLI -> /usr/local/bin/alphacp (v0.2)"
  else
    install -m 0755 "${STAGE}/cli/alphacp" /usr/local/bin/alphacp
    install -m 0755 "${STAGE}/cli/alphacp" "${ACP_HOME}/bin/alphacp"
    ok "CLI installed: /usr/local/bin/alphacp"
  fi

  # ---- register this server ------------------------------------------------
  local hostname_short public_ip private_ip os_pretty arch cpu ram_mb disk_gb specs
  hostname_short="${SERVER_NAME:-$(hostname -s)}"
  private_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  public_ip="$(curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null || true)"
  [[ -n "$public_ip" ]] || public_ip="$private_ip"
  os_pretty="$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-unknown}")"
  arch="$(uname -m)"
  cpu="$(nproc)"
  ram_mb="$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)"
  disk_gb="$(df -BG / | awk 'NR==2{print $2}' | tr -dc '0-9')"

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} register server '${hostname_short}' (${public_ip}, ${arch}, ${cpu}c/${ram_mb}MB/${disk_gb}GB)"
  else
    mysql_root "${DB_NAME}" <<SQL
INSERT INTO servers (name, hostname, role, public_ip, private_ip, os, arch, panel_version, specs, created_at, updated_at)
VALUES ('${hostname_short}', '${hostname_short}', 'central', '${public_ip}', '${private_ip}', '${os_pretty}',
        '${arch}', '0.2.0', JSON_OBJECT('cpu', ${cpu}, 'ram_mb', ${ram_mb}, 'disk_gb', ${disk_gb}), NOW(), NOW())
ON DUPLICATE KEY UPDATE
  public_ip = VALUES(public_ip), private_ip = VALUES(private_ip), os = VALUES(os),
  arch = VALUES(arch), specs = VALUES(specs), updated_at = NOW();
SQL
  fi

  local server_id=1
  if (( ! DRY_RUN )); then
    server_id="$(mysql_root -N -B "${DB_NAME}" -e "SELECT id FROM servers WHERE hostname='${hostname_short}' LIMIT 1")"
    [[ -n "$server_id" ]] || die "could not read server id"
    if grep -q '^ACP_SERVER_ID=' "${ACP_HOME}/etc/database.env"; then
      sed -i "s/^ACP_SERVER_ID=.*/ACP_SERVER_ID=${server_id}/" "${ACP_HOME}/etc/database.env"
    else
      sed -i "s/^AAP_SERVER_ID_PLACEHOLDER$/ACP_SERVER_ID=${server_id}/" "${ACP_HOME}/etc/database.env"
    fi
    ok "server registered: ${hostname_short} (id=${server_id}, public ${public_ip})"
  fi

  # ---- systemd unit --------------------------------------------------------
  local php_bin; php_bin="$(detect_php)"
  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} install /etc/systemd/system/paneld.service (ExecStart=${php_bin} ... --daemon)"
    return 0
  fi

  sed "s#^ExecStart=.*#ExecStart=${php_bin} ${ACP_HOME}/agent/bin/paneld --daemon#" \
    "${STAGE}/agent/systemd/paneld.service" > /etc/systemd/system/paneld.service

  if sysd; then
    systemctl daemon-reload >>"${LOG_FILE}" 2>&1 || true
    systemctl enable paneld >>"${LOG_FILE}" 2>&1 || true
    systemctl restart paneld >>"${LOG_FILE}" 2>&1 || true
    sleep 2
    if systemctl is-active --quiet paneld; then
      ok "paneld service active (restarts automatically, also after reboot)"
    else
      warn "paneld did not start — diagnostics:"
      systemctl status paneld --no-pager -l 2>&1 | head -14 | tee -a "${LOG_FILE}"
    fi
  else
    warn "systemd not available in this environment — service install skipped"
    warn "(real VPS par chalega; yahan test ke liye paneld manually chalega)"
    nohup "${php_bin}" "${ACP_HOME}/agent/bin/paneld" --daemon >>"${ACP_HOME}/logs/paneld.log" 2>&1 &
    sleep 2
    if pgrep -f "paneld --daemon" >/dev/null 2>&1; then ok "paneld started manually (no systemd)"; fi
  fi
}

# -----------------------------------------------------------------------------
#  PHASE 4 — verify (end to end)
# -----------------------------------------------------------------------------
phase_verify() {
  step "Phase 4/4 — Self test (end to end)"

  local php_bin; php_bin="$(detect_php)"

  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} paneld --selftest ; queue agent.ping ; wait for result"
    return 0
  fi

  local report; report="${ACP_HOME}/logs/verify-step2-$(date +%Y%m%d-%H%M%S).txt"
  {
    echo "===== AlphaCP Step 2 verification — $(date -Is) ====="
    echo
    echo "--- file layout ---"
    ls -la "${ACP_HOME}/agent/bin/" "${ACP_HOME}/etc/" | sed 's/^/  /'
    echo
    echo "--- paneld selftest ---"
    "$php_bin" "${ACP_HOME}/agent/bin/paneld" --selftest 2>&1 | sed 's/^/  /'
    echo
    echo "--- agent status (before task) ---"
    "$php_bin" "${ACP_HOME}/agent/bin/paneld" --status 2>&1 | sed 's/^/  /'
  } > "$report" 2>&1

  # queue a real task and wait for the agent to finish it
  step "Queue test: agent.ping"
  local server_id; server_id="$(grep -E '^ACP_SERVER_ID=' "${ACP_HOME}/etc/database.env" | cut -d= -f2)"
  server_id="${server_id:-1}"

  local task_id
  task_id="$(mysql_root -N -B "${DB_NAME}" -e "
    INSERT INTO tasks (server_id, type, safety, payload, status, requested_src, created_at, updated_at)
      VALUES (${server_id}, 'agent.ping', 'readonly', '{}', 'queued', 'installer', NOW(), NOW());
    SELECT LAST_INSERT_ID();")"
  [[ -n "$task_id" && "$task_id" != "0" ]] || die "could not queue the test task"

  info "task #${task_id} queued — paneld ka jawab wait kar rahe hain (max 30s)"

  local waited=0 status="" result="" error=""
  while (( waited < 30 )); do
    IFS=$'\t' read -r status result error < <(mysql_root -N -B "${DB_NAME}" -e \
      "SELECT status, IFNULL(result,''), IFNULL(error,'') FROM tasks WHERE id=${task_id};") || true
    case "$status" in success|failed|cancelled) break ;; esac
    sleep 1; waited=$((waited + 1))
  done

  {
    echo
    echo "--- queue test (task #${task_id}) ---"
    echo "  status      : ${status}"
    echo "  waited      : ${waited}s"
    echo "  result      : ${result}"
    echo "  error       : ${error}"
  } >> "$report"

  say ""
  if [[ "$status" == "success" ]]; then
    ok "agent replied to the queue in ${waited}s"
    say "  result: ${result}"
  else
    err "queue test FAILED (status='${status}', error='${error}')"
    say ""
    say "Diagnostics:"
    tail -15 "${ACP_HOME}/logs/paneld.log" 2>/dev/null | sed 's/^/  /' || true
    (( DRY_RUN )) || {
      echo "  --- journal ---"
      journalctl -u paneld --no-pager -n 15 2>/dev/null | sed 's/^/  /' || true
    }
  fi

  # CLI smoke test
  step "CLI smoke test"
  run_out alphacp task list --limit=5 || true
  run_out alphacp task show "$task_id" || true

  say ""
  ok "report saved: ${report}"
  say ""
  say "${C_BOLD}Ab ye try karo:${C_RESET}"
  say "  alphacp status"
  say "  alphacp doctor"
  say "  alphacp task types"
  say "  sudo alphacp task run system.info"
  say "  sudo alphacp task run service.status"
  say ""
  say "Log: ${LOG_FILE}"
}

# -----------------------------------------------------------------------------
main() {
  ORIG_ARGS=("$@")
  parse_args "$@"
  if (( ! DRY_RUN )); then mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true; fi

  say ""
  say "${C_BOLD}AlphaCP Step 2 installer v${ACP_STEP2_VERSION}${C_RESET} — paneld agent + task queue"
  say "${C_DIM}log: ${LOG_FILE}${C_RESET}"

  phase_preflight
  extract_payload
  phase_database
  phase_migrations
  phase_code
  phase_verify

  say ""
  say "${C_GREEN}${C_BOLD}Step 2 install complete.${C_RESET} Iska output paste karo — phir Step 2B (panel UI) aage badhega."
  say ""
}

main "$@"
# The base64 payload below is DATA, not code — stop before bash reaches it.
exit 0

# @@PAYLOAD_BEGIN@@
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
/ef+87v5/P/5WazPALgBAA==
# @@PAYLOAD_END@@
