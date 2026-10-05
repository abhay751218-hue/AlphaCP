#!/usr/bin/env bash
# =============================================================================
# AlphaCP S7 #18 — LIVE verification for static Exim mailing-list fan-out.
# Run as root after deploying the matching panel/agent release. The temporary
# account, list, and alias are removed on exit; a report is written under ACP_HOME.
#
# This proves the list task, generated Exim alias, actual route, and real delivery
# to a subscriber Maildir. It does not test Mailman moderation/archives.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
TEST_USER="${ACP_VERIFY_MAIL_USER:-acpmailchk}"
TEST_DOMAIN="${ACP_VERIFY_LIST_DOMAIN:-acp-list-check.test}"
TEST_LOCAL="${ACP_VERIFY_LIST_LOCAL:-announce}"
TEST_ADDR="info@${TEST_DOMAIN}"
LIST_ADDR="${TEST_LOCAL}@${TEST_DOMAIN}"
REPORT_DIR="${ACP_HOME}/verify-reports"
REPORT_FILE="${REPORT_DIR}/s7-mailing-list-check.txt"
EXIM_ALIASES="${ACP_MAIL_EXIM_ALIASES:-/etc/exim4/alphacp-aliases}"
PASS=0; FAIL=0; CREATED_ACCOUNT=0; LIST_DELIVERY=NO; DONE=0
TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; TASK_OUT=""
RUNNER=()

ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0"; exit 1
fi
[[ -x "${PANELD}" ]] || { echo "paneld not found: ${PANELD}"; exit 1; }
[[ -f "${ACP_HOME}/agent/config/tasks.php" ]] || { echo "agent task registry missing"; exit 1; }
grep -q "'mail[.]list'" "${ACP_HOME}/agent/config/tasks.php" || {
  echo "mail.list is not registered in the installed agent"; exit 1;
}

find_bin() {
  local tool="$1" envvar="$2" preferred="$3" envval="" found="" d
  envval="$(printenv "$envvar" 2>/dev/null || true)"
  if [[ -n "$envval" ]]; then printf '%s' "$envval"; return 0; fi
  if [[ -n "$preferred" && -x "$preferred" ]]; then printf '%s' "$preferred"; return 0; fi
  found="$(command -v "$tool" 2>/dev/null || true)"
  if [[ -n "$found" && -x "$found" ]]; then printf '%s' "$found"; return 0; fi
  for d in /usr/sbin /usr/bin /sbin /bin /usr/local/sbin /usr/local/bin; do
    if [[ -x "${d}/${tool}" ]]; then printf '%s' "${d}/${tool}"; return 0; fi
  done
  printf ''
}

EXIM="$(find_bin exim4 ACP_VERIFY_EXIM /usr/sbin/exim4)"
DOVEADM="$(find_bin doveadm ACP_VERIFY_DOVEADM /usr/bin/doveadm)"
if [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]]; then
  RUNNER=("$PHP_BIN" "$PANELD")
else
  RUNNER=("$PANELD")
fi

run_task() {
  local type="$1" payload="$2" out rc
  out="$("${RUNNER[@]}" --run "$type" "$payload" 2>&1)"
  rc=$?
  TASK_OUT="$out"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"$out" | grep -o '[0-9]*' | head -1)"
  [[ -n "$LAST_TASK_ID" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if [[ "$rc" == "0" ]] && grep -q '"status": "success"' <<<"$out"; then
    LAST_ERR=""
    return 0
  fi
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"$out" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -n "$LAST_ERR" ]] || LAST_ERR="$(tail -1 <<<"$out")"
  return 1
}

cleanup() {
  if [[ "${CREATED_ACCOUNT}" == "1" && ${#RUNNER[@]} -gt 0 ]]; then
    "${RUNNER[@]}" --run account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}" >/dev/null 2>&1 || true
    CREATED_ACCOUNT=0
  fi
  if [[ ${#RUNNER[@]} -gt 0 ]] && [[ -x "${PANELD}" ]]; then
    "${RUNNER[@]}" --run mail.server '{"action":"sync"}' >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

count_marker() {
  local dir="$1" marker="$2"
  [[ -d "$dir" ]] || { printf '0'; return; }
  find "$dir" -mindepth 1 -maxdepth 1 -type f -exec grep -lF -- "$marker" {} + 2>/dev/null | wc -l | tr -d ' '
}

write_report() {
  local tmp="${REPORT_FILE}.tmp.$$"
  mkdir -p "$REPORT_DIR" 2>/dev/null || return 1
  {
    printf '=== S7 MAILING LIST LIVE CHECK (%s) ===\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'pass=%s fail=%s\n' "$PASS" "$FAIL"
    printf 'list_address=%s\nsubscriber=%s\n' "$LIST_ADDR" "$TEST_ADDR"
    printf 'list_delivery=%s\n' "$LIST_DELIVERY"
    printf 'tasks: %s\n' "$TASK_IDS"
    printf 'agent=%s\n' "$(grep -m1 "'mail[.]list'" "${ACP_HOME}/agent/config/tasks.php" 2>/dev/null || true)"
  } > "$tmp" || return 1
  chmod 0644 "$tmp" 2>/dev/null || true
  mv -f "$tmp" "$REPORT_FILE"
}

fatal() {
  bad "$1"
  write_report || info "Could not write report to ${REPORT_FILE}"
  DONE=1
  printf '\n=== S7 #18 MAILING LIST LIVE CHECK: %s pass, %s fail ===\n' "$PASS" "$FAIL"
  info "list delivery: ${LIST_DELIVERY}"
  info "tasks: ${TASK_IDS}"
  info "report: ${REPORT_FILE}"
  exit 1
}

echo "=== S7 #18 MAILING LIST LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "list     : ${LIST_ADDR}"
info "member   : ${TEST_ADDR}"

[[ -n "$EXIM" && -x "$EXIM" ]] || fatal "Exim binary missing"
[[ -n "$DOVEADM" && -x "$DOVEADM" ]] || fatal "doveadm binary missing"
[[ -f "${ACP_HOME}/etc/mail-server-configured" ]] || fatal "mail.server is not configured; run the S7 mail setup first"
[[ "$TEST_USER" =~ ^[a-z][a-z0-9]{2,15}$ ]] || fatal "invalid test account name"
[[ "$TEST_DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fatal "invalid test domain"
[[ "$TEST_LOCAL" =~ ^[a-z0-9][a-z0-9._-]{0,30}[a-z0-9]$ ]] || fatal "invalid test list name"

HASH="${ACP_MAIL_TEST_HASH:-}"
if [[ -z "$HASH" && -n "$PHP_BIN" && -x "$PHP_BIN" ]]; then
  HASH="$("$PHP_BIN" -r 'echo password_hash("AcpMailListTest123", PASSWORD_BCRYPT);' 2>/dev/null)"
fi
[[ "$HASH" =~ ^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9./]{53}$ ]] || fatal "could not create a bcrypt test mailbox hash"
SHADOW="$(openssl passwd -6 'AcpMailListTest123' 2>/dev/null || echo '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv')"

if run_task account.create "{\"username\":\"${TEST_USER}\",\"domain\":\"${TEST_DOMAIN}\",\"shadow_hash\":\"${SHADOW}\",\"quota_mb\":256,\"php_version\":\"8.4\"}"; then
  CREATED_ACCOUNT=1
  ok "temporary account created (task #${LAST_TASK_ID})"
else
  fatal "could not create temporary account: ${LAST_ERR:-unknown}; no existing account was changed"
fi

if run_task mail.set "{\"username\":\"${TEST_USER}\",\"mailboxes\":[{\"local\":\"info\",\"domain\":\"${TEST_DOMAIN}\",\"hash\":\"${HASH}\",\"quota_mb\":100}]}"; then
  ok "subscriber mailbox created (task #${LAST_TASK_ID})"
else
  fatal "mail.set failed: ${LAST_ERR:-unknown}"
fi

LIST_PAYLOAD="{\"username\":\"${TEST_USER}\",\"lists\":[{\"local\":\"${TEST_LOCAL}\",\"domain\":\"${TEST_DOMAIN}\",\"owner\":\"list-owner@example.net\",\"members\":[\"${TEST_ADDR}\"]}]}"
if run_task mail.list "$LIST_PAYLOAD"; then
  ok "mail.list saved one list and one subscriber (task #${LAST_TASK_ID})"
else
  fatal "mail.list failed: ${LAST_ERR:-unknown}"
fi

if run_task mail.server '{"action":"sync"}'; then
  if grep -q '"lists": *1' <<<"$TASK_OUT" && grep -q '"list_errors": *0' <<<"$TASK_OUT"; then
    ok "mail.server sync published exactly one list with zero list errors"
  else
    fatal "sync response did not confirm one clean list: $(grep -E '"lists"|"list_errors"' <<<"$TASK_OUT" | tr '\n' ' ')"
  fi
else
  fatal "mail.server sync failed: ${LAST_ERR:-unknown}"
fi

if grep -Fqx "${LIST_ADDR}: ${TEST_ADDR}" "$EXIM_ALIASES" 2>/dev/null; then
  ok "Exim alias map expands ${LIST_ADDR} to ${TEST_ADDR}"
else
  fatal "generated alias map entry missing: ${LIST_ADDR} -> ${TEST_ADDR}"
fi

ROUTE="$("$EXIM" -bt "$LIST_ADDR" 2>&1)"
ROUTE_RC=$?
if [[ "$ROUTE_RC" == "0" && "$ROUTE" == *"${TEST_ADDR}"* && "$ROUTE" == *"alphacp_aliases"* ]]; then
  ok "Exim -bt routed the list through alphacp_aliases to its subscriber"
else
  fatal "Exim -bt did not confirm list routing (rc=${ROUTE_RC}): $(tr '\n' ' ' <<<"$ROUTE")"
fi

DOVE_HOME="$("$DOVEADM" user "$TEST_ADDR" 2>/dev/null | awk '$1 == "home" {print $2; exit}')"
if [[ "$DOVE_HOME" == */mail/${TEST_DOMAIN}/info ]]; then
  MAILDIR="$DOVE_HOME"
else
  MAILDIR="${DOVE_HOME:-/home/${TEST_USER}}/mail/${TEST_DOMAIN}/info"
fi
[[ -d "${MAILDIR}/new" ]] || fatal "subscriber Maildir is missing: ${MAILDIR}/new"
MARKER="ACP-LIST-$(date +%s)-${RANDOM}"
BEFORE="$(count_marker "${MAILDIR}/new" "$MARKER")"
SENDER="root@$(hostname -f 2>/dev/null || hostname)"
MESSAGE="$(printf 'From: %s\nTo: %s\nSubject: %s\n\nAlphaCP list fan-out verification %s\n' "$SENDER" "$LIST_ADDR" "$MARKER" "$MARKER")"
EXIM_OUT="$(printf '%s\n' "$MESSAGE" | "$EXIM" -odf -oem -v -f "$SENDER" "$LIST_ADDR" 2>&1)"
EXIM_RC=$?
AFTER=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
  AFTER="$(count_marker "${MAILDIR}/new" "$MARKER")"
  [[ "$AFTER" -gt 0 ]] && break
  sleep 1
done
if [[ "$EXIM_RC" == "0" && "$AFTER" -gt "$BEFORE" ]]; then
  LIST_DELIVERY=YES
  ok "real Exim list delivery reached subscriber Maildir (${BEFORE} -> ${AFTER})"
else
  fatal "list delivery not verified (exim rc=${EXIM_RC}, marker ${BEFORE} -> ${AFTER}): $(tr '\n' ' ' <<<"$EXIM_OUT")"
fi

PIPE_PAYLOAD="{\"username\":\"${TEST_USER}\",\"lists\":[{\"local\":\"${TEST_LOCAL}\",\"domain\":\"${TEST_DOMAIN}\",\"owner\":\"list-owner@example.net\",\"members\":[\"|/bin/sh\"]}]}"
if run_task mail.list "$PIPE_PAYLOAD"; then
  bad "unsafe pipe subscriber was accepted"
else
  ok "unsafe pipe subscriber was rejected"
fi

SELF_PAYLOAD="{\"username\":\"${TEST_USER}\",\"lists\":[{\"local\":\"${TEST_LOCAL}\",\"domain\":\"${TEST_DOMAIN}\",\"owner\":\"list-owner@example.net\",\"members\":[\"${LIST_ADDR}\"]}]}"
if run_task mail.list "$SELF_PAYLOAD"; then
  bad "self-subscribed list was accepted"
else
  ok "self-subscribed list was rejected"
fi

if grep -Fqx "${LIST_ADDR}: ${TEST_ADDR}" "$EXIM_ALIASES" 2>/dev/null; then
  ok "rejected edits did not change the active subscriber route"
else
  bad "active subscriber route disappeared after rejected edits"
fi

if run_task mail.list "{\"username\":\"${TEST_USER}\",\"lists\":[]}"; then
  ok "mail.list removed the temporary list"
else
  bad "mail.list cleanup failed: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"sync"}'; then
  if grep -Fq "${LIST_ADDR}:" "$EXIM_ALIASES" 2>/dev/null; then
    bad "list alias remains in Exim map after removal"
  else
    ok "sync removed the list alias from Exim"
  fi
else
  bad "final mail.server sync failed: ${LAST_ERR:-unknown}"
fi

if run_task account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}"; then
  CREATED_ACCOUNT=0
  ok "temporary account removed (task #${LAST_TASK_ID})"
else
  bad "temporary account removal failed: ${LAST_ERR:-unknown}; exit cleanup will retry"
fi
if run_task mail.server '{"action":"sync"}'; then
  ok "final mail sync completed"
else
  bad "final mail sync failed: ${LAST_ERR:-unknown}"
fi

DONE=1
if ! write_report; then
  bad "could not write report to ${REPORT_FILE}"
fi
printf '\n=== S7 #18 MAILING LIST LIVE CHECK: %s pass, %s fail ===\n' "$PASS" "$FAIL"
info "list delivery: ${LIST_DELIVERY}"
info "tasks: ${TASK_IDS}"
info "report: ${REPORT_FILE}"

[[ "$FAIL" == "0" ]]
