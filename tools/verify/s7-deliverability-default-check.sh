#!/usr/bin/env bash
# =============================================================================
# AlphaCP S7 #145 — LIVE verification: Email Deliverability ka SERVER DEFAULT.
#
# cPanel me "Email Deliverability" per-domain hota hai; #145 server-level default
# hai: server KHUD (bina customer ke kisi click ke) har mail domain ke liye
#   1) SPF   TXT   (@)                 — v=spf1 …
#   2) DMARC TXT   (_dmarc)            — v=DMARC1; p=…
#   3) DKIM  TXT   (default._domainkey) + asli RSA key pair (private 0600/0640)
#   4) Exim me DKIM **signing** enabled (dkim_selector + dkim_private_key)
# likhta hai — aur naya domain/account aate hi apne aap milta hai.
#
# Ye verifier: naya temporary account + 1 mailbox banata hai, phir SIRF
# `mail.server sync` chalata hai (koi `mail.deliverability` manual action NAHI),
# aur us naye domain par upar ke chaar cheezein assert karta hai. Aakhir me
# account khud hata deta hai; report ACP_HOME/verify-reports me likhi jati hai.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
TEST_USER="${ACP_VERIFY_DELIV_USER:-acpdelivchk}"
TEST_DOMAIN="${ACP_VERIFY_DELIV_DOMAIN:-acp-deliverability-check.test}"
TEST_LOCAL="${ACP_VERIFY_DELIV_LOCAL:-postmaster}"
DKIM_DIR="${ACP_MAIL_DKIM_DIR:-${ACP_HOME}/etc/mail/dkim}"
EXIM_TEMPLATE="${ACP_MAIL_EXIM_TEMPLATE:-/etc/exim4/exim4.conf.template}"
REPORT_DIR="${ACP_HOME}/verify-reports"
REPORT_FILE="${REPORT_DIR}/s7-deliverability-default-check.txt"
PASS=0; FAIL=0; CREATED_ACCOUNT=0; DONE=0
TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; TASK_OUT=""
RUNNER=(); PHP_CMD=()
WORK="$(mktemp -d /tmp/acp-deliv-check.XXXXXX)"

ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
skip() { printf '  skip %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0"; exit 1
fi
[[ -x "$PANELD" ]] || { echo "paneld not found: ${PANELD}"; exit 1; }

if [[ -n "${ACP_VERIFY_PHP:-}" ]]; then
  read -r -a PHP_CMD <<<"${ACP_VERIFY_PHP}"
else
  for c in php8.4 php8.3 php; do
    if command -v "$c" >/dev/null 2>&1; then PHP_CMD=("$(command -v "$c")"); break; fi
  done
fi

find_bin() {
  local tool="$1" envvar="$2" preferred="$3" envval="" found="" d
  envval="$(printenv "$envvar" 2>/dev/null || true)"
  [[ -n "$envval" ]] && { printf '%s' "$envval"; return 0; }
  [[ -n "$preferred" && -x "$preferred" ]] && { printf '%s' "$preferred"; return 0; }
  found="$(command -v "$tool" 2>/dev/null || true)"
  [[ -n "$found" && -x "$found" ]] && { printf '%s' "$found"; return 0; }
  for d in /usr/sbin /usr/bin /sbin /bin /usr/local/sbin /usr/local/bin; do
    [[ -x "${d}/${tool}" ]] && { printf '%s' "${d}/${tool}"; return 0; }
  done
  printf ''
}
EXIM="$(find_bin exim4 ACP_VERIFY_EXIM /usr/sbin/exim4)"
DOVEADM="$(find_bin doveadm ACP_VERIFY_DOVEADM /usr/bin/doveadm)"

if [[ -x "$PANELD" ]]; then RUNNER=("$PANELD"); else RUNNER=("${PHP_CMD[@]}" "$PANELD"); fi

run_task() {
  local type="$1" payload="$2" out rc
  out="$("${RUNNER[@]}" --run "$type" "$payload" 2>&1)"; rc=$?
  TASK_OUT="$out"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"$out" | grep -o '[0-9]*' | head -1)"
  [[ -n "$LAST_TASK_ID" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if [[ "$rc" == "0" ]] && grep -q '"status": "success"' <<<"$out"; then LAST_ERR=""; return 0; fi
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"$out" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -n "$LAST_ERR" ]] || LAST_ERR="$(tail -1 <<<"$out")"
  return 1
}

cleanup() {
  if [[ "${CREATED_ACCOUNT}" == "1" && ${#RUNNER[@]} -gt 0 ]]; then
    "${RUNNER[@]}" --run account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}" >/dev/null 2>&1 || true
    CREATED_ACCOUNT=0
  fi
  [[ ${#RUNNER[@]} -gt 0 && -x "$PANELD" ]] && "${RUNNER[@]}" --run mail.server '{"action":"sync"}' >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

write_report() {
  local tmp="${REPORT_FILE}.tmp.$$"
  mkdir -p "$REPORT_DIR" 2>/dev/null || return 1
  {
    printf '=== S7 #145 SERVER-DEFAULT DELIVERABILITY LIVE CHECK (%s) ===\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'pass=%s fail=%s\n' "$PASS" "$FAIL"
    printf 'account=%s domain=%s mailbox=%s@%s\n' "$TEST_USER" "$TEST_DOMAIN" "$TEST_LOCAL" "$TEST_DOMAIN"
    printf 'dkim_dir=%s\nexim_template=%s\n' "$DKIM_DIR" "$EXIM_TEMPLATE"
    printf 'tasks: %s\n' "$TASK_IDS"
  } > "$tmp" || return 1
  chmod 0644 "$tmp" 2>/dev/null || true
  mv -f "$tmp" "$REPORT_FILE"
}

fatal() {
  bad "$1"
  write_report || info "Could not write report to ${REPORT_FILE}"
  DONE=1
  printf '\n=== S7 #145 DELIVERABILITY (SERVER DEFAULT): %s pass, %s fail ===\n' "$PASS" "$FAIL"
  info "tasks: ${TASK_IDS}"
  info "report: ${REPORT_FILE}"
  exit 1
}

echo "=== S7 #145 SERVER-DEFAULT DELIVERABILITY LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "account  : ${TEST_USER} (domain ${TEST_DOMAIN})"
info "dkim dir : ${DKIM_DIR}"

[[ -f "${ACP_HOME}/agent/config/tasks.php" ]] || fatal "agent task registry missing"
[[ -f "${ACP_HOME}/etc/mail-server-configured" ]] || fatal "mail.server is not configured; run the S7 mail setup first"

# 0) pre-state: is domain ka koi DKIM key pehle se? (naya account = default proof)
if [[ -f "${DKIM_DIR}/${TEST_DOMAIN}.key" ]]; then
  info "purana key mila — pehle hata dete hain taaki server default se naya ban sake"
  rm -f "${DKIM_DIR}/${TEST_DOMAIN}.key" "${DKIM_DIR}/${TEST_DOMAIN}.pub" 2>/dev/null || true
fi

SHADOW="$(openssl passwd -6 'AcpDelivCheck123' 2>/dev/null || echo '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv')"
if run_task account.create "{\"username\":\"${TEST_USER}\",\"domain\":\"${TEST_DOMAIN}\",\"shadow_hash\":\"${SHADOW}\",\"quota_mb\":256,\"php_version\":\"8.4\"}"; then
  CREATED_ACCOUNT=1
  ok "naya temporary account banaya (task #${LAST_TASK_ID})"
else
  fatal "could not create temporary account: ${LAST_ERR:-unknown}; no existing account was changed"
fi

HASH=""
if [[ ${#PHP_CMD[@]} -gt 0 ]]; then
  HASH="$("${PHP_CMD[@]}" -r 'echo password_hash("AcpDelivCheck123", PASSWORD_BCRYPT);' 2>/dev/null)"
fi
[[ "$HASH" =~ ^\$2[ayb]\$ ]] || HASH='$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234'
if run_task mail.set "{\"username\":\"${TEST_USER}\",\"mailboxes\":[{\"local\":\"${TEST_LOCAL}\",\"domain\":\"${TEST_DOMAIN}\",\"hash\":\"${HASH}\",\"quota_mb\":100}]}"; then
  ok "mailbox banaya (task #${LAST_TASK_ID})"
else
  fatal "mail.set failed: ${LAST_ERR:-unknown}"
fi

# 1) SIRF sync — koi manual deliverability action nahi (yahi "server default" hai)
if run_task mail.server '{"action":"sync"}'; then
  ok "mail.server sync chala (task #${LAST_TASK_ID}) — koi manual deliverability action nahi"
else
  fatal "mail.server sync failed: ${LAST_ERR:-unknown}"
fi

# 2) account home dhoondho (doveadm se) aur zone.json padho
ADDR="${TEST_LOCAL}@${TEST_DOMAIN}"
DOVE_HOME="$("$DOVEADM" user "$ADDR" 2>/dev/null | awk '$1 == "home" {print $2; exit}')"
HOME_DIR="${DOVE_HOME%/mail/*}"
if [[ -z "$HOME_DIR" || ! -d "$HOME_DIR" ]]; then
  HOME_DIR="/home/${TEST_USER}"
fi
ZONE="${HOME_DIR}/etc/dns/zone.json"
if [[ -f "$ZONE" ]]; then
  ok "domain ka DNS zone maujood: ${ZONE}"
else
  fatal "DNS zone nahi mili (${ZONE})"
fi

ZONE_JSON="$(python3 - "$ZONE" <<'PY'
import json, sys
try:
    rows = json.load(open(sys.argv[1]))
except Exception as e:
    print("ERR " + str(e)); raise SystemExit(1)
print(json.dumps(rows))
PY
)" || fatal "zone.json parse nahi hui"

ZONE_OUT="$(python3 - "$ZONE_JSON" "$TEST_DOMAIN" <<'PY'
import json, sys
rows, dom = json.loads(sys.argv[1]), sys.argv[2].lower()
def get(name, prefix=None, want=None):
    for r in rows:
        if not isinstance(r, dict): continue
        if str(r.get('name','')).lower().rstrip('.') != name: continue
        if str(r.get('type','')).upper() != 'TXT': continue
        if str(r.get('domain', dom)).lower() != dom: continue
        v = str(r.get('value',''))
        if prefix and not v.lower().startswith(prefix): continue
        if want and want.lower() not in v.lower(): continue
        return v
    return ''
print("SPF=%s" % get('@', prefix='v=spf1'))
print("DMARC=%s" % get('_dmarc', want='v=DMARC1'))
print("DKIM=%s" % get('default._domainkey', want='v=DKIM1'))
PY
)"
SPF_V="$(sed -n 's/^SPF=//p' <<<"$ZONE_OUT")"
DMARC_V="$(sed -n 's/^DMARC=//p' <<<"$ZONE_OUT")"
DKIM_V="$(sed -n 's/^DKIM=//p' <<<"$ZONE_OUT")"

[[ -n "$SPF_V" ]] && ok "server default: SPF record apne aap likha gaya → ${SPF_V}" || bad "SPF record missing (server default kaam nahi kiya)"
[[ -n "$DMARC_V" ]] && ok "server default: DMARC record apne aap likha gaya → ${DMARC_V}" || bad "DMARC record missing"
[[ -n "$DKIM_V" ]] && ok "server default: DKIM TXT apne aap likha gaya → ${DKIM_V:0:70}…" || bad "DKIM TXT record missing"

# 3) DKIM key pair (asli RSA) + zone ka p= pub key se match
KEY_FILE="${DKIM_DIR}/${TEST_DOMAIN}.key"
PUB_FILE="${DKIM_DIR}/${TEST_DOMAIN}.pub"
if [[ -f "$KEY_FILE" ]]; then
  MODE="$(stat -c '%a' "$KEY_FILE" 2>/dev/null || echo '?')"
  if [[ "$MODE" == "600" || "$MODE" == "640" ]]; then
    ok "DKIM private key bani (mode ${MODE}, group $(stat -c '%G' "$KEY_FILE" 2>/dev/null)): ${KEY_FILE}"
  else
    bad "DKIM private key ka mode theek nahi (${MODE}) — 600 ya 640 hona chahiye"
  fi
else
  bad "DKIM private key nahi bani: ${KEY_FILE}"
fi
if [[ -f "$PUB_FILE" ]]; then
  ok "DKIM public key file maujood: ${PUB_FILE}"
else
  bad "DKIM public key file nahi mili: ${PUB_FILE}"
fi
if [[ -n "$DKIM_V" && -f "$PUB_FILE" ]]; then
  PUB_B64="$(tr -d '\n' < "$PUB_FILE" | sed -n 's/.*-----BEGIN PUBLIC KEY-----\(.*\)-----END PUBLIC KEY-----.*/\1/p' | tr -d ' ')"
  PUB_B64="${PUB_B64:-$(tr -d '\n\r ' < "$PUB_FILE")}"
  ZONE_B64="$(sed -n 's/.*p=\([A-Za-z0-9+/=]*\).*/\1/p' <<<"$DKIM_V")"
  if [[ -n "$ZONE_B64" && -n "$PUB_B64" && "$ZONE_B64" == "$PUB_B64" ]]; then
    ok "DKIM TXT ka public key asli key file se match karta hai (${#ZONE_B64} chars)"
  else
    bad "DKIM TXT ka p= pub key se match nahi (zone=${ZONE_B64:0:24}… key=${PUB_B64:0:24}…)"
  fi
fi

# 4) Exim me DKIM signing enabled + config valid
if [[ -f "$EXIM_TEMPLATE" ]]; then
  if grep -q "dkim_selector" "$EXIM_TEMPLATE" && grep -q "dkim_private_key" "$EXIM_TEMPLATE"; then
    SEL="$(grep -m1 "dkim_selector" "$EXIM_TEMPLATE" | tr -d ' ')"
    ok "Exim me DKIM signing enabled (${SEL}) — outbound mail sign hoga"
  else
    bad "Exim template me dkim_selector/dkim_private_key nahi mila (${EXIM_TEMPLATE})"
    # live debugging ke liye: kya exim build me DKIM support hi hai?
    if [[ -n "$EXIM" && -x "$EXIM" ]]; then
      if "$EXIM" -bV 2>/dev/null | grep -qi 'dkim'; then
        info "exim build me DKIM support hai (exim -bV me dikha) — yaani template purana hai; mail.server setup dobara chalao"
      else
        info "exim -bV me DKIM nahi dikha — ye Exim build bina DKIM ka hai (exim4-daemon-heavy chahiye)"
      fi
    fi
  fi
  if grep -q "alphacp_mailbox" "$EXIM_TEMPLATE"; then
    ok "managed Exim template intact (routers maujood)"
  else
    bad "managed Exim template me routers nahi mile — config adhoora"
  fi
else
  bad "Exim template nahi mili: ${EXIM_TEMPLATE}"
fi
if [[ -n "$EXIM" && -x "$EXIM" ]]; then
  if "$EXIM" -bV >/dev/null 2>&1; then
    ok "Exim config valid (exim -bV pass)"
  else
    bad "Exim config invalid (exim -bV fail)"
  fi
fi

# 5) cleanup
if run_task account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}"; then
  CREATED_ACCOUNT=0
  ok "temporary account hataya (task #${LAST_TASK_ID})"
else
  bad "temporary account removal failed: ${LAST_ERR:-unknown}; exit cleanup will retry"
fi
if run_task mail.server '{"action":"sync"}'; then
  ok "final mail sync complete"
else
  bad "final mail sync failed: ${LAST_ERR:-unknown}"
fi

DONE=1
write_report || bad "could not write report to ${REPORT_FILE}"
printf '\n=== S7 #145 DELIVERABILITY (SERVER DEFAULT): %s pass, %s fail ===\n' "$PASS" "$FAIL"
info "tasks: ${TASK_IDS}"
info "report: ${REPORT_FILE}"

[[ "$FAIL" == "0" ]]
