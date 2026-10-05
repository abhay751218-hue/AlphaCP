#!/usr/bin/env bash
# =============================================================================
# AlphaCP S7 — read-only live verification for SpamAssassin + greylistd (#147).
# Does NOT enable/disable either feature. Writes diagnostics to verify-reports/
# so alphacp-sync can carry them back to the repo.
#
#   sudo bash tools/verify/s7-spam-check.sh
# =============================================================================
set -uo pipefail
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
EXIM="${ACP_VERIFY_EXIM:-/usr/sbin/exim4}"
SPAMD="${ACP_VERIFY_SPAMD:-/usr/sbin/spamd}"
GREYLISTD="${ACP_VERIFY_GREYLISTD:-/usr/sbin/greylistd}"
EXIM_TEMPLATE="${ACP_MAIL_EXIM_TEMPLATE:-/etc/exim4/exim4.conf.template}"
EXIM_OPTIONS="${ACP_MAIL_EXIM_OPTIONS:-${ACP_HOME}/etc/mail/exim-options.json}"
GREYLISTD_SOCKET="${ACP_MAIL_GREYLISTD_SOCKET:-/var/run/greylistd/socket}"
REPORT_DIR="${ACP_HOME}/verify-reports"
REPORT="${REPORT_DIR}/s7-spam-check.txt"
PASS=0; FAIL=0; SKIP=0; TASK_OUT=""; LAST_ERR=""

ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
skip() { SKIP=$((SKIP+1)); printf '  skip %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "root ke saath chalao: sudo bash $0"; exit 1
fi
[[ -x "$PANELD" ]] || { echo "paneld nahi mila: $PANELD"; exit 1; }
mkdir -p "$REPORT_DIR" 2>/dev/null || true
: > "$REPORT" 2>/dev/null || true
report() { printf '%s\n' "$1" | tee -a "$REPORT"; }
run_task() {
  local payload="$1" out
  if [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]]; then
    out="$("$PHP_BIN" "$PANELD" --run mail.server "$payload" 2>&1)"
  else
    out="$("$PANELD" --run mail.server "$payload" 2>&1)"
  fi
  TASK_OUT="$out"
  if grep -q '"status"[[:space:]]*:[[:space:]]*"success"' <<<"$out"; then
    LAST_ERR=""
    return 0
  fi
  LAST_ERR="$(grep -o '"error"[[:space:]]*:[[:space:]]*"[^"]*"' <<<"$out" | head -1 | sed 's/.*:[[:space:]]*"//; s/"$//')"
  [[ -n "$LAST_ERR" ]] || LAST_ERR="$(tail -1 <<<"$out")"
  return 1
}

report "S7 #147 live check — $(date -u +%Y-%m-%dT%H:%M:%SZ) host=$(hostname -f 2>/dev/null || hostname)"
echo "=== S7 SPAMASSASSIN + GREYLISTD LIVE CHECK (read-only) ==="
info "ACP_HOME: $ACP_HOME"
info "No feature toggle is changed by this script."

if [[ -f "${ACP_HOME}/etc/mail-server-configured" ]]; then
  ok "mail.server configured marker exists"
else
  bad "mail.server configured marker missing — run mail.server setup first"
fi

if [[ -x "$EXIM" ]] && "$EXIM" -bV >"/tmp/acp-s7-spam-bv.$$" 2>&1; then
  if grep -q 'Content_Scanning' "/tmp/acp-s7-spam-bv.$$"; then
    ok "Exim supports Content_Scanning (daemon-heavy)"
  else
    bad "Exim lacks Content_Scanning; SpamAssassin ACL cannot run"
  fi
else
  bad "Exim -bV failed: $(head -3 "/tmp/acp-s7-spam-bv.$$" 2>/dev/null | tr '\n' ' ')"
fi
rm -f "/tmp/acp-s7-spam-bv.$$"

if [[ -x "$SPAMD" ]]; then
  ok "spamd binary installed"
else
  bad "spamd binary missing (spamassassin package)"
fi
if [[ -x "$GREYLISTD" ]]; then
  ok "greylistd binary installed"
else
  bad "greylistd binary missing"
fi

if run_task '{"action":"spamassassin"}'; then
  ok "mail.server spamassassin status action succeeded"
  printf '%s\n' "$TASK_OUT" | tee -a "$REPORT"
else
  bad "mail.server spamassassin status failed: $LAST_ERR"
  printf '%s\n' "$TASK_OUT" | tee -a "$REPORT"
fi

SPAM_ENABLED=0; SPAM_ACTIVE=0; GREY_ENABLED=0; GREY_ACTIVE=0
if grep -q '"enabled"[[:space:]]*:[[:space:]]*true' <<<"$TASK_OUT"; then SPAM_ENABLED=1; fi
if grep -q '"active"[[:space:]]*:[[:space:]]*true' <<<"$TASK_OUT"; then SPAM_ACTIVE=1; fi
if grep -q '"greylisting"[[:space:]]*:[[:space:]]*true' <<<"$TASK_OUT"; then GREY_ENABLED=1; fi
if grep -q '"greylisting_active"[[:space:]]*:[[:space:]]*true' <<<"$TASK_OUT"; then GREY_ACTIVE=1; fi

if [[ "$SPAM_ENABLED" == "1" ]]; then
  if [[ "$SPAM_ACTIVE" == "1" ]] && grep -q 'warn spam = nobody:true/defer_ok' "$EXIM_TEMPLATE" 2>/dev/null; then
    ok "SpamAssassin enabled: daemon, Exim capability, and fail-open ACL are active"
  else
    bad "SpamAssassin enabled in options but daemon/ACL is not active"
  fi
else
  if grep -q 'warn spam = nobody:true/defer_ok' "$EXIM_TEMPLATE" 2>/dev/null; then
    bad "SpamAssassin ACL is present although spam_enabled is off"
  else
    ok "SpamAssassin is safely off by default; no scoring ACL in Exim"
  fi
fi

if [[ "$GREY_ENABLED" == "1" ]]; then
  if [[ "$GREY_ACTIVE" == "1" ]] && [[ -S "$GREYLISTD_SOCKET" ]] \
     && grep -q -- '--grey \$sender_host_address \$sender_address \$local_part@\$domain' "$EXIM_TEMPLATE" 2>/dev/null; then
    ok "greylisting enabled: greylistd socket + Exim --grey ACL are active"
  else
    bad "greylisting enabled in options but daemon/socket/ACL is not active"
  fi
else
  if grep -q 'condition = .*readsocket.*--grey' "$EXIM_TEMPLATE" 2>/dev/null; then
    bad "greylist ACL is present although greylisting is off"
  else
    ok "greylisting safely off by default; no RCPT defer ACL in Exim"
  fi
fi

if command -v ss >/dev/null 2>&1; then
  if ss -lnt 2>/dev/null | awk 'NR>1 && $4 ~ /:783$/ && $4 !~ /^127\.0\.0\.1:783$/ && $4 !~ /^\[::1\]:783$/ && $4 !~ /^::1:783$/ { public=1 } END { exit !public }'; then
    bad "spamd port 783 listens beyond loopback (public exposure)"
  else
    ok "spamd has no public TCP/783 listener (loopback-only or stopped)"
  fi
else
  skip "ss not installed; spamd listen address not checked"
fi

report "RESULT: ${PASS} pass, ${FAIL} fail, ${SKIP} skip"
echo "=== S7 SPAMASSASSIN + GREYLISTD LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
if [[ "$FAIL" -eq 0 ]]; then
  echo 'S7 SPAMASSASSIN + GREYLIST:STATUS-VERIFIED'
  exit 0
fi
echo 'S7 SPAMASSASSIN + GREYLIST:NOT-VERIFIED'
exit 1
