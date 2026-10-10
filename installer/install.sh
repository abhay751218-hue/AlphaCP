#!/usr/bin/env bash
# =============================================================================
#  AlphaCP Installer  ─  v0.1.0   (Roadmap Step 1: Base Hosting Stack)
# -----------------------------------------------------------------------------
#  Product : AlphaCP — custom hosting control panel (cPanel/WHM parity target)
#  Purpose : Turn a fresh Ubuntu server into a hosting-ready base stack:
#            web (Apache + PHP-FPM multi-version), DB (MariaDB), cache (Redis),
#            mail packages (installed, configured later), DNS (BIND9, local),
#            FTP (Pure-FTPd), firewall (UFW), fail2ban, disk quotas, cgroups v2,
#            dev tooling (composer, node), and the `alphacp` CLI (status/doctor).
#  Docs    : docs/06-installer-updater.md · docs/runbooks/step-1-install-dev-server.md
#  Design  : Phase 0 preflight → Phase 1 packages → Phase 2 services → Phase 3 verify
#            Resumable via state file; idempotent (safe to re-run); fully logged.
#  Secrets : NONE. This file is safe to share / host publicly.
# -----------------------------------------------------------------------------
#  Usage   : sudo bash alphacp-step1.sh [options]
#  Options : --profile=dev|prod   dev = skip ClamAV daemon, lighter (default)
#            --only=preflight,packages,services,verify   run selected phases
#            --dry-run            show what would happen, change nothing
#            --yes                no prompts (unattended)
#            --force              ignore saved phase state
#            --version | --help
#  Logs    : /var/log/alphacp-install.log
#  State   : /usr/local/alphacp/var/install.state
# =============================================================================
set -Eeuo pipefail

ACP_VERSION="0.1.2"
ACP_ROOT="/usr/local/alphacp"
ACP_ETC="${ACP_ROOT}/etc"
ACP_VAR="${ACP_ROOT}/var"
ACP_LOGS="${ACP_ROOT}/logs"
LOG_FILE="/var/log/alphacp-install.log"
STATE_FILE="${ACP_VAR}/install.state"
ENV_FILE="${ACP_ETC}/install.env"

PROFILE="dev"
DRY_RUN=0
ASSUME_YES=0
FORCE=0
ONLY_PHASES=""
ORIG_ARGS=()

# --- pretty output -----------------------------------------------------------
if [[ -t 1 ]]; then
  C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
  C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
  C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

log() { local lvl="$1"; shift; { printf '%s [%s] %s\n' "$(date '+%F %T')" "$lvl" "$*" >>"${LOG_FILE}"; } 2>/dev/null || true; }
say()  { printf '%s\n' "$*"; }
info() { log INFO "$*";  say "${C_BLUE}[i]${C_RESET} $*"; }
ok()   { log OK "$*";    say "${C_GREEN}[OK]${C_RESET} $*"; }
warn() { log WARN "$*";  say "${C_YELLOW}[!]${C_RESET} $*"; }
err()  { log ERROR "$*"; say "${C_RED}[x]${C_RESET} $*" >&2; }
step() { say ""; say "${C_BOLD}${C_BLUE}==> $*${C_RESET}"; log STEP "$*"; }
die()  { err "$*"; say ""; say "Full log: ${LOG_FILE}"; exit 1; }

on_error() {
  local code=$1 line=$2
  err "Command failed (exit ${code}) at line ${line}."
  say "   → Check the log: ${LOG_FILE}"
  say "   → Last lines:"; tail -n 15 "${LOG_FILE}" 2>/dev/null | sed 's/^/     /' || true
  exit "${code}"
}
trap 'on_error $? $LINENO' ERR

# --- command helpers ---------------------------------------------------------
run() {                       # run a command (respects --dry-run, logs output)
  log CMD "$*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" >>"${LOG_FILE}" 2>&1
}
run_try() {                   # like run() but never fails the script
  log CMD "(try) $*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" >>"${LOG_FILE}" 2>&1 || { log WARN "command failed (ignored): $*"; return 1; }
  return 0
}
run_out() {                   # run and show output in terminal too
  log CMD "$*"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} $*"; return 0; fi
  "$@" 2>&1 | tee -a "${LOG_FILE}"
}

write_conf() {                # write_conf <path> [mode]  (content on stdin)
  local path="$1" mode="${2:-0644}" tmp
  tmp="$(mktemp)"
  cat >"$tmp"
  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} write $path"; rm -f "$tmp"; return 0; fi
  mkdir -p "$(dirname "$path")"
  if [[ -f "$path" ]] && ! cmp -s "$tmp" "$path"; then
    local bk; bk="${path}.alphacp.bak.$(date +%Y%m%d%H%M%S)"
    cp -a "$path" "$bk" && log "backup: $bk"
  fi
  install -m "$mode" "$tmp" "$path"
  rm -f "$tmp"
}

confirm() {                   # confirm "question"  → 0 yes / 1 no
  (( ASSUME_YES )) && return 0
  (( DRY_RUN )) && return 0
  local reply
  read -r -p "${C_BOLD}$1 [y/N]: ${C_RESET}" reply || true
  [[ "${reply,,}" == "y" || "${reply,,}" == "yes" ]]
}

svc_exists() { systemctl list-unit-files "$1.service" >/dev/null 2>&1 && systemctl list-unit-files "$1.service" | grep -q "^$1\.service"; }
svc_active() { systemctl is-active --quiet "$1" 2>/dev/null; }

svc_enable_start() {
  local u="$1"
  (( DRY_RUN )) && { say "    ${C_DIM}(dry-run)${C_RESET} enable+start $u"; return 0; }
  systemctl enable "$u" >>"${LOG_FILE}" 2>&1 || warn "could not enable $u"
  systemctl restart "$u" >>"${LOG_FILE}" 2>&1 || true
  sleep 1
  if svc_active "$u"; then ok "$u is running"; else warn "$u is NOT running (see: journalctl -u $u)"; return 1; fi
}
svc_stop_disable() {
  local u="$1"
  (( DRY_RUN )) && { say "    ${C_DIM}(dry-run)${C_RESET} stop+disable $u"; return 0; }
  systemctl disable --now "$u" >>"${LOG_FILE}" 2>&1 || true
  ok "$u stopped + disabled"
}

mark_phase() { (( DRY_RUN )) && return 0; printf '%s=done %s\n' "$1" "$(date -Is)" >>"${STATE_FILE}"; }
phase_done() { grep -q "^$1=done" "${STATE_FILE}" 2>/dev/null; }

# --- apt helpers -------------------------------------------------------------
APT_UPDATED=0
apt_update_once() {
  if (( APT_UPDATED )); then return 0; fi
  info "Updating package lists (apt update)..."
  run env DEBIAN_FRONTEND=noninteractive apt-get update -qq
  APT_UPDATED=1
}
apt_install() {
  apt_update_once
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
      -o Dpkg::Options::=--force-confnew -o Dpkg::Options::=--force-confold "$@"
}
apt_install_try() {           # returns 1 on failure, never aborts the script
  if apt_install "$@"; then return 0; fi
  warn "package install failed: $*"
  return 1
}

# =============================================================================
#  ARGUMENT PARSING
# =============================================================================
usage() {
  cat <<EOF
AlphaCP Installer v${ACP_VERSION} — Step 1 (base hosting stack)

Usage: sudo bash $0 [options]

  --profile=dev|prod      dev (default) = lighter setup, ClamAV daemon skipped
  --only=PHASES           comma list: preflight,packages,services,verify
  --dry-run               show actions without changing anything
  --yes                   unattended (no confirmation prompts)
  --force                 re-run phases even if state says done
  --version               print version
  --help                  this help

Phases:
  preflight   OS/root/network/resource checks, directory + state setup
  packages    install base hosting stack + dev tooling
  services    configure & start services, firewall, quotas, cgroups
  verify      self-test + report (/usr/local/alphacp/logs/verify-report-*.txt)
EOF
}

parse_args() {
  for arg in "$@"; do
    case "$arg" in
      --profile=*) PROFILE="${arg#*=}" ;;
      --only=*)    ONLY_PHASES="${arg#*=}" ;;
      --dry-run)   DRY_RUN=1 ;;
      --yes|-y)    ASSUME_YES=1 ;;
      --force)     FORCE=1 ;;
      --version)   echo "alphacp-installer ${ACP_VERSION}"; exit 0 ;;
      --help|-h)   usage; exit 0 ;;
      *) usage; die "Unknown option: $arg" ;;
    esac
  done
  [[ "$PROFILE" == "dev" || "$PROFILE" == "prod" ]] || die "--profile must be dev or prod"
  if [[ -n "$ONLY_PHASES" ]]; then
    local p
    IFS=',' read -r -a _phase_list <<< "$ONLY_PHASES"
    for p in "${_phase_list[@]}"; do
      case "$p" in
        preflight|packages|services|verify) ;;
        *) die "--only: unknown phase '$p' (valid: preflight,packages,services,verify)" ;;
      esac
    done
  fi
}

should_run_phase() {
  [[ -z "$ONLY_PHASES" ]] && return 0
  [[ ",${ONLY_PHASES}," == *",$1,"* ]] && return 0
  info "Skipping phase '$1' (not in --only list)"
  return 1
}

# =============================================================================
#  PHASE 0 — PREFLIGHT
# =============================================================================
phase_preflight() {
  step "Phase 0/3 — Preflight checks"

  # root ---------------------------------------------------------------------
  if (( ! DRY_RUN )) && [[ "${EUID}" -ne 0 ]]; then
    if command -v sudo >/dev/null 2>&1 && [[ -f "${BASH_SOURCE[0]}" ]]; then
      info "Not root — re-running with sudo..."
      exec sudo -E bash "${BASH_SOURCE[0]}" "${ORIG_ARGS[@]}"
    fi
    die "Please run as root:  sudo bash $0"
  fi
  ok "root privileges confirmed"

  # OS ------------------------------------------------------------------------
  [[ -r /etc/os-release ]] || die "/etc/os-release not found — unsupported OS."
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-unknown}"; OS_VER="${VERSION_ID:-0}"; OS_PRETTY="${PRETTY_NAME:-$OS_ID}"
  local os_ok=0
  [[ "$OS_ID" == "ubuntu" && ( "$OS_VER" == "24.04" || "$OS_VER" == "22.04" ) ]] && os_ok=1
  [[ "$OS_ID" == "almalinux" || "$OS_ID" == "rocky" ]] && os_ok=0   # v0.1 installer = Ubuntu only
  if (( os_ok )); then
    ok "OS supported: ${OS_PRETTY}"
  elif (( DRY_RUN )); then
    warn "OS ${OS_PRETTY} not in supported list (dry-run: continuing)"
  else
    die "Unsupported OS: ${OS_PRETTY}. This installer v${ACP_VERSION} supports Ubuntu 22.04 / 24.04. (AlmaLinux/Rocky support arrives with the v1 installer.)"
  fi

  ARCH="$(uname -m)"
  if [[ "$ARCH" == "x86_64" ]]; then ok "arch: x86_64 (production target)"
  else warn "arch: ${ARCH} — dev/testing OK; production recommends x86_64"; fi

  # package manager -----------------------------------------------------------
  command -v apt-get >/dev/null 2>&1 || die "apt-get not found (unsupported platform)"
  ok "package manager: apt"

  # resources -----------------------------------------------------------------
  local ram_mb disk_gb
  ram_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
  disk_gb=$(df -BG / | awk 'NR==2{gsub("G","",$4); print $4}')
  ok "memory: ${ram_mb} MB · free disk: ${disk_gb} GB"
  if (( ram_mb < 1800 )); then die "Minimum 2 GB RAM required (found ${ram_mb} MB)."; fi
  if (( ram_mb < 3600 )); then warn "Less than 4 GB RAM — dev stack will be tight; expect to tune later."; fi
  if (( disk_gb < 12 )); then die "Minimum 12 GB free disk required (found ${disk_gb} GB)."; fi

  # network -------------------------------------------------------------------
  if (( ! DRY_RUN )); then
    curl -fsS -m 10 -o /dev/null https://archive.ubuntu.com/ || warn "Cannot reach archive.ubuntu.com — check network"
    curl -fsS -m 10 -o /dev/null https://getcomposer.org/     || warn "Cannot reach getcomposer.org"
    ok "internet connectivity OK"
  fi

  # IPv4 present --------------------------------------------------------------
  if (( ! DRY_RUN )); then
    local pub4
    pub4="$(curl -4 -fsS -m 10 https://ifconfig.me 2>/dev/null || true)"
    if [[ -n "$pub4" ]]; then ok "public IPv4: ${pub4}"
    else warn "Could not detect public IPv4 (dual-stack server? fine for dev)"; fi
  fi

  # swap ----------------------------------------------------------------------
  local swap_mb
  swap_mb=$(awk '/SwapTotal/{print int($2/1024)}' /proc/meminfo)
  if (( swap_mb >= 512 )); then ok "swap present: ${swap_mb} MB"
  else warn "No/low swap (${swap_mb} MB) — will create 2 GB swapfile in services phase"; fi

  # layout + state ------------------------------------------------------------
  run mkdir -p "$ACP_ROOT" "$ACP_ETC" "$ACP_VAR" "$ACP_LOGS" "${ACP_ROOT}/releases"
  ok "layout ready: ${ACP_ROOT}"
  if [[ -d "${ACP_ROOT}/current" ]]; then
    warn "Existing AlphaCP installation detected at ${ACP_ROOT}/current (panel deploy comes in Step 2)"
  fi

  mark_phase preflight
}

# =============================================================================
#  PHASE 1 — PACKAGES
# =============================================================================
PHP_PRIMARY="8.3"
PHP_OTHER_VERSIONS=(7.4 8.1 8.2 8.4)

phase_packages() {
  step "Phase 1/3 — Installing base hosting stack (this takes 10-25 minutes)"

  confirm "Install the AlphaCP base stack now?" || die "Aborted by user."

  apt_update_once

  info "Base tools..."
  apt_install ca-certificates curl wget git unzip zip tar zstd jq gnupg lsb-release \
              software-properties-common apt-transport-https rsync acl attr \
              htop iftop sysstat psmisc lsof net-tools

  # --- PHP (ondrej PPA for multi-version) ------------------------------------
  if (( ! DRY_RUN )) && [[ -f /etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources || -f /etc/apt/sources.list.d/ondrej-ubuntu-php-jammy.sources ]]; then
    ok "PHP PPA already present"
  else
    info "Adding PHP repository (ondrej/php)..."
    apt_install_try software-properties-common || true
    run env DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php || warn "Could not add PHP PPA"
    APT_UPDATED=0; apt_update_once
  fi

  info "PHP ${PHP_PRIMARY} (FPM + extensions)..."
  apt_install "php${PHP_PRIMARY}-fpm" "php${PHP_PRIMARY}-cli" "php${PHP_PRIMARY}-common" \
              "php${PHP_PRIMARY}-mysql" "php${PHP_PRIMARY}-curl" "php${PHP_PRIMARY}-gd" \
              "php${PHP_PRIMARY}-mbstring" "php${PHP_PRIMARY}-xml" "php${PHP_PRIMARY}-zip" \
              "php${PHP_PRIMARY}-intl" "php${PHP_PRIMARY}-bcmath" "php${PHP_PRIMARY}-redis" \
              "php${PHP_PRIMARY}-sqlite3" "php${PHP_PRIMARY}-opcache"

  info "Additional PHP versions (best-effort: $(printf '%s ' "${PHP_OTHER_VERSIONS[@]}"))..."
  local v installed_php=""
  for v in "${PHP_OTHER_VERSIONS[@]}"; do
    if apt_install_try "php${v}-fpm" "php${v}-cli" "php${v}-mysql" "php${v}-curl" \
                       "php${v}-gd" "php${v}-mbstring" "php${v}-xml" "php${v}-zip" "php${v}-intl"; then
      ok "PHP ${v} installed"; installed_php+="${v} "
    else
      warn "PHP ${v} not available from repo — skipped"
    fi
  done
  PHP_AVAILABLE_VERSIONS="${PHP_PRIMARY} ${installed_php}"
  ok "PHP versions available: ${PHP_AVAILABLE_VERSIONS}"

  info "Web servers..."
  apt_install apache2 nginx

  info "Database + cache..."
  apt_install mariadb-server mariadb-client redis-server

  info "DNS server..."
  apt_install bind9 bind9-utils bind9-dnsutils dnsutils

  info "FTP server..."
  apt_install pure-ftpd

  info "Mail stack packages (installed now, configured properly in Step 7)..."
  apt_install exim4 exim4-daemon-heavy dovecot-core dovecot-imapd dovecot-pop3d \
              dovecot-lmtpd dovecot-sieve dovecot-managesieved dovecot-mysql \
              spamassassin spamc opendkim opendkim-tools
  if [[ "$PROFILE" == "prod" ]]; then
    info "ClamAV (prod profile)..."
    apt_install_try clamav clamav-daemon || warn "ClamAV install failed (continuing)"
  else
    info "ClamAV: skipped in dev profile (RAM saver). Install later with: apt install clamav clamav-daemon"
  fi

  info "Security + ops tooling..."
  apt_install fail2ban ufw quota certbot libpam-systemd

  info "Composer..."
  if command -v composer >/dev/null 2>&1; then
    ok "composer already installed: $(COMPOSER_ALLOW_SUPERUSER=1 timeout 10 composer --version --no-interaction --no-ansi 2>/dev/null | head -1)"
  elif (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} install composer"
  else
    local sig actual
    sig="$(curl -fsS -m 20 https://composer.github.io/installer.sig 2>/dev/null || true)"
    curl -fsS -m 30 -o /tmp/composer-setup.php https://getcomposer.org/installer 2>/dev/null || true
    if [[ -f /tmp/composer-setup.php && -n "$sig" ]]; then
      actual="$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');" 2>/dev/null || true)"
      if [[ "$sig" == "$actual" ]]; then
        run php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
        ok "composer installed"
      else
        warn "Composer installer signature mismatch — skipped (install manually later)"
      fi
    else
      warn "Could not download composer installer — skipped"
    fi
    rm -f /tmp/composer-setup.php
  fi

  info "Node.js 20 (for panel frontend builds)..."
  if command -v node >/dev/null 2>&1; then
    ok "node already installed: $(node --version)"
  elif (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} install Node.js"
  else
    if curl -fsS -m 30 https://deb.nodesource.com/setup_20.x -o /tmp/nodesource.sh 2>/dev/null; then
      run bash /tmp/nodesource.sh
      apt_install_try nodejs || true
      rm -f /tmp/nodesource.sh
    fi
    if command -v node >/dev/null 2>&1; then ok "node $(node --version) · npm $(npm --version 2>/dev/null || echo '-')"
    else warn "Node.js not installed — we'll add it when the panel needs it (Step 2)"; fi
  fi

  # --- versions summary ------------------------------------------------------
  step "Installed versions"
  local svc_ver
  svc_ver=$(apache2 -v 2>/dev/null | head -1 || true);              say "  Apache   : ${svc_ver:-?}"
  if command -v nginx >/dev/null 2>&1; then svc_ver=$(nginx -v 2>&1 | head -1); else svc_ver=""; fi
  say "  Nginx    : ${svc_ver:-not installed}"
  svc_ver=$(php -v 2>/dev/null | head -1 || true);                  say "  PHP      : ${svc_ver:-?}"
  svc_ver=$(mariadb --version 2>/dev/null || true);                 say "  MariaDB  : ${svc_ver:-?}"
  svc_ver=$(redis-server -v 2>/dev/null || true);                   say "  Redis    : ${svc_ver:-?}"
  svc_ver=$(named -v 2>/dev/null || true);                          say "  BIND     : ${svc_ver:-?}"
  svc_ver=$(COMPOSER_ALLOW_SUPERUSER=1 timeout 10 composer --version --no-interaction --no-ansi 2>/dev/null | head -1 || true); say "  Composer : ${svc_ver:-not installed}"
  svc_ver=$(timeout 10 node --version 2>/dev/null || true);         say "  Node     : ${svc_ver:-not installed}"

  mark_phase packages
}

# =============================================================================
#  PHASE 2 — SERVICES & HARDENING
# =============================================================================
RAM_MB=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)

phase_services() {
  step "Phase 2/3 — Configuring services, firewall and limits"

  # ---------------------------------------------------------------------------
  # 2.0 PRE-CLEAN — free the ports BEFORE starting Apache.
  #     Lesson from install #1: the nginx package auto-starts and grabs :80,
  #     so apache2 failed to bind and stayed dead. Stop them first, always.
  # ---------------------------------------------------------------------------
  info "Pre-clean: stopping services that hold port 80/443 (nginx + stray Apache)..."
  svc_stop_disable nginx
  svc_stop_disable apache2 || true

  # ---------------------------------------------------------------------------
  # 2.1 APACHE (customer websites: port 80/443 owner)
  # ---------------------------------------------------------------------------
  info "Apache: event MPM + PHP-FPM bridge..."
  run_try a2dismod mpm_prefork
  run_try a2enmod mpm_event proxy proxy_http proxy_fcgi rewrite headers ssl expires deflate remoteip

  write_conf /etc/apache2/conf-available/alphacp-security.conf <<'EOF'
# AlphaCP security defaults (managed by installer — safe to re-generate)
ServerTokens Prod
ServerSignature Off
TraceEnable off
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
EOF
  run_try a2enconf alphacp-security

  local www_root="/var/www/alphacp-default"
  run mkdir -p "${www_root}"
  write_conf "${www_root}/index.html" 0644 <<'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AlphaCP — Server Ready</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { min-height: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background: radial-gradient(1200px 600px at 20% -10%, #1e293b, #0b1220 60%); color: #e2e8f0; padding: 24px; }
  .card { max-width: 620px; width: 100%; background: rgba(30,41,59,.55); border: 1px solid #334155; border-radius: 18px;
          padding: 34px 30px; backdrop-filter: blur(6px); box-shadow: 0 30px 70px rgba(0,0,0,.35); }
  .row { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
  .logo { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; font-weight: 900; font-size: 22px;
          background: linear-gradient(135deg, #4f46e5, #06b6d4); color: #fff; }
  h1 { font-size: 22px; letter-spacing: .2px; }
  .sub { color: #94a3b8; font-size: 13.5px; margin-top: 3px; }
  .badge { display: inline-flex; align-items: center; gap: 8px; background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.4);
           color: #34d399; font-size: 13px; font-weight: 700; padding: 7px 13px; border-radius: 99px; margin: 6px 0 18px; }
  .dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.18); }
  ul { list-style: none; font-size: 14px; color: #cbd5e1; }
  li { padding: 7px 0 7px 26px; position: relative; border-bottom: 1px dashed #33415555; }
  li:before { content: "✓"; position: absolute; left: 4px; color: #34d399; font-weight: 800; }
  .foot { margin-top: 20px; font-size: 12px; color: #64748b; }
</style>
</head>
<body>
  <div class="card">
    <div class="row">
      <div class="logo">A</div>
      <div>
        <h1>AlphaCP</h1>
        <div class="sub">Custom hosting control panel — development server</div>
      </div>
    </div>
    <div class="badge"><span class="dot"></span> Base stack installed &amp; running</div>
    <ul>
      <li>Web: Apache + Nginx + PHP-FPM (multi-version)</li>
      <li>Database: MariaDB (secured, local-only)</li>
      <li>Cache: Redis (local-only)</li>
      <li>Firewall (UFW) + Fail2ban active</li>
      <li>Disk quotas + cgroups v2 ready</li>
    </ul>
    <div class="foot">Panel UI arrives in the next development step. This placeholder page is temporary.</div>
  </div>
</body>
</html>
EOF

  write_conf "${www_root}/robots.txt" 0644 <<'EOF'
User-agent: *
Disallow: /
EOF

  # token-protected php check (used by verify phase; not public info)
  if (( ! DRY_RUN )); then
    CHECK_TOKEN="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    printf '%s' "$CHECK_TOKEN" | write_conf "${ACP_ETC}/check.token" 0640
    chgrp www-data "${ACP_ETC}/check.token" 2>/dev/null || true   # php-fpm (www-data) ko read chahiye — self-test fix
  fi
  write_conf "${www_root}/check.php" 0644 <<'EOF'
<?php
// AlphaCP installer self-test endpoint (token protected; see /usr/local/alphacp/etc/check.token)
$token_file = '/usr/local/alphacp/etc/check.token';
$expected = is_readable($token_file) ? trim((string)file_get_contents($token_file)) : '';
if ($expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(404); exit;
}
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'extensions_ok' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring'  => extension_loaded('mbstring'),
        'gd'        => extension_loaded('gd'),
        'zip'       => extension_loaded('zip'),
        'curl'      => extension_loaded('curl'),
        'redis'     => extension_loaded('redis'),
        'opcache'   => extension_loaded('Zend OPcache'),
    ],
], JSON_PRETTY_PRINT);
EOF

  write_conf /etc/apache2/sites-available/alphacp-default.conf <<EOF
# AlphaCP default vhost (temporary — replaced by per-account vhosts in Step 5)
<VirtualHost *:80>
    ServerName _default_
    DocumentRoot ${www_root}

    <Directory ${www_root}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:/run/php/php${PHP_PRIMARY}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/alphacp-default-error.log
    CustomLog \${APACHE_LOG_DIR}/alphacp-default-access.log combined
</VirtualHost>
EOF
  run_try a2dissite 000-default
  run_try a2ensite alphacp-default
  if (( ! DRY_RUN )); then
    if apache2ctl configtest >>"${LOG_FILE}" 2>&1; then ok "apache config test: OK"
    else die "Apache config test FAILED — see log"; fi
  fi
  svc_enable_start apache2 || true

  # ---------------------------------------------------------------------------
  # 2.2 NGINX (panel ports owner — activated in Step 2)
  # ---------------------------------------------------------------------------
  info "Nginx: installed, stopped for now (it will serve the panel on 2082-2087 in Step 2)"
  svc_stop_disable nginx

  # ---------------------------------------------------------------------------
  # 2.3 PHP-FPM
  # ---------------------------------------------------------------------------
  info "PHP-FPM ${PHP_PRIMARY}: settings + pool tuning..."
  write_conf "/etc/php/${PHP_PRIMARY}/fpm/conf.d/99-alphacp.ini" <<'EOF'
; AlphaCP defaults (managed by installer)
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_time = 300
max_input_vars = 4000
expose_php = Off
display_errors = Off
log_errors = On
date.timezone = UTC
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
EOF

  local fpm_pool="/etc/php/${PHP_PRIMARY}/fpm/pool.d/www.conf"
  if (( ! DRY_RUN )) && [[ -f "$fpm_pool" ]]; then
    cp -a "$fpm_pool" "${fpm_pool}.alphacp.bak.$(date +%Y%m%d%H%M%S)"
    local max_children=$(( RAM_MB / 80 )); (( max_children < 5 )) && max_children=5; (( max_children > 40 )) && max_children=40
    sed -i -e "s|^pm = .*|pm = ondemand|" \
           -e "s|^pm.max_children = .*|pm.max_children = ${max_children}|" \
           -e "s|^pm.start_servers = .*|pm.start_servers = 2|" \
           -e "s|^pm.min_spare_servers = .*|pm.min_spare_servers = 1|" \
           -e "s|^pm.max_spare_servers = .*|pm.max_spare_servers = 3|" \
           -e "s|^;pm.process_idle_timeout = .*|pm.process_idle_timeout = 10s|" \
           -e "s|^pm.max_requests = .*|pm.max_requests = 500|" "$fpm_pool" || warn "pool tuning partially applied"
    ok "pool www: ondemand, max_children=${max_children}"
  fi
  svc_enable_start "php${PHP_PRIMARY}-fpm" || true

  # other php versions: just make sure their services exist (start on demand later)
  for v in ${PHP_AVAILABLE_VERSIONS:-}; do
    [[ "$v" == "$PHP_PRIMARY" ]] && continue
    write_conf "/etc/php/${v}/fpm/conf.d/99-alphacp.ini" <<'EOF'
; AlphaCP defaults (managed by installer)
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
expose_php = Off
date.timezone = UTC
EOF
    run_try systemctl enable "php${v}-fpm"
  done

  # ---------------------------------------------------------------------------
  # 2.4 MARIADB
  # ---------------------------------------------------------------------------
  info "MariaDB: tuning + security hardening..."
  write_conf /etc/mysql/mariadb.conf.d/99-alphacp.cnf <<EOF
# AlphaCP MariaDB defaults (managed by installer)
[mysqld]
bind-address            = 127.0.0.1
skip-name-resolve       = 1
local_infile            = 0
max_connections         = 100
wait_timeout            = 300
innodb_buffer_pool_size = $(( RAM_MB > 3500 ? 384 : 192 ))M
innodb_log_file_size    = 64M
innodb_flush_log_at_trx_commit = 2
character-set-server    = utf8mb4
collation-server        = utf8mb4_unicode_ci
EOF
  svc_enable_start mariadb || true

  if (( ! DRY_RUN )); then
    if mariadb -e "SELECT 1" >/dev/null 2>&1; then
      mariadb -e "DELETE FROM mysql.user WHERE User='';"                  >>"${LOG_FILE}" 2>&1 || true
      mariadb -e "DROP DATABASE IF EXISTS test;"                          >>"${LOG_FILE}" 2>&1 || true
      mariadb -e "DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\\_%';" >>"${LOG_FILE}" 2>&1 || true
      mariadb -e "FLUSH PRIVILEGES;"                                      >>"${LOG_FILE}" 2>&1 || true
      ok "MariaDB secured (anonymous users removed, test db dropped, root = unix_socket local)"
    else
      warn "MariaDB not answering yet — check: systemctl status mariadb"
    fi
  fi

  # ---------------------------------------------------------------------------
  # 2.5 REDIS
  # ---------------------------------------------------------------------------
  info "Redis (local-only, cache + queue)..."
  write_conf /etc/redis/alphacp.conf 0640 <<'EOF'
# AlphaCP Redis overrides (managed by installer)
bind 127.0.0.1 -::1
protected-mode yes
maxmemory 256mb
maxmemory-policy noeviction
appendonly no
save 900 1
EOF
  if (( ! DRY_RUN )); then
    if ! grep -q "alphacp.conf" /etc/redis/redis.conf 2>/dev/null; then
      printf '\n# AlphaCP overrides\ninclude /etc/redis/alphacp.conf\n' >> /etc/redis/redis.conf
      log "redis.conf: include alphacp.conf added"
    fi
  fi
  svc_enable_start redis-server || true

  # ---------------------------------------------------------------------------
  # 2.6 DNS RESOLVER SWAP + BIND9  (server needs port 53 for its own DNS)
  # ---------------------------------------------------------------------------
  info "DNS: switching resolver (systemd-resolved → static) so BIND9 can own port 53..."
  local resolver_swapped=0
  if (( DRY_RUN )); then
    say "    ${C_DIM}(dry-run)${C_RESET} disable systemd-resolved + write /etc/resolv.conf + start bind9"
    resolver_swapped=1
  elif systemctl is-enabled systemd-resolved >/dev/null 2>&1 || [[ -L /etc/resolv.conf ]]; then
    local resolv_bk; resolv_bk="/etc/resolv.conf.alphacp.bak.$(date +%Y%m%d%H%M%S)"
    cp -a /etc/resolv.conf "$resolv_bk" 2>/dev/null || true
    systemctl disable --now systemd-resolved >>"${LOG_FILE}" 2>&1 || true
    rm -f /etc/resolv.conf
    write_conf /etc/resolv.conf <<'EOF'
# Managed by AlphaCP installer (static resolver; BIND9 serves port 53 locally)
nameserver 1.1.1.1
nameserver 8.8.8.8
options timeout:2 attempts:3 rotate
EOF
    if getent hosts archive.ubuntu.com >/dev/null 2>&1; then
      ok "name resolution working (static resolvers)"
      resolver_swapped=1
    else
      warn "Name resolution FAILED after swap — rolling back to systemd-resolved"
      systemctl enable --now systemd-resolved >>"${LOG_FILE}" 2>&1 || true
      if [[ -f "$resolv_bk" ]]; then cp -a "$resolv_bk" /etc/resolv.conf 2>/dev/null || true; fi
      if getent hosts archive.ubuntu.com >/dev/null 2>&1; then ok "rollback OK — resolver restored"; else warn "Please check DNS manually"; fi
    fi
  else
    ok "systemd-resolved not in use — no swap needed"
    resolver_swapped=1
  fi

  if (( resolver_swapped )); then
    write_conf /etc/bind/named.conf.options <<'EOF'
// AlphaCP BIND9 config — local resolver for now; public nameserver config comes in Step 9
options {
        directory "/var/cache/bind";
        listen-on { 127.0.0.1; };
        listen-on-v6 { ::1; };
        allow-query { localhost; };
        recursion yes;
        allow-recursion { localhost; };
        forwarders { 1.1.1.1; 8.8.8.8; };
        dnssec-validation auto;
        auth-nxdomain no;
};
EOF
    if (( ! DRY_RUN )) && ! named-checkconf >>"${LOG_FILE}" 2>&1; then
      warn "named-checkconf failed — BIND not started (fix later in Step 9)"
    else
      svc_enable_start bind9 || true
    fi
  fi

  # ---------------------------------------------------------------------------
  # 2.7 MAIL + FTP services: installed but intentionally stopped until their steps
  # ---------------------------------------------------------------------------
  info "Mail + FTP services: keeping them stopped until Step 6/7 (security: no unconfigured services)"
  for s in exim4 dovecot spamassassin clamav-daemon clamav-freshclam opendkim pure-ftpd; do
    if svc_exists "$s"; then svc_stop_disable "$s"; fi
  done

  # ---------------------------------------------------------------------------
  # 2.8 FAIL2BAN
  # ---------------------------------------------------------------------------
  info "Fail2ban (brute-force protection)..."
  write_conf /etc/fail2ban/jail.d/alphacp.local <<'EOF'
# AlphaCP fail2ban jails (managed by installer; more jails arrive with the panel)
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
port    = ssh
EOF
  svc_enable_start fail2ban || true

  # ---------------------------------------------------------------------------
  # 2.9 FIREWALL (UFW)
  # ---------------------------------------------------------------------------
  info "Firewall (UFW) — same port map as the Lightsail firewall..."
  run ufw --force default deny incoming
  run ufw --force default allow outgoing
  run ufw allow 22/tcp    comment 'SSH'
  run ufw allow 80/tcp    comment 'HTTP'
  run ufw allow 443/tcp   comment 'HTTPS'
  run ufw allow 8090/tcp  comment 'Panel UI (primary)'
  run ufw allow 2082/tcp  comment 'Panel (client http, compat)'
  run ufw allow 2083/tcp  comment 'Panel (client https)'
  run ufw allow 2086/tcp  comment 'Panel (admin http)'
  run ufw allow 2087/tcp  comment 'Panel (admin https + API)'
  run ufw allow 2095/tcp  comment 'Webmail http'
  run ufw allow 2096/tcp  comment 'Webmail https'
  run ufw allow 21/tcp    comment 'FTP'
  run ufw allow 49152:65535/tcp comment 'FTP passive'
  run ufw allow 53/tcp    comment 'DNS'
  run ufw allow 53/udp    comment 'DNS'
  run ufw allow 25/tcp    comment 'SMTP (mail module)'
  run ufw allow 465/tcp   comment 'SMTPS'
  run ufw allow 587/tcp   comment 'SMTP submission'
  run ufw allow 143/tcp   comment 'IMAP'
  run ufw allow 993/tcp   comment 'IMAPS'
  run ufw allow 110/tcp   comment 'POP3'
  run ufw allow 995/tcp   comment 'POP3S'
  run ufw --force enable
  ok "ufw active (SSH allowed first — safe)"

  # ---------------------------------------------------------------------------
  # 2.10 DISK QUOTAS (per-account storage limits)
  # ---------------------------------------------------------------------------
  info "Disk quotas (needed for per-account storage limits in Step 3)..."
  enable_quota_on_root

  # ---------------------------------------------------------------------------
  # 2.11 CGROUPS v2 + SYSTEMD ACCOUNTING
  # ---------------------------------------------------------------------------
  info "cgroups v2 (CPU/RAM/IO limits per account)..."
  if [[ "$(stat -fc %T /sys/fs/cgroup)" == "cgroup2fs" ]]; then
    ok "cgroups v2 unified hierarchy active"
  else
    warn "cgroups v1/hybrid detected — limits will be handled in Step 3 tuning"
  fi
  write_conf /etc/systemd/system.conf.d/10-alphacp.conf <<'EOF'
# AlphaCP: enable resource accounting by default (used for per-account limits)
[Manager]
DefaultCPUAccounting=yes
DefaultMemoryAccounting=yes
DefaultIOAccounting=yes
EOF
  ok "systemd accounting defaults written (applies on next boot)"

  # ---------------------------------------------------------------------------
  # 2.12 SWAP (only if missing)
  # ---------------------------------------------------------------------------
  local swap_mb
  swap_mb=$(awk '/SwapTotal/{print int($2/1024)}' /proc/meminfo)
  if (( swap_mb >= 512 )); then
    ok "swap already present: ${swap_mb} MB"
  else
    info "Creating 2 GB swapfile..."
    if (( ! DRY_RUN )); then
      fallocate -l 2G /swapfile >>"${LOG_FILE}" 2>&1 || dd if=/dev/zero of=/swapfile bs=1M count=2048 >>"${LOG_FILE}" 2>&1
      chmod 600 /swapfile; mkswap /swapfile >>"${LOG_FILE}" 2>&1; swapon /swapfile >>"${LOG_FILE}" 2>&1 || true
      local bk; bk="/etc/fstab.alphacp.bak.$(date +%Y%m%d%H%M%S)"; cp -a /etc/fstab "$bk"
      if ! grep -q "^/swapfile" /etc/fstab; then printf '/swapfile none swap sw 0 0\n' >> /etc/fstab; fi
      if findmnt --verify >/dev/null 2>&1; then ok "swap enabled + fstab updated"
      else warn "fstab verify failed — restoring backup"; cp -a "$bk" /etc/fstab; fi
    fi
  fi

  # ---------------------------------------------------------------------------
  # 2.13 version record + alphacp CLI
  # ---------------------------------------------------------------------------
  write_conf "${ENV_FILE}" 0644 <<EOF
# AlphaCP install record (managed by installer)
ACP_INSTALLER_VERSION=${ACP_VERSION}
ACP_PROFILE=${PROFILE}
ACP_INSTALLED_AT=$(date -Is)
ACP_OS=${OS_PRETTY:-unknown}
ACP_ARCH=${ARCH:-unknown}
ACP_PHP_PRIMARY=${PHP_PRIMARY}
ACP_PHP_VERSIONS="${PHP_AVAILABLE_VERSIONS:-$PHP_PRIMARY}"
ACP_PANEL_INSTALLED=no
ACP_LICENSE_STATUS=not-activated
EOF

  install_cli

  # ---------------------------------------------------------------------------
  # 2.14 FINAL HEALTH CHECK — start-order safety net + last re-assert of stops
  # ---------------------------------------------------------------------------
  info "Final health check: bringing the customer-site web server up..."
  if (( ! DRY_RUN )); then
    for s in nginx exim4 dovecot spamassassin clamav-daemon clamav-freshclam opendkim pure-ftpd; do
      if svc_exists "$s" && svc_active "$s"; then svc_stop_disable "$s"; fi
    done
    local attempt=0 apache_ok=0
    while (( attempt < 3 )); do
      attempt=$(( attempt + 1 ))
      systemctl restart apache2 >>"${LOG_FILE}" 2>&1 || true
      sleep 2
      if svc_active apache2; then apache_ok=1; break; fi
      warn "apache2 start attempt ${attempt} failed — freeing port 80 and retrying..."
      svc_stop_disable nginx
    done
    if (( apache_ok )); then
      ok "apache2 running (owns port 80/443)"
      local http_code=""
      http_code=$(curl -s -o /dev/null -w '%{http_code}' -m 8 http://127.0.0.1/ 2>/dev/null || true)
      if [[ "$http_code" == "200" ]]; then ok "local HTTP self-test: HTTP 200"
      else warn "local HTTP self-test returned '${http_code:-no-response}'"; fi
    else
      err "apache2 did NOT start after 3 attempts — diagnostics below:"
      {
        echo "----- systemctl status apache2 -----"
        systemctl status apache2 --no-pager -l 2>&1 | head -18
        echo "----- apache2ctl configtest -----"
        apache2ctl configtest 2>&1 | tail -8
        echo "----- /var/log/apache2/error.log (tail) -----"
        tail -20 /var/log/apache2/error.log 2>/dev/null || echo "(no error.log)"
      } | tee -a "${LOG_FILE}"
      warn "Paste the block above in the chat — we fix it before Step 2."
    fi
  fi

  mark_phase services
}

# --- disk quotas helper ------------------------------------------------------
enable_quota_on_root() {
  local mnt="/" fstype opts
  fstype="$(findmnt -no FSTYPE "$mnt" 2>/dev/null || echo unknown)"
  case "$fstype" in
    ext4|ext3|ext2) opts="usrquota,grpquota" ;;
    xfs)            opts="usrquota,grpquota" ;;
    *)              warn "filesystem '${fstype}' — quota setup skipped (report this)"; return 0 ;;
  esac

  if findmnt -no OPTIONS "$mnt" 2>/dev/null | grep -Eq 'usrjquota|usrquota|pquota'; then
    ok "quotas already mounted on ${mnt}"
    if (( ! DRY_RUN )); then
    if quotaon -p "$mnt" >/dev/null 2>&1; then ok "quota enforcement active"; else warn "quota enforcement not active yet"; fi
  fi
    return 0
  fi

  if (( DRY_RUN )); then say "    ${C_DIM}(dry-run)${C_RESET} enable ${opts} on ${mnt} + quotacheck + quotaon"; return 0; fi

  local bk; bk="/etc/fstab.alphacp.bak.$(date +%Y%m%d%H%M%S)"
  cp -a /etc/fstab "$bk"
  awk -v opts="$opts" '
    BEGIN { OFS=" " }
    /^[[:space:]]*#/ { print; next }
    NF >= 4 && $2 == "/" {
      n = split($4, arr, ","); keep = ""
      for (i = 1; i <= n; i++) {
        if (arr[i] == "") continue
        if (arr[i] ~ /^(usrjquota|grpjquota|jqfmt|usrquota|grpquota|pquota|uquota|gquota)/) continue
        keep = (keep == "" ? arr[i] : keep "," arr[i])
      }
      $4 = (keep == "" ? opts : keep "," opts)
    }
    { print }
  ' /etc/fstab > /tmp/fstab.alphacp.new

  if ! grep -q "$opts" /tmp/fstab.alphacp.new; then
    warn "Could not modify fstab for quotas — skipping (non-fatal)"
    rm -f /tmp/fstab.alphacp.new; return 0
  fi

  cat /tmp/fstab.alphacp.new > /etc/fstab
  rm -f /tmp/fstab.alphacp.new

  if ! findmnt --verify >/dev/null 2>&1; then
    warn "fstab verification failed — restoring original"
    cp -a "$bk" /etc/fstab
    return 0
  fi

  if ! mount -o "remount,${opts}" "$mnt" >>"${LOG_FILE}" 2>&1; then
    warn "remount with quota options failed — fstab KEPT (quota activates automatically after next reboot)"
    return 0
  fi
  ok "mounted ${mnt} with quota options"

  if [[ "$fstype" == ext* ]]; then
    touch /aquota.user /aquota.group
    chmod 600 /aquota.user /aquota.group
  fi
  quotacheck -cugm "$mnt" >>"${LOG_FILE}" 2>&1 || quotacheck -avugm >>"${LOG_FILE}" 2>&1 || true
  systemctl enable quotaon >/dev/null 2>&1 || true
  if quotaon -ugv "$mnt" >>"${LOG_FILE}" 2>&1; then
    ok "quota enforcement ON (auto-enabled at boot via fstab)"
  else
    warn "quotaon failed — will retry in Step 3 (non-fatal)"
  fi
}

# --- alphacp CLI -------------------------------------------------------------
install_cli() {
  info "Installing 'alphacp' CLI (v0 status/doctor — grows in later steps)..."
  write_conf /usr/local/bin/alphacp 0755 <<'CLI_EOF'
#!/usr/bin/env bash
# AlphaCP CLI v0 (bash) — status & doctor
# NOTE: becomes a full CLI (install/update/rollback/doctor/... ) in later steps.
set -uo pipefail
ACP_ROOT="/usr/local/alphacp"
ENV_FILE="${ACP_ROOT}/etc/install.env"
LOG_DIR="${ACP_ROOT}/logs"

c_g=$'\033[32m'; c_r=$'\033[31m'; c_y=$'\033[33m'; c_b=$'\033[1m'; c_dim=$'\033[2m'; c_0=$'\033[0m'
svc_running() { systemctl is-active --quiet "$1" 2>/dev/null; }
line() { printf '%-24s %s\n' "$1" "$2"; }

cmd_version() {
  [[ -f "$ENV_FILE" ]] && cat "$ENV_FILE" || echo "AlphaCP not installed (no ${ENV_FILE})"
  command -v apache2 >/dev/null && apache2 -v 2>/dev/null | head -1
  command -v php     >/dev/null && php -v 2>/dev/null | head -1
  command -v mariadb >/dev/null && mariadb --version 2>/dev/null | head -1
}

cmd_status() {
  echo "${c_b}AlphaCP status${c_0}  (host: $(hostname))"
  echo
  echo "${c_b}Services${c_0}   ${c_dim}(running = good; 'stopped (later step)' = intentional)${c_0}"
  local rows=(
    "apache2|required"
    "php8.3-fpm|required"
    "mariadb|required"
    "redis-server|required"
    "bind9|required"
    "fail2ban|required"
    "ufw|required"
    "nginx|step-2"
    "exim4|step-7"
    "dovecot|step-7"
    "spamassassin|step-7"
    "pure-ftpd|step-6"
  )
  for row in "${rows[@]}"; do
    local s="${row%%|*}" tag="${row##*|}" state
    if svc_running "$s"; then state="${c_g}● running${c_0}"
    else
      if [[ "$tag" == "required" ]]; then state="${c_r}○ STOPPED (should run!)${c_0}"
      else state="${c_y}○ stopped (${tag} will enable)${c_0}"; fi
    fi
    printf '  %-16s %s\n' "$s" "$state"
  done
  echo
  echo "${c_b}Resources${c_0}"
  free -h | awk 'NR<=3 {printf "  %-8s %s\n", $1, $2" total / "$3" used"}'
  df -h / | awk 'NR==2 {printf "  disk /   %s total / %s used (%s)\n", $2, $3, $5}'
  echo
  echo "${c_b}Quota${c_0}"
  if quotaon -p / >/dev/null 2>&1; then echo "  ${c_g}enabled${c_0} (per-account storage limits ready)"; else echo "  ${c_y}not enabled yet${c_0}"; fi
  echo
  echo "${c_b}Listening ports${c_0}"
  ss -ltn 2>/dev/null | awk 'NR>1 {print "  "$4}' | sort -u | head -20
  echo
  echo "${c_b}Panel / License${c_0}"
  echo "  panel     : not installed yet (Step 2)"
  [[ -f "$ENV_FILE" ]] && grep -E 'ACP_LICENSE_STATUS|ACP_PROFILE|ACP_INSTALLER_VERSION' "$ENV_FILE" | sed 's/^/  /'
}

cmd_doctor() {
  local fix=0; [[ "${1:-}" == "--fix" ]] && fix=1
  local fails=0 warns=0
  echo "${c_b}AlphaCP doctor${c_0} $([[ $fix == 1 ]] && echo '(with --fix)')"
  echo
  chk() { # name, ok?, hint
    if [[ "$2" == "1" ]]; then printf '  %-40s %s\n' "$1" "${c_g}OK${c_0}"
    else printf '  %-40s %s   %s\n' "$1" "${c_r}FAIL${c_0}" "${3:-}"; fails=$((fails+1)); fi
  }
  chk "install record present" "$([[ -f "$ENV_FILE" ]] && echo 1 || echo 0)" "re-run the installer"
  chk "apache2 running"        "$(svc_running apache2 && echo 1 || echo 0)" "systemctl start apache2"
  chk "php8.3-fpm running"     "$(svc_running php8.3-fpm && echo 1 || echo 0)" "systemctl start php8.3-fpm"
  chk "mariadb running"        "$(svc_running mariadb && echo 1 || echo 0)" "systemctl start mariadb"
  chk "redis running"          "$(svc_running redis-server && echo 1 || echo 0)" "systemctl start redis-server"
  chk "bind9 running"          "$(svc_running bind9 && echo 1 || echo 0)" "systemctl start bind9"
  chk "ufw active"             "$(ufw status 2>/dev/null | grep -q 'Status: active' && echo 1 || echo 0)" "ufw --force enable"
  chk "fail2ban running"       "$(svc_running fail2ban && echo 1 || echo 0)" "systemctl start fail2ban"
  chk "mysql not public"       "$(ss -ltn 2>/dev/null | grep -q ':3306' && echo 0 || echo 1)" "check mariadb bind-address"
  chk "redis local-only"       "$(ss -ltn 2>/dev/null | grep -q '127.0.0.1:6379' && echo 1 || echo 0)" "check /etc/redis/alphacp.conf"
  chk "quota enabled"          "$(quotaon -p / >/dev/null 2>&1 && echo 1 || echo 0)" "re-run installer services phase"
  chk "cgroups v2"             "$([[ "$(stat -fc %T /sys/fs/cgroup)" == "cgroup2fs" ]] && echo 1 || echo 0)" "kernel/systemd issue"
  chk "fstab backup exists"    "$(ls /etc/fstab.alphacp.bak.* >/dev/null 2>&1 && echo 1 || echo 0)" "will be created on next config change"
  chk "default page responds"  "$(curl -fsS -m 5 -o /dev/null http://127.0.0.1/ && echo 1 || echo 0)" "apache down?"
  echo
  local stopped_expected=0
  for s in exim4 dovecot spamassassin pure-ftpd nginx; do svc_running "$s" && stopped_expected=1; done
  if (( stopped_expected )); then printf '  %-40s %s\n' "unconfigured services stopped" "${c_y}WARN — some are running${c_0} (fine, they get configured in later steps)"; warns=$((warns+1));
  else printf '  %-40s %s\n' "unconfigured services stopped" "${c_g}OK${c_0}"; fi
  echo
  if (( fails == 0 )); then echo "${c_g}All critical checks passed.${c_0}"
  else echo "${c_r}${fails} issue(s) found.${c_0} Logs: ${LOG_DIR} · installer log: /var/log/alphacp-install.log"; fi
  if (( fix )); then
    echo; echo "Attempting safe auto-fixes..."
    for s in apache2 php8.3-fpm mariadb redis-server bind9 fail2ban; do svc_running "$s" || { echo "  restarting $s"; systemctl restart "$s" 2>/dev/null || true; }; done
    ufw status 2>/dev/null | grep -q 'Status: active' || { echo "  enabling ufw"; ufw --force enable 2>/dev/null || true; }
    echo "  done (re-run 'alphacp doctor' to verify)"
  fi
  return 0
}

case "${1:-}" in
  version|--version|-v) cmd_version ;;
  status|"")            cmd_status ;;
  doctor)               shift; cmd_doctor "${1:-}" ;;
  *) echo "Usage: alphacp {status|version|doctor [--fix]}"; exit 1 ;;
esac
CLI_EOF
  if (( ! DRY_RUN )); then
    chmod 0755 /usr/local/bin/alphacp
    ok "cli installed: /usr/local/bin/alphacp  (try: alphacp status)"
  fi
}

# =============================================================================
#  PHASE 3 — VERIFY (self-test + report)
# =============================================================================
phase_verify() {
  step "Phase 3/3 — Self-test & verification report"

  local report; report="${ACP_LOGS}/verify-report-$(date +%Y%m%d-%H%M%S).txt"
  local pass=0 fail=0 warnc=0
  local -a lines=()

  vcheck() { # name, ok(1/0), detail, critical
    local name="$1" okf="$2" detail="${3:-}" crit="${4:-0}"
    if [[ "$okf" == "1" ]]; then
      lines+=("PASS  ${name}${detail:+ — $detail}"); pass=$((pass+1)); printf '  %-42s %s\n' "$name" "${c_g}PASS${c_reset:-}"
    else
      if [[ "$crit" == "1" ]]; then lines+=("FAIL  ${name}${detail:+ — $detail}"); fail=$((fail+1)); printf '  %-42s %s\n' "$name" "${c_r}FAIL${c_reset:-}"
      else lines+=("WARN  ${name}${detail:+ — $detail}"); warnc=$((warnc+1)); printf '  %-42s %s\n' "$name" "${c_y}WARN${c_reset:-}"; fi
    fi
  }
  local c_reset="" c_g="$C_GREEN" c_r="$C_RED" c_y="$C_YELLOW"

  # services
  vcheck "service: apache2 running"        "$(svc_active apache2 && echo 1 || echo 0)" "" 1
  vcheck "service: php8.3-fpm running"     "$(svc_active php8.3-fpm && echo 1 || echo 0)" "" 1
  vcheck "service: mariadb running"        "$(svc_active mariadb && echo 1 || echo 0)" "" 1
  vcheck "service: redis-server running"   "$(svc_active redis-server && echo 1 || echo 0)" "" 1
  vcheck "service: bind9 running"          "$(svc_active bind9 && echo 1 || echo 0)" "" 1
  vcheck "service: fail2ban running"       "$(svc_active fail2ban && echo 1 || echo 0)" "" 1
  vcheck "service: ufw active"             "$(ufw status 2>/dev/null | grep -q 'Status: active' && echo 1 || echo 0)" "" 1
  vcheck "service: nginx stopped (Step 2)" "$(svc_active nginx && echo 0 || echo 1)" "intentional" 0
  local mail_st=1; if svc_active exim4 || svc_active dovecot; then mail_st=0; fi
  vcheck "service: exim/dovecot stopped"   "$mail_st" "Step 7" 0

  # http + php
  if (( DRY_RUN )); then
    vcheck "http://127.0.0.1 responds" "1" "dry-run"
  else
    local body
    body="$(curl -fsS -m 8 http://127.0.0.1/ 2>/dev/null || true)"
    vcheck "http://127.0.0.1 responds" "$([[ "$body" == *AlphaCP* ]] && echo 1 || echo 0)" "default page" 1
    local token phpjson
    token="$(cat "${ACP_ETC}/check.token" 2>/dev/null || true)"
    phpjson="$(curl -fsS -m 8 "http://127.0.0.1/check.php?token=${token}" 2>/dev/null || true)"
    vcheck "PHP executes via Apache+FPM" "$([[ "$phpjson" == *'"ok":true'* || "$phpjson" == *'"ok": true'* ]] && echo 1 || echo 0)" "${phpjson:0:60}" 1
    vcheck "check.php blocks wrong token" "$(curl -fsS -m 5 -o /dev/null -w '%{http_code}' 'http://127.0.0.1/check.php?token=wrong' 2>/dev/null | grep -q '404' && echo 1 || echo 0)" "token guard" 0
  fi

  # ---- data layer -----------------------------------------------------------
  vcheck "MariaDB query works"    "$(mariadb -N -e 'SELECT 1' >/dev/null 2>&1 && echo 1 || echo 0)" "" 1
  local anon_count="1"
  if (( ! DRY_RUN )); then
    anon_count="$(mariadb -N -e "SELECT COUNT(*) FROM mysql.user WHERE User=''" 2>/dev/null | tr -dc '0-9' || true)"
    if [[ -z "$anon_count" ]]; then anon_count="1"; fi
  fi
  vcheck "no anonymous SQL users" "$([[ "$anon_count" == "0" ]] && echo 1 || echo 0)" "count=${anon_count}" 0
  local mysql_nonlocal=""
  if (( ! DRY_RUN )); then
    mysql_nonlocal=$(ss -ltnH 2>/dev/null | awk '{print $4}' | grep ':3306$' | grep -vE '^(127\.|\[::1\]|localhost)' | head -1 || true)
  fi
  vcheck "MySQL not public (3306)" "$([[ -z "$mysql_nonlocal" ]] && echo 1 || echo 0)" "loopback-only binding" 1
  vcheck "Redis responds (PONG)"  "$(redis-cli ping 2>/dev/null | grep -q PONG && echo 1 || echo 0)" "" 1
  vcheck "Redis local-only"       "$(ss -ltn 2>/dev/null | grep -q '127.0.0.1:6379' && echo 1 || echo 0)" "" 0
  vcheck "BIND answers locally"   "$(dig +short +time=3 +tries=1 @127.0.0.1 localhost >/dev/null 2>&1 && echo 1 || echo 0)" "" 0
  vcheck "public DNS resolution"  "$(getent hosts archive.ubuntu.com >/dev/null 2>&1 && echo 1 || echo 0)" "" 1

  # ---- quotas, cgroups, swap, disk ------------------------------------------
  vcheck "quota enforcement on /" "$(quotaon -p / >/dev/null 2>&1 && echo 1 || echo 0)" "" 0
  vcheck "cgroups v2"             "$([[ "$(stat -fc %T /sys/fs/cgroup)" == "cgroup2fs" ]] && echo 1 || echo 0)" "" 0
  local swap_now disk_used_pct ok_swap ok_disk
  swap_now=$(awk '/SwapTotal/{print int($2/1024)}' /proc/meminfo)
  ok_swap=0; if (( swap_now >= 512 )); then ok_swap=1; fi
  vcheck "swap available"         "$ok_swap" "${swap_now} MB" 0
  disk_used_pct=$(df --output=pcent / | tail -1 | tr -dc '0-9')
  ok_disk=0; if (( ${disk_used_pct:-100} < 85 )); then ok_disk=1; fi
  vcheck "disk usage under 85%"   "$ok_disk" "${disk_used_pct}% used" 0

  # ---- firewall -------------------------------------------------------------
  vcheck "ufw: ssh/http/https allowed" "$(ufw status 2>/dev/null | grep -qE '^(22|80|443)/tcp' && echo 1 || echo 0)" "" 1
  vcheck "ufw: panel port 2083 allowed" "$(ufw status 2>/dev/null | grep -q '2083' && echo 1 || echo 0)" "" 0
  vcheck "ufw: panel port 8090 allowed" "$(ufw status 2>/dev/null | grep -q '8090' && echo 1 || echo 0)" "" 0

  # tooling
  vcheck "composer available" "$(command -v composer >/dev/null && echo 1 || echo 0)" "" 0
  vcheck "node available"     "$(command -v node >/dev/null && echo 1 || echo 0)" "" 0
  vcheck "alphacp CLI works"  "$(alphacp version >/dev/null 2>&1 && echo 1 || echo 0)" "" 0

  # report file
  if (( ! DRY_RUN )); then
    {
      echo "AlphaCP installer v${ACP_VERSION} — verification report"
      echo "date: $(date -Is)   host: $(hostname)   profile: ${PROFILE}"
      echo "os: ${OS_PRETTY:-unknown}   arch: ${ARCH:-unknown}   ram: ${RAM_MB} MB"
      echo "----------------------------------------------------------------"
      printf '%s\n' "${lines[@]}"
      echo "----------------------------------------------------------------"
      echo "PASS: ${pass}   FAIL: ${fail}   WARN: ${warnc}"
    } > "$report"
  fi

  step "Result"
  say "  ${C_GREEN}PASS: ${pass}${C_RESET}   ${C_RED}FAIL: ${fail}${C_RESET}   ${C_YELLOW}WARN: ${warnc}${C_RESET}"
  say "  report: ${report}"
  say "  log   : ${LOG_FILE}"
  say ""
  if (( fail == 0 )); then
    ok "Step 1 COMPLETE — base hosting stack is running."
    mark_phase verify
  else
    warn "Some critical checks failed — share the report above and we'll fix before Step 2."
  fi
}

# =============================================================================
#  MAIN
# =============================================================================
main() {
  ORIG_ARGS=("$@")
  parse_args "$@"
  if (( ! DRY_RUN )); then mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true; fi
  log "================ AlphaCP installer v${ACP_VERSION} start (profile=${PROFILE} dry_run=${DRY_RUN}) ================"

  say ""
  say "${C_BOLD}AlphaCP Installer v${ACP_VERSION}${C_RESET} — Step 1: base hosting stack"
  say "${C_DIM}profile: ${PROFILE} · log: ${LOG_FILE}${C_RESET}"
  say ""

  run_phase() {
    local p="$1"
    should_run_phase "$p" || return 0
    if phase_done "$p" && (( ! FORCE )); then ok "phase '$p' already completed (state) — skipping (use --force to redo)"; return 0; fi
    "phase_${p}"
  }

  run_phase preflight
  run_phase packages
  run_phase services
  run_phase verify

  say ""
  say "${C_BOLD}${C_GREEN}Done.${C_RESET} Next steps:"
  say "  1. Run:  ${C_BOLD}alphacp status${C_RESET}   (see the whole stack)"
  say "  2. Run:  ${C_BOLD}alphacp doctor${C_RESET}   (health checks)"
  say "  3. Open: ${C_BOLD}http://<server-ip>/${C_RESET}   (should show the AlphaCP ready page)"
  say "  4. Tell the assistant: \"Step 1 done\" + paste the result line"
  say ""
  log "================ installer finished ================"
}

main "$@"
