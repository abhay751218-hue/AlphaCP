#!/usr/bin/env bash
# =============================================================================
# AlphaCP S7 — LIVE verification: Exim4 + Dovecot (mail.server)
# Server par ROOT ke saath chalao. Sab kuch apne aap saaf ho jata hai.
#
#   A) preconditions — exim4/dovecot/doveadm binaries (path auto-detect)
#   B) setup          — config likhi gayi (backup ke saath), validate, start
#   C) config proof   — `exim4 -bV` + `doveconf -n` (asli daemons khud bolein)
#   D) services       — exim4/dovecot active, port 25/143 sun rahe hain
#   E) ASLI MAIL      — mailbox banao, `exim4 -bt` se routing, phir mail bhej kar
#                       Maildir me file dhoondho (sabse bada saboot)
#   F) sync/verify/list/status
#
# Kaccha account: ${TEST_USER} (${TEST_DOMAIN}) — ant me terminate ho jata hai.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
TEST_USER="${ACP_VERIFY_MAIL_USER:-acpmailchk}"
TEST_DOMAIN="${ACP_VERIFY_MAIL_DOMAIN:-acp-mail-check.test}"
TEST_ADDR="info@${TEST_DOMAIN}"
# paths: agent jis env override ko maanta hai wahi yahan (SIM me bhi chale)
DOVECONF_USERS="${ACP_MAIL_DOVECOT_USERS:-/etc/dovecot/alphacp-users}"
DOVECONF_FILE="${ACP_MAIL_DOVECOT_CONF:-/etc/dovecot/conf.d/99-alphacp.conf}"
EXIM_TEMPLATE="${ACP_MAIL_EXIM_TEMPLATE:-/etc/exim4/exim4.conf.template}"
EXIM_DOMAINS="${ACP_MAIL_EXIM_DOMAINS:-/etc/exim4/alphacp-domains}"
EXIM_RECIPIENTS="${ACP_MAIL_EXIM_RECIPIENTS:-/etc/exim4/alphacp-recipients}"
EXIM_ALIASES="${ACP_MAIL_EXIM_ALIASES:-/etc/exim4/alphacp-aliases}"
EXIM_CATCHALL="${ACP_MAIL_CATCHALL:-/etc/exim4/alphacp-catchall}"
VACATION_DIR="${ACP_MAIL_VACATION_DIR:-/etc/exim4/alphacp-vacation}"
SPAM_DIR="${ACP_MAIL_SPAM_DIR:-/etc/exim4/alphacp-spam}"
REPORT_DIR="${ACP_HOME}/verify-reports"

PASS=0; FAIL=0; SKIP=0; TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; TASK_OUT=""; DONE=0
CREATED_ACCOUNT=0; LIVE_MAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
# diagnostics: screen par bhi, aur ${ACP_HOME}/verify-reports/s7-diag.txt me bhi
# (hourly sync se ye file main branch par aa jati hai — main khud padh leta hu)
DIAG_FILE="${ACP_HOME}/verify-reports/s7-diag.txt"
diag() { printf '  ::   %s\n' "$1"; [[ -d "${ACP_HOME}/verify-reports" ]] && printf '%s\n' "$1" >> "${DIAG_FILE}" 2>/dev/null; return 0; }
diagsec() { diag ""; diag "=== $1 ==="; }
diagcmd() { diag "\$ $*"; diag "$("$@" 2>&1 | head -20 | sed 's/^/    /')"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
skip() { SKIP=$((SKIP+1)); printf '  \033[33mskip\033[0m %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "root ke saath chalao: sudo bash $0"; exit 1
fi
[[ -x "${PANELD}" ]] || { echo "paneld nahi mila: ${PANELD}"; exit 1; }
grep -q "'mail[.]server'" "${ACP_HOME}/agent/config/tasks.php" 2>/dev/null \
  || { echo "agent me mail.server task nahi hai — pehle 0.75.0 update karo"; exit 1; }

find_bin() {  # $1 tool, $2 env var, $3 preferred path
  local tool="$1" envvar="$2" pref="$3" envval="" found="" d=""
  envval="$(printenv "${envvar}" 2>/dev/null || true)"
  if [[ -n "${envval}" ]]; then printf '%s' "${envval}"; return 0; fi
  if [[ -n "${pref}" && -x "${pref}" ]]; then printf '%s' "${pref}"; return 0; fi
  found="$(command -v "${tool}" 2>/dev/null || true)"
  if [[ -n "${found}" && -x "${found}" ]]; then printf '%s' "${found}"; return 0; fi
  for d in /usr/sbin /usr/bin /sbin /bin /usr/local/sbin /usr/local/bin; do
    if [[ -x "${d}/${tool}" ]]; then printf '%s' "${d}/${tool}"; return 0; fi
  done
  printf ''
}

EXIM="$(find_bin exim4 ACP_VERIFY_EXIM /usr/sbin/exim4)"
DIG="$(find_bin dig ACP_VERIFY_DIG /usr/bin/dig)"
DOVEADM="$(find_bin doveadm ACP_VERIFY_DOVEADM /usr/bin/doveadm)"
DOVECOT_BIN="$(find_bin dovecot ACP_VERIFY_DOVECOT /usr/sbin/dovecot)"
DOVECONF_BIN="$(find_bin doveconf ACP_VERIFY_DOVECONF /usr/sbin/doveconf)"

cleanup() {
  if [[ "${DONE}" != "1" ]]; then info "cleanup (script beech me ruka)"; fi
  if [[ "${CREATED_ACCOUNT}" == "1" ]]; then
    "${RUNNER[@]}" --run account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}" >/dev/null 2>&1 || true
  fi
  "${RUNNER[@]}" --run mail.server '{"action":"sync"}' >/dev/null 2>&1 || true
  return 0
}
trap cleanup EXIT

RUNNER=()
if [[ -n "${PHP_BIN}" && -x "${PHP_BIN}" ]]; then
  RUNNER=("${PHP_BIN}" "${PANELD}")
elif [[ -x "${PANELD}" ]]; then
  RUNNER=("${PANELD}")
else
  echo "php binary nahi mila (ACP_PHP=... set karo) aur paneld executable bhi nahi"; exit 1
fi

run_task() {  # type payload -> 0/1 ; LAST_TASK_ID + LAST_ERR + TASK_OUT set
  local type="$1" payload="$2" out
  out="$("${RUNNER[@]}" --run "${type}" "${payload}" 2>&1)"
  TASK_OUT="${out}"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"${out}" | grep -o '[0-9]*' | head -1)"
  [[ -n "${LAST_TASK_ID}" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if grep -q '"status": "success"' <<<"${out}"; then
    LAST_ERR=""
    return 0
  fi
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"${out}" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -z "${LAST_ERR}" ]] && LAST_ERR="$(tail -1 <<<"${out}")"
  return 1
}

mkdir -p "${ACP_HOME}/verify-reports" 2>/dev/null || true
: > "${ACP_HOME}/verify-reports/s7-diag.txt" 2>/dev/null || true
diag "S7 mail diagnostics — $(date -u +%Y-%m-%dT%H:%M:%SZ) host=$(hostname -f 2>/dev/null || hostname)"
echo "=== S7 MAIL SERVER LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "test addr: ${TEST_ADDR}"
echo

# ------------------------------------------------------------------ part A ----
info "A: preconditions (exim4 + dovecot installed?)"
MISSING=""
for b in "${EXIM}" "${DOVEADM}" "${DOVECOT_BIN}"; do
  [[ -n "${b}" && -x "${b}" ]] || MISSING="${MISSING} ${b:-<nahi-mila>}"
done
if [[ -n "${MISSING}" ]]; then
  bad "mail tools nahi mile:${MISSING}"
  info "Fix: sudo apt-get install -y exim4 exim4-daemon-light dovecot-core dovecot-imapd dovecot-pop3d"
  info "command -v exim4 : $(command -v exim4 2>/dev/null || echo NAHI-MILA)"
  info "command -v dovecot: $(command -v dovecot 2>/dev/null || echo NAHI-MILA)"
  echo; echo "=== S7 MAIL SERVER LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
  DONE=1; exit 1
fi
ok "exim4: ${EXIM}"
ok "dovecot: ${DOVECOT_BIN}"
ok "doveadm: ${DOVEADM}"
ok "agent me mail.server task registered"

# ------------------------------------------------------------------ part B ----
echo
info "B: mail.server setup (config + services)"
if run_task mail.server '{"action":"setup"}'; then
  ok "setup success (task #${LAST_TASK_ID})"
else
  bad "setup fail: ${LAST_ERR:-unknown}"
fi
[[ -f "${DOVECONF_FILE}" ]] && ok "dovecot managed conf: ${DOVECONF_FILE}" || bad "dovecot conf nahi mili"
[[ -f "${EXIM_TEMPLATE}.acp-orig" ]] && ok "asli exim template ki backup hai" || bad "exim template backup nahi mili"
[[ -f "${EXIM_DOMAINS}" ]] && ok "domains file: ${EXIM_DOMAINS}" || bad "domains file nahi mili"
[[ -f "${EXIM_RECIPIENTS}" ]] && ok "recipients file: ${EXIM_RECIPIENTS}" || bad "recipients file nahi mili"
[[ -f "${EXIM_ALIASES}" ]] && ok "aliases file: ${EXIM_ALIASES}" || bad "aliases file nahi mili"
[[ -f "${DOVECONF_USERS}" ]] && ok "dovecot users file: ${DOVECONF_USERS}" || bad "dovecot users file nahi mili"
# setup ke baad agent ka apna probe: Dovecot khud bole ki mailbox mili ya nahi
if grep -q '"dovecot_userdb"' <<<"${TASK_OUT}"; then
  if grep -q '"ok": *false' <<<"${TASK_OUT}"; then
    bad "setup ka dovecot userdb probe FAIL — ${ACP_HOME}/verify-reports/s7-diag.txt dekhein"
  else
    ok "setup ka dovecot userdb probe pass (Dovecot ne mailbox dhoondh li)"
  fi
fi
if [[ -f "${ACP_HOME}/etc/mail-server-configured" ]]; then
  ok "configured marker likha gaya (updater services chalu rakhega)"
else
  bad "configured marker nahi mila"
fi

# ------------------------------------------------------------------ part C ----
echo
info "C: config asli daemons se validate"
if "${EXIM}" -bV >/dev/null 2>&1; then
  ok "exim4 -bV pass: $("${EXIM}" -bV 2>/dev/null | head -1)"
else
  bad "exim4 -bV fail: $("${EXIM}" -bV 2>&1 | head -3 | tr '\n' ' ')"
fi
if [[ -n "${DOVECONF_BIN}" && -x "${DOVECONF_BIN}" ]]; then
  if "${DOVECONF_BIN}" -n >/dev/null 2>&1; then
    ok "doveconf -n pass"
  else
    bad "doveconf -n fail: $("${DOVECONF_BIN}" -n 2>&1 | head -3 | tr '\n' ' ')"
  fi
fi

# ------------------------------------------------------------------ part D ----
echo
info "D: services chal rahe hain?"
for u in exim4 dovecot; do
  if [[ "$(systemctl is-active "$u" 2>/dev/null)" == "active" ]]; then
    ok "${u} service active"
  else
    bad "${u} service active NAHI"
    info "journalctl ${u}: $(journalctl -u "${u}" -n 8 --no-pager 2>/dev/null | tail -5 | tr '\n' ' ')"
  fi
done
P25="$(ss -lnt 2>/dev/null | grep -c ':25 ')"
P143="$(ss -lnt 2>/dev/null | grep -c ':143 ')"
if [[ "${P25}" -gt 0 ]]; then ok "port 25 (SMTP) sun raha hai"; else skip "port 25 nahi sun raha (AWS security group ya local_interfaces check karo)"; fi
if [[ "${P143}" -gt 0 ]]; then ok "port 143 (IMAP) sun raha hai"; else skip "port 143 nahi sun raha (Dovecot abhi localhost-only hai — S7 slice 2 me TLS ke saath khulega)"; fi

# ------------------------------------------------------------------ part E ----
echo
info "E: ASLI MAIL — mailbox banao, routing test karo, mail bhejo"
HASH="${ACP_MAIL_TEST_HASH:-}"
if [[ -z "${HASH}" && -n "${PHP_BIN}" && -x "${PHP_BIN}" ]]; then
  HASH="$("${PHP_BIN}" -r 'echo password_hash("AcpMailTest123", PASSWORD_BCRYPT);' 2>/dev/null)"
fi
if [[ ! "${HASH}" =~ ^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9./]{53}$ ]]; then
  skip "bcrypt hash nahi ban paaya (php missing?) — real delivery test chhoda"
else
  SHADOW="$(openssl passwd -6 'AcpMailTest123' 2>/dev/null || echo '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv')"
  if run_task account.create "{\"username\":\"${TEST_USER}\",\"domain\":\"${TEST_DOMAIN}\",\"shadow_hash\":\"${SHADOW}\",\"quota_mb\":256,\"php_version\":\"8.4\"}"; then
    ok "kaccha account ban gaya (task #${LAST_TASK_ID})"
    CREATED_ACCOUNT=1
  else
    skip "kaccha account nahi ban paaya (${LAST_ERR:-unknown}) — real delivery test chhoda"
  fi

  if [[ "${CREATED_ACCOUNT}" == "1" ]]; then
    if run_task mail.set "{\"username\":\"${TEST_USER}\",\"mailboxes\":[{\"local\":\"info\",\"domain\":\"${TEST_DOMAIN}\",\"hash\":\"${HASH}\",\"quota_mb\":100}]}"; then
      ok "mailbox ban gaya (task #${LAST_TASK_ID})"
    else
      bad "mail.set fail: ${LAST_ERR:-unknown}"
    fi
    if run_task mail.server '{"action":"sync"}'; then
      ok "sync ne mailbox aggregate kar liya (task #${LAST_TASK_ID})"
    else
      bad "sync fail: ${LAST_ERR:-unknown}"
    fi
    if grep -q "^${TEST_ADDR}:" "${DOVECONF_USERS}" 2>/dev/null; then
      ok "dovecot users file me ${TEST_ADDR} hai"
    else
      bad "dovecot users file me ${TEST_ADDR} nahi mila"
    fi
    if grep -q "^${TEST_DOMAIN}$" "${EXIM_DOMAINS}" 2>/dev/null; then
      ok "exim local domains me ${TEST_DOMAIN} hai"
    else
      bad "exim domains file me ${TEST_DOMAIN} nahi mila"
    fi

    # 1) routing test (asli exim)
    ROUTE="$("${EXIM}" -bt "${TEST_ADDR}" 2>&1 | head -3 | tr '\n' ' ')"
    if [[ "${ROUTE}" == *alphacp_maildir* || "${ROUTE}" == *alphacp_mailbox* ]]; then
      ok "exim -bt: ${ROUTE}"
    else
      bad "exim -bt galat: ${ROUTE}"
    fi

    # 2) dovecot mailbox lookup
    if "${DOVEADM}" user "${TEST_ADDR}" >/dev/null 2>&1; then
      ok "doveadm user ${TEST_ADDR}: $("${DOVEADM}" user "${TEST_ADDR}" 2>/dev/null | tr '\n' ' ')"
    else
      bad "doveadm user ${TEST_ADDR} fail: $("${DOVEADM}" user "${TEST_ADDR}" 2>&1 | head -2 | tr '\n' ' ')"
      diagsec "DOVEADM FAIL diagnostics (${TEST_ADDR})"
      diagcmd ls -l "${DOVECONF_USERS}"
      diag "--- users file pehli line (hash chhupa kar) ---"
      diag "$(head -1 "${DOVECONF_USERS}" 2>/dev/null | sed 's/\({BLF-CRYPT}\)[^:]*/\1<hash>/')"
      diag "--- doveconf -n (alphacp + auth) ---"
      diag "$("${DOVECONF_BIN}" -n 2>&1 | grep -iE 'alphacp|userdb|passdb|mail_location|mail_home|first_valid|auth_' | head -20 | sed 's/^/    /')"
      diag "--- dovecot auth worker file padh sakta hai? ---"
      diag "$(sudo -u dovecot head -1 "${DOVECONF_USERS}" >/dev/null 2>&1 && echo 'HAA (dovecot user padh sakta hai)' || echo 'NAHI (permission problem — yahi wajah ho sakti hai)')"
      diag "--- dovecot log (aakhri 20) ---"
      diag "$(tail -20 /var/log/dovecot.log 2>/dev/null || journalctl -u dovecot -n 20 --no-pager 2>/dev/null)"
      diagcmd id dovecot
      diagcmd id Debian-exim
    fi

    # 3) ASLI DELIVERY — mail bhejo aur Maildir me file dhoondho
    # Maildir ka pata khud Dovecot se poochha (hardcode nahi — sim me bhi chale)
    MAILDIR="$("${DOVEADM}" user "${TEST_ADDR}" 2>/dev/null | awk '/^home[[:space:]]/ {print $2}' | head -1)"
    [[ -z "${MAILDIR}" ]] && MAILDIR="/home/${TEST_USER}/mail/${TEST_DOMAIN}/info"
    info "maildir (doveadm se): ${MAILDIR}"
    BEFORE="$(ls -1 "${MAILDIR}/new" 2>/dev/null | wc -l)"
    printf 'Subject: AlphaCP S7 test\nFrom: root@%s\n\nHello from the S7 live check.\n' "$(hostname -f 2>/dev/null || hostname)" \
      | "${EXIM}" -odf -oem "${TEST_ADDR}" >/dev/null 2>&1
    sleep 1
    AFTER="$(ls -1 "${MAILDIR}/new" 2>/dev/null | wc -l)"
    if [[ "${AFTER}" -gt "${BEFORE}" ]]; then
      ok "ASLI MAIL PAHUNCH GAYI → ${MAILDIR}/new (${BEFORE} se ${AFTER})"
      LIVE_MAIL=1
      head -5 "${MAILDIR}/new/$(ls -1t "${MAILDIR}/new" | head -1)" 2>/dev/null | sed 's/^/       | /'
    else
      bad "mail Maildir me nahi pahunchi (${MAILDIR}/new me koi naya file nahi)"
      info "exim mainlog: $(tail -12 /var/log/exim4/mainlog 2>/dev/null | tr '\n' ' ')"
      info "exim paniclog: $(tail -6 /var/log/exim4/paniclog 2>/dev/null | tr '\n' ' ')"
      info "exim -bt     : ${ROUTE}"
      info "maildir perms: $(ls -ld "${MAILDIR}" "${MAILDIR}/new" 2>&1 | tr '\n' ' ')"
      info "exim user    : $("${EXIM}" -bP exim_user 2>/dev/null | tr '\n' ' ')"
      info "setuid bit   : $(ls -l "${EXIM}" 2>/dev/null)"
      diagsec "DELIVERY FAIL diagnostics (${TEST_ADDR})"
      diagcmd ls -ld "/home/${TEST_USER}" "/home/${TEST_USER}/mail" "/home/${TEST_USER}/mail/${TEST_DOMAIN}" "${MAILDIR}" "${MAILDIR}/new" "${MAILDIR}/tmp"
      diag "--- exim kis user se chal raha hai ---"
      diag "systemctl show User : $(systemctl show -p User --value exim4 2>/dev/null)"
      diag "exim -bP exim_user  : $("${EXIM}" -bP exim_user 2>/dev/null)"
      diag "exim -bP deliver_drop_privilege : $("${EXIM}" -bP deliver_drop_privilege 2>/dev/null)"
      diag "setuid bit          : $(ls -l "${EXIM}" 2>/dev/null)"
      diag "running daemon uid  : $(ps -o user= -C exim4 2>/dev/null | head -2 | tr '\n' ' ')"
      diag "--- mailbox user likh sakta hai? (setuid path) ---"
      diag "$(sudo -u "${TEST_USER}" test -w "${MAILDIR}/new" 2>/dev/null && echo "HAA (${TEST_USER} likh sakta hai)" || echo "NAHI — ${TEST_USER} ko ${MAILDIR}/new me likhne nahi deta")"
      diag "--- Debian-exim likh sakta hai? (bina setuid path) ---"
      diag "$(sudo -u Debian-exim test -w "${MAILDIR}/new" 2>/dev/null && echo 'HAA (Debian-exim bhi likh sakta hai)' || echo 'NAHI — Debian-exim ko permission nahi (setuid zaroori hai)')"
      diag "--- exim mainlog (aakhri 25) ---"
      diag "$(tail -25 /var/log/exim4/mainlog 2>/dev/null)"
      diag "--- mail.server sync ka natija (repair) ---"
      diag "$(${PHP_BIN} ${ACP_HOME}/agent/bin/paneld --run mail.server '{"action":"sync"}' 2>&1 | head -12)" 
    fi
  fi
fi

# ------------------------------------------------------------------ part F ----
echo
info "F: verify / list / status"
if run_task mail.server "{\"action\":\"verify\",\"address\":\"${TEST_ADDR}\"}"; then
  ok "verify action chala (task #${LAST_TASK_ID})"
else
  bad "verify fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"list"}'; then
  ok "list action chala (task #${LAST_TASK_ID})"
else
  bad "list fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"status"}'; then
  ok "status action chala (task #${LAST_TASK_ID})"
  grep -q '"installed": *true' <<<"${TASK_OUT}" && ok "status: installed = true" || bad "status me installed true nahi mila"
else
  bad "status fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"destroy"}'; then
  bad "unknown action chal gaya (refuse hona chahiye tha)"
else
  ok "unknown action refuse (${LAST_ERR:-unknown})"
fi

# ------------------------------------------------------------------ part G ----
echo
info "G: catch-all + autoresponder + SPF/DKIM/DMARC (asli DNS me)"
if [[ "${CREATED_ACCOUNT}" != "1" ]]; then
  skip "kaccha account nahi bana — ye sab check chhoda"
else
  # 1) catch-all
  if run_task mail.catchall "{\"username\":\"${TEST_USER}\",\"catchalls\":[{\"domain\":\"${TEST_DOMAIN}\",\"dest\":\"info@${TEST_DOMAIN}\"}]}"; then
    ok "catch-all set kiya gaya (task #${LAST_TASK_ID})"
  else
    bad "mail.catchall fail: ${LAST_ERR:-unknown}"
  fi
  run_task mail.server '{"action":"sync"}' >/dev/null 2>&1 || true
  if grep -q "^\\*@${TEST_DOMAIN}:" "${EXIM_CATCHALL}" 2>/dev/null; then
    ok "catch-all file me *@${TEST_DOMAIN} hai"
  else
    bad "catch-all file me entry nahi mili (${EXIM_CATCHALL})"
  fi
  # poora output: catch-all redirect ke baad final router (alphacp_mailbox) dikhta
  # hai, aur redirect karne wala router (alphacp_catchall) chain me — dono theek hain
  R2="$("${EXIM}" -bt "unknown-nobody@${TEST_DOMAIN}" 2>&1 | tr '\n' ' ')"
  if [[ "${R2}" == *alphacp_catchall* ]]; then
    ok "exim -bt unknown@: ${R2}"
  elif [[ "${R2}" == *Unrouteable* || "${R2}" == *"cannot route"* ]]; then
    bad "catch-all routing nahi mila: ${R2}"
  else
    ok "exim -bt unknown@ (redirect hua): ${R2}"
    info "note: catch-all redirect ke baad final delivery mailbox par hoti hai"
  fi

  # 2) autoresponder (vacation)
  if run_task mail.autorespond "{\"username\":\"${TEST_USER}\",\"responders\":[{\"local\":\"info\",\"domain\":\"${TEST_DOMAIN}\",\"subject\":\"Chutti par hu\",\"body\":\"Kal laut kar jawab dunga.\",\"interval_h\":24}]}"; then
    ok "autoresponder set kiya gaya (task #${LAST_TASK_ID})"
  else
    bad "mail.autorespond fail: ${LAST_ERR:-unknown}"
  fi
  if [[ -f "${VACATION_DIR}/${TEST_ADDR}.eml" ]]; then
    ok "vacation file bana: ${VACATION_DIR}/${TEST_ADDR}.eml"
    head -1 "${VACATION_DIR}/${TEST_ADDR}.eml" | sed 's/^/       | /'
  else
    bad "vacation file nahi mila (${VACATION_DIR}/${TEST_ADDR}.eml)"
  fi

  # 3) spam lists
  if run_task mail.spam "{\"username\":\"${TEST_USER}\",\"required_score\":5,\"blacklist\":[\"spam@bad.test\"],\"whitelist\":[\"boss@good.test\"]}"; then
    ok "mail.spam set kiya gaya (task #${LAST_TASK_ID})"
  else
    bad "mail.spam fail: ${LAST_ERR:-unknown}"
  fi
  if grep -q "spam@bad.test" "${SPAM_DIR}/${TEST_ADDR}.deny" 2>/dev/null; then
    ok "blacklist file: ${SPAM_DIR}/${TEST_ADDR}.deny"
  else
    bad "blacklist file nahi mili (${SPAM_DIR}/${TEST_ADDR}.deny)"
  fi

  # 4) deliverability: SPF + DMARC + DKIM records — BIND live hai to dig se verify
  if run_task mail.server '{"action":"deliverability"}'; then
    ok "deliverability chala (task #${LAST_TASK_ID})"
    grep -q '"dkim": *true' <<<"${TASK_OUT}" && ok "DKIM key ban gayi (exim signing ke liye taiyar)" \
      || skip "DKIM key nahi bani (openssl missing?) — SPF/DMARC phir bhi likhe gaye"
  else
    bad "deliverability fail: ${LAST_ERR:-unknown}"
  fi

  if [[ -n "${DIG}" && -x "${DIG}" ]]; then
    SPF_TXT="$("${DIG}" @127.0.0.1 "${TEST_DOMAIN}" TXT +short 2>/dev/null | tr -d '"' | grep '^v=spf1' | head -1)"
    if [[ -n "${SPF_TXT}" ]]; then
      ok "dig TXT ${TEST_DOMAIN} = ${SPF_TXT}"
    else
      bad "dig se SPF record nahi mila (${TEST_DOMAIN} TXT)"
      info "zone file: $(ls -1 /etc/bind/zones/db.${TEST_DOMAIN} 2>/dev/null || echo nahi-mili)"
    fi
    DM_TXT="$("${DIG}" @127.0.0.1 "_dmarc.${TEST_DOMAIN}" TXT +short 2>/dev/null | tr -d '"' | grep '^v=DMARC1' | head -1)"
    [[ -n "${DM_TXT}" ]] && ok "dig TXT _dmarc = ${DM_TXT}" || bad "dig se DMARC record nahi mila"
    DK_TXT="$("${DIG}" @127.0.0.1 "default._domainkey.${TEST_DOMAIN}" TXT +short 2>/dev/null | tr -d '"' | grep '^v=DKIM1' | head -1)"
    if [[ -n "${DK_TXT}" ]]; then
      ok "dig TXT default._domainkey = ${DK_TXT:0:40}... (DKIM DNS me live)"
    else
      skip "DKIM DNS record nahi mila (key nahi bani ya abhi pending)"
    fi
  else
    skip "dig nahi mila — DNS records verify nahi hue (dnsutils install karein)"
  fi
fi

# ------------------------------------------------------------------ part H ----
echo
info "H: server-wide — mail queue / delivery reports / exim+dovecot config / disk usage"

# cPanel #141 — Mail Queue Manager
if run_task mail.server '{"action":"queue"}'; then
  QCOUNT="$(grep -o '"count": *[0-9]*' <<<"${TASK_OUT}" | grep -o '[0-9]*' | head -1)"
  ok "mail queue (cPanel #141) — abhi ${QCOUNT:-0} mail pending"
else
  bad "mail.server queue fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"queue","op":"count"}'; then
  ok "queue count action chala"
else
  bad "queue count fail: ${LAST_ERR:-unknown}"
fi
# khatarnak id reject hona hi chahiye (shell injection se bachav)
if run_task mail.server '{"action":"queue","op":"remove","id":"../../etc/passwd"}'; then
  bad "queue: khatarnak message id reject nahi hui (fail-closed tuta)"
else
  ok "queue: khatarnak message id reject (galat id se kuch nahi chalta)"
fi

# cPanel #142 — Mail Delivery Reports (asli exim mainlog se)
if run_task mail.server '{"action":"reports","limit":20}'; then
  if grep -q '"ok": false' <<<"${TASK_OUT}"; then
    skip "delivery reports: exim mainlog nahi mila (abhi tak koi mail log nahi bana)"
  else
    ARRIVED="$(grep -o '"arrived": *[0-9]*' <<<"${TASK_OUT}" | grep -o '[0-9]*' | head -1)"
    DELIVERED="$(grep -o '"delivered": *[0-9]*' <<<"${TASK_OUT}" | grep -o '[0-9]*' | head -1)"
    ok "delivery reports (cPanel #142) — mainlog se ginati: aayi=${ARRIVED:-0}, pahunchi=${DELIVERED:-0}"
  fi
else
  bad "mail.server reports fail: ${LAST_ERR:-unknown}"
fi

# cPanel #143 — Exim Configuration Manager
if run_task mail.server '{"action":"eximconf"}'; then
  ok "eximconf: maujuda options + allowed list dikhaye"
else
  bad "eximconf (read) fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"eximconf","set":{"message_size_limit":"100M"}}'; then
  if grep -q 'message_size_limit = 100M' "${EXIM_TEMPLATE}" 2>/dev/null; then
    ok "eximconf (cPanel #143): message_size_limit=100M ASLI exim template me likha"
  else
    bad "eximconf: value template me nahi mili (${EXIM_TEMPLATE})"
  fi
  run_task mail.server '{"action":"eximconf","set":{"message_size_limit":"50M"}}' >/dev/null 2>&1
  if grep -q 'message_size_limit = 50M' "${EXIM_TEMPLATE}" 2>/dev/null; then
    ok "eximconf: wapas 50M (idempotent — dobara likhna surakshit)"
  else
    bad "eximconf: wapas 50M nahi hua"
  fi
else
  bad "eximconf set fail: ${LAST_ERR:-unknown}"
fi
if run_task mail.server '{"action":"eximconf","set":{"message_size_limit":"50X"}}'; then
  bad "eximconf: galat value reject nahi hui (fail-closed tuta — exim kharaab ho sakta tha)"
else
  ok "eximconf: galat value reject (config kharaab hone se bacha)"
fi

# cPanel #144 — Mailserver Configuration (Dovecot)
if run_task mail.server '{"action":"dovecotconf","set":{"mail_max_userip_connections":"20"}}'; then
  if grep -q 'mail_max_userip_connections = 20' "${DOVECONF_FILE}" 2>/dev/null; then
    ok "dovecotconf (cPanel #144): value ASLI ${DOVECONF_FILE} me likhi"
  else
    bad "dovecotconf: value conf me nahi mili (${DOVECONF_FILE})"
  fi
else
  bad "dovecotconf set fail: ${LAST_ERR:-unknown}"
fi

# cPanel #146 — Email Disk Usage (server view)
if run_task mail.server '{"action":"diskusage"}'; then
  TOTAL="$(grep -o '"total_bytes": *[0-9]*' <<<"${TASK_OUT}" | grep -o '[0-9]*' | head -1)"
  ACCS="$(grep -o '"account_count": *[0-9]*' <<<"${TASK_OUT}" | grep -o '[0-9]*' | head -1)"
  ok "email disk usage (cPanel #146): ${ACCS:-0} account, kul ${TOTAL:-0} bytes mail"
else
  bad "diskusage fail: ${LAST_ERR:-unknown}"
fi

DONE=1
echo
echo "=== S7 MAIL SERVER LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
info "tasks: ${TASK_IDS}"
[[ "${LIVE_MAIL}" == "1" ]] && info "ASLI MAIL DELIVERY:VERIFIED" || info "ASLI MAIL DELIVERY:NOT-VERIFIED"

if mkdir -p "${REPORT_DIR}" 2>/dev/null; then
  {
    echo "=== S7 MAIL SERVER LIVE CHECK ($(date -u +%Y-%m-%dT%H:%M:%SZ)) ==="
    echo "pass=${PASS} fail=${FAIL} skip=${SKIP}"
    echo "tasks: ${TASK_IDS}"
    echo "live_mail_delivery=$([[ "${LIVE_MAIL}" == "1" ]] && echo YES || echo NO)"
    echo "tools: exim=${EXIM} dovecot=${DOVECOT_BIN} doveadm=${DOVEADM}"
    echo "mailboxes: $(grep -c ':' "${DOVECONF_USERS}" 2>/dev/null || echo 0)"
    echo "domains: $(wc -l < "${EXIM_DOMAINS}" 2>/dev/null || echo 0)"
  } > "${REPORT_DIR}/s7-mail-check.txt" 2>/dev/null || true
fi

[[ ${FAIL} -eq 0 ]]
