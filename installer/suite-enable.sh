#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — SUITE-ENABLE  v1.0  (live par poora agent test-suite chalane layak)
# -----------------------------------------------------------------------------
#  Live PHP me `pdo_sqlite` nahi hai → agent suite ke TaskLogger tests
#  (`PDO('sqlite::memory:')`) live par nahi chal sakte; installers isliye suite
#  gate par skip-note dete the (baaki gates enforced). User-approved (8 Oct):
#  `php<X.Y>-sqlite3` apt package lagao taaki poora 222-test suite LIVE par bhi
#  authoritative chale.
#
#  Ye script koi panel/agent file nahi chhooti — sirf:
#    1  pdo_sqlite check (loaded ho to install skip — idempotent)
#    2  apt-get install php<X.Y>-sqlite3 (PHP version detect se)
#    3  verify: php -m me pdo_sqlite
#    4  poora agent suite LIVE run (passed>=222 failed=0) — gate
#    5  alphacp-sync
#  Kahin fail = exit 1 (koi rollback zaroori nahi; --rollback apt-remove karta hai).
#
#  Usage: sudo bash suite-enable-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
STAMP="$(date -u +%Y%m%d%H%M%S)"
LOG_FILE="${ACP_HOME}/logs/suite-enable-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }

# php-wasm (CI/sim) `PHP` env ko VERSION maanta hai — binary PHP_BIN me rakho.
unset PHP 2>/dev/null || true

detect_php(){
  local p
  if [[ -f /etc/systemd/system/paneld.service ]]; then
    p="$(sed -nE 's#^ExecStart=([^ ]+).*#\1#p' /etc/systemd/system/paneld.service 2>/dev/null | head -1)"
    [[ -n "$p" && -x "$p" ]] && { printf '%s' "$p"; return; }
  fi
  for c in php8.4 php8.3 php8.2 php; do command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }; done
  printf ''
}
PHP_BIN="$(detect_php)"

has_pdo_sqlite(){ [[ -n "$PHP_BIN" ]] && "$PHP_BIN" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; }

rollback(){
  hdr "ROLLBACK — suite-enable v${VERSION} (php-sqlite3 apt remove)"
  local v
  v="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  if command -v apt-get >/dev/null 2>&1 && [[ -n "$v" ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get remove -y "php${v}-sqlite3" >>"$LOG_FILE" 2>&1 \
      && ok "php${v}-sqlite3 remove hua" || warn "apt remove fail — manual: apt-get remove php${v}-sqlite3"
  else
    warn "apt-get/php nahi mila — manual remove karo"
  fi
}

diagnose(){
  hdr "DIAGNOSE (read-only) — suite-enable v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none}"
  info "pdo_sqlite loaded          : $( has_pdo_sqlite && echo YES || echo NO )"
  info "agent suite file           : $( [[ -f "${AGENT}/tests/run-tests.php" ]] && echo PRESENT || echo MISSING )"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — suite-enable v${VERSION} (pdo_sqlite + live suite gate)"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || die "php binary nahi mila"
  [[ -f "${AGENT}/tests/run-tests.php" ]] || die "agent suite nahi mila: ${AGENT}/tests/run-tests.php"

  hdr "pdo_sqlite check"
  if has_pdo_sqlite; then
    ok "pdo_sqlite pehle se loaded — apt install skip (idempotent)"
  else
    local v
    v="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    [[ -n "$v" ]] || die "php version detect nahi hua"
    command -v apt-get >/dev/null 2>&1 || die "apt-get nahi mila (non-Debian system?)"
    hdr "apt install php${v}-sqlite3"
    apt-get update -y >>"$LOG_FILE" 2>&1 || warn "apt-get update fail (cached lists try karte hain)"
    if DEBIAN_FRONTEND=noninteractive apt-get install -y "php${v}-sqlite3" >>"$LOG_FILE" 2>&1; then
      ok "php${v}-sqlite3 install hua"
    else
      die "apt-get install php${v}-sqlite3 fail — log: ${LOG_FILE}"
    fi
    if have_systemd && [[ -f /etc/systemd/system/paneld.service ]]; then
      systemctl restart paneld >/dev/null 2>&1 || warn "paneld restart fail"
    fi
  fi

  hdr "verify extension"
  has_pdo_sqlite || die "pdo_sqlite abhi bhi load nahi (php -m dekhein)"
  ok "pdo_sqlite loaded ($( "$PHP_BIN" -r 'echo PHP_VERSION;' ))"

  hdr "AGENT SUITE — live full run (ab gate enforce hota hai)"
  local sum n p
  sum="$("$PHP_BIN" "${AGENT}/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1)"
  n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"${sum:-}")"
  p="$(sed -E 's/.*passed: ([0-9]+).*/\1/; s/ .*//' <<<"${sum:-}")"
  info "suite: ${sum:-<summary nahi mila>}"
  if [[ "${n:-9}" != "0" || "${p:-0}" -lt 222 ]]; then
    die "agent suite green nahi (passed=${p:-?} failed=${n:-?})"
  fi
  ok "agent suite GREEN live par (passed=${p} failed=0)"

  hdr "alphacp-sync"
  if command -v alphacp-sync >/dev/null 2>&1; then
    alphacp-sync >>"$LOG_FILE" 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync fail (baad me: sudo alphacp-sync)"
  else
    warn "alphacp-sync nahi mila"
  fi

  hdr "FINAL VERDICT"
  ok "pdo_sqlite live par — ab har installer ka suite gate live par bhi enforce hoga"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}suite-enable v${VERSION} APPLY ho gaya.${C_0} Aage ke installers suite-skip note nahi denge."
}

usage(){ sed -nE 's/^#( |=)(.*)$/\2/p' "$0" | sed -n '1,30p'; }
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
case "${1:-apply}" in
  --diagnose|-d) diagnose ;;
  --rollback|-r) rollback ;;
  --help|-h) usage ;;
  apply|"") apply ;;
  *) die "unknown option: $1 (--diagnose | --rollback | --help)" ;;
esac
