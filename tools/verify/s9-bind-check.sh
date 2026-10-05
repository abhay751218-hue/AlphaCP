#!/usr/bin/env bash
# =============================================================================
# AlphaCP S9 — LIVE verification: BIND9 real DNS zones (dns.bind)
# Server par ROOT ke saath chalao. Sab kuch apne aap saaf ho jata hai.
#
# Ye check "likh diya" ko nahi, "named sach me jawab de raha hai" ko verify
# karta hai:
#   A) preconditions — bind9 binaries + agent me dns.bind task
#   B) setup         — idempotent provisioning (include line, options, zone dir)
#   C) write         — zone file + named-checkzone + rndc reload + dig SOA/A
#   D) gate          — kharaab zone reject ho, aur PURANI zone bilkul waise rahe
#   E) remove        — zone file + zone clause hat jayen, dig khamosh ho jaye
#   F) sync/verify/list/status — server-wide sync aur asli dig jawab
#
# Test zone: ${TEST_DOMAIN} (kaccha domain, ant me hata diya jata hai)
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
TEST_DOMAIN="${ACP_VERIFY_DOMAIN:-acp-bind-check.test}"
# Live server par ye /etc/bind/* hote hain. Sim (tools/sim/s9-bind-sim.sh) inhi
# ko override kar ke offline check karta hai — asli path kabhi change nahi hote.
# Binary kahan hai: env override > preferred path > PATH > sab candidates.
# (Server par /usr/sbin ke bajaye /usr/bin mila to bhi check chalna chahiye.)
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

CHECKCONF="$(find_bin named-checkconf ACP_VERIFY_NAMED_CHECKCONF /usr/sbin/named-checkconf)"
CHECKZONE="$(find_bin named-checkzone ACP_VERIFY_NAMED_CHECKZONE /usr/sbin/named-checkzone)"
RNDC="$(find_bin rndc ACP_VERIFY_RNDC /usr/sbin/rndc)"
DIG="$(find_bin dig ACP_VERIFY_DIG /usr/bin/dig)"
ZONE_DIR="${ACP_VERIFY_BIND_ZONE_DIR:-/etc/bind/zones}"
ZONES_FILE="${ACP_VERIFY_BIND_ZONES_FILE:-/etc/bind/named.conf.alphacp}"
NAMED_CONF="${ACP_VERIFY_BIND_NAMED_CONF:-/etc/bind/named.conf}"
OPTIONS_FILE="${ACP_VERIFY_BIND_OPTIONS:-/etc/bind/named.conf.options}"
ZONE_FILE="${ZONE_DIR}/db.${TEST_DOMAIN}"
REPORT_DIR="${ACP_HOME}/verify-reports"

PASS=0; FAIL=0; SKIP=0; TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; DONE=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
skip() { SKIP=$((SKIP+1)); printf '  \033[33mskip\033[0m %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "root ke saath chalao: sudo bash $0"; exit 1
fi
[[ -x "${PANELD}" ]] || { echo "paneld nahi mila: ${PANELD}"; exit 1; }
# Server par paneld PHP se chalta hai. Sim me paneld khud executable hota hai
# (python shebang) — tab PHP ki zaroorat nahi.
RUNNER=()
if [[ -n "${PHP_BIN}" && -x "${PHP_BIN}" ]]; then
  RUNNER=("${PHP_BIN}" "${PANELD}")
elif [[ -x "${PANELD}" ]]; then
  RUNNER=("${PANELD}")
else
  echo "php binary nahi mila (ACP_PHP=... set karo) aur paneld executable bhi nahi"; exit 1
fi
grep -q "'dns[.]bind'" "${ACP_HOME}/agent/config/tasks.php" 2>/dev/null \
  || { echo "agent me dns.bind task nahi hai — pehle 0.74.0 update karo"; exit 1; }

cleanup() {
  if [[ "${DONE}" != "1" ]]; then info "cleanup (script beech me ruka)"; fi
  # test zone hamesha hatani hai — server par kaccha zone nahi rehna chahiye
  if [[ -x "${RNDC}" ]]; then
    "${RNDC}" retransfer "${TEST_DOMAIN}" >/dev/null 2>&1 || true
  fi
  "${RUNNER[@]}" --run dns.bind "{\"action\":\"remove\",\"domain\":\"${TEST_DOMAIN}\"}" >/dev/null 2>&1 || true
  rm -f "${ZONE_FILE}" 2>/dev/null || true
  return 0
}
trap cleanup EXIT

run_task() {  # type payload -> 0/1 ; LAST_TASK_ID + LAST_ERR set
  local type="$1" payload="$2" out
  out="$("${RUNNER[@]}" --run "${type}" "${payload}" 2>&1)"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"${out}" | grep -o '[0-9]*' | head -1)"
  [[ -n "${LAST_TASK_ID}" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if grep -q '"status": "success"' <<<"${out}"; then
    LAST_ERR=""
    TASK_OUT="${out}"
    return 0
  fi
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"${out}" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -z "${LAST_ERR}" ]] && LAST_ERR="$(tail -1 <<<"${out}")"
  TASK_OUT="${out}"
  return 1
}

echo "=== S9 BIND9 LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "test zone: ${TEST_DOMAIN}"
echo

# ------------------------------------------------------------------ part A ----
info "A: preconditions (bind9 installed?)"
MISSING=""
for b in "${CHECKCONF}" "${CHECKZONE}" "${RNDC}" "${DIG}"; do
  [[ -n "${b}" && -x "${b}" ]] || MISSING="${MISSING} ${b:-<nahi-mila>}"
done
if [[ -n "${MISSING}" ]]; then
  bad "bind9 tools nahi mile:${MISSING}"
  echo
  info "--- diagnosis (ye poori copy bhej do) ---"
  for t in named-checkconf named-checkzone rndc dig; do
    info "command -v ${t} -> $(command -v "${t}" 2>/dev/null || echo 'NAHI MILA')"
  done
  info "dpkg bind packages: $(dpkg -l 2>/dev/null | grep -E '^ii +bind9' | awk '{print $2" "$3}' | tr '\n' ' ')"
  info "ls /usr/sbin/named* : $(ls -1 /usr/sbin/named* 2>/dev/null | tr '\n' ' ')"
  info "ls /usr/bin/named*  : $(ls -1 /usr/bin/named* 2>/dev/null | tr '\n' ' ')"
  info "ls /usr/bin/dig /usr/sbin/dig /usr/bin/rndc /usr/sbin/rndc: $(ls -1 /usr/bin/dig /usr/sbin/dig /usr/bin/rndc /usr/sbin/rndc 2>/dev/null | tr '\n' ' ')"
  info "dpkg -S named-checkconf: $(dpkg -S named-checkconf 2>&1 | head -2 | tr '\n' ' ')"
  info "systemctl named: $(systemctl is-active named 2>/dev/null || echo 'inactive')"
  info "--- ant ---"
  info "Fix: sudo apt-get update && sudo apt-get install -y bind9 bind9-utils dnsutils"
  echo
  echo "=== S9 BIND9 LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
  if mkdir -p "${REPORT_DIR}" 2>/dev/null; then
    {
      echo "=== S9 BIND9 LIVE CHECK ($(date -u +%Y-%m-%dT%H:%M:%SZ)) ==="
      echo "pass=${PASS} fail=${FAIL} skip=${SKIP}"
      echo "missing:${MISSING}"
      echo "command -v named-checkconf: $(command -v named-checkconf 2>/dev/null || echo NAHI-MILA)"
      echo "command -v named-checkzone: $(command -v named-checkzone 2>/dev/null || echo NAHI-MILA)"
      echo "command -v rndc: $(command -v rndc 2>/dev/null || echo NAHI-MILA)"
      echo "command -v dig: $(command -v dig 2>/dev/null || echo NAHI-MILA)"
      echo "dpkg bind9: $(dpkg -l 2>/dev/null | grep -E '^ii +bind9' | awk '{print $2" "$3}' | tr '\n' ' ')"
    } > "${REPORT_DIR}/s9-bind-check.txt" 2>/dev/null || true
  fi
  DONE=1
  exit 1
fi
ok "named-checkconf: ${CHECKCONF}"
ok "named-checkzone: ${CHECKZONE}"
ok "rndc: ${RNDC}"
ok "dig: ${DIG}"
ok "agent me dns.bind task registered"

# ------------------------------------------------------------------ part B ----
echo
info "B: dns.bind setup (idempotent provisioning)"
if run_task dns.bind '{"action":"setup"}'; then
  ok "setup success (task #${LAST_TASK_ID})"
else
  bad "setup fail: ${LAST_ERR:-unknown}"
fi
[[ -d "${ZONE_DIR}" ]] && ok "zone dir: ${ZONE_DIR}" || bad "zone dir nahi bana: ${ZONE_DIR}"
if grep -q 'include "'"${ZONES_FILE}"'";' "${NAMED_CONF}" 2>/dev/null; then
  ok "named.conf me AlphaCP include line hai"
else
  bad "named.conf me include line nahi mili"
fi
if [[ -f "${ZONES_FILE}" ]] && grep -q 'AlphaCP managed' "${ZONES_FILE}" 2>/dev/null; then
  ok "managed zones file likhi gayi: ${ZONES_FILE}"
else
  bad "managed zones file nahi mili"
fi
if grep -q 'recursion no;' "${OPTIONS_FILE}" 2>/dev/null; then
  ok "options me recursion off (open resolver nahi banenge)"
else
  bad "options me 'recursion no;' nahi mila"
fi
if "${CHECKCONF}" "${NAMED_CONF}" >/dev/null 2>&1; then
  ok "named-checkconf pass (poora config theek)"
else
  bad "named-checkconf fail: $("${CHECKCONF}" "${NAMED_CONF}" 2>&1 | head -2 | tr '\n' ' ')"
fi
# dobara setup — idempotent hona chahiye (include line ek hi)
INCLUDE_BEFORE="$(grep -c 'include "'"${ZONES_FILE}"'";' "${NAMED_CONF}" 2>/dev/null || echo 0)"
run_task dns.bind '{"action":"setup"}' >/dev/null 2>&1 || true
INCLUDE_AFTER="$(grep -c 'include "'"${ZONES_FILE}"'";' "${NAMED_CONF}" 2>/dev/null || echo 0)"
if [[ "${INCLUDE_BEFORE}" == "${INCLUDE_AFTER}" && "${INCLUDE_AFTER}" == "1" ]]; then
  ok "doosri baar setup: include line ek hi rahi (idempotent)"
else
  bad "setup idempotent nahi (pehle ${INCLUDE_BEFORE}, baad me ${INCLUDE_AFTER})"
fi

# named sach me chal raha hai? zone file likhna kaafi nahi — serve bhi karna padta hai
NAMED_UNIT=""
for u in named bind9; do
  if [[ "$(systemctl is-active "$u" 2>/dev/null)" == "active" ]]; then NAMED_UNIT="$u"; fi
done
if [[ -z "${NAMED_UNIT}" ]]; then
  info "named active nahi — shuru karne ki koshish (systemctl restart named/bind9)"
  systemctl restart named >/dev/null 2>&1 || systemctl restart bind9 >/dev/null 2>&1 || true
  sleep 2
  for u in named bind9; do
    [[ "$(systemctl is-active "$u" 2>/dev/null)" == "active" ]] && NAMED_UNIT="$u"
  done
fi
if [[ -n "${NAMED_UNIT}" ]]; then
  ok "named service active: ${NAMED_UNIT}"
else
  bad "named service active NAHI hai (koi zone serve nahi hoga)"
  info "systemctl status: $(systemctl status named --no-pager -n 0 2>&1 | head -3 | tr '\n' ' ')"
  info "journalctl named: $(journalctl -u named -n 12 --no-pager 2>/dev/null | tail -8 | tr '\n' ' ')"
  info "journalctl bind9 : $(journalctl -u bind9 -n 12 --no-pager 2>/dev/null | tail -8 | tr '\n' ' ')"
  info "port 53          : $(ss -lntup 2>/dev/null | grep ':53' | head -3 | tr '\n' ' ')"
fi
if [[ -n "${NAMED_UNIT}" ]]; then
  RNDCS="$("${RNDC}" status 2>&1 | head -3 | tr '\n' ' ')"
  info "rndc status: ${RNDCS}"
  if "${RNDC}" status >/dev/null 2>&1; then
    ok "rndc se baat ho rahi hai (reload/reconfig kaam karega)"
  else
    bad "rndc status fail — zone load nahi hoga: ${RNDCS}"
  fi
fi

# ------------------------------------------------------------------ part C ----
echo
info "C: dns.bind write — asli zone (${TEST_DOMAIN})"
PAYLOAD="{\"action\":\"write\",\"domain\":\"${TEST_DOMAIN}\",\"records\":["
PAYLOAD+="{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"@\",\"type\":\"A\",\"value\":\"203.0.113.10\"},"
PAYLOAD+="{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"www\",\"type\":\"A\",\"value\":\"203.0.113.10\"},"
PAYLOAD+="{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"mail\",\"type\":\"A\",\"value\":\"203.0.113.11\"},"
PAYLOAD+="{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"@\",\"type\":\"MX\",\"value\":\"mail.${TEST_DOMAIN}\"},"
PAYLOAD+="{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"@\",\"type\":\"TXT\",\"value\":\"v=spf1 a mx -all\"}"
PAYLOAD+="]}"
if run_task dns.bind "${PAYLOAD}"; then
  ok "write success (task #${LAST_TASK_ID})"
else
  bad "write fail: ${LAST_ERR:-unknown}"
fi
if [[ -f "${ZONE_FILE}" ]]; then
  ok "zone file likhi gayi: ${ZONE_FILE}"
else
  bad "zone file nahi mili: ${ZONE_FILE}"
fi
if "${CHECKZONE}" "${TEST_DOMAIN}" "${ZONE_FILE}" >/dev/null 2>&1; then
  ok "named-checkzone pass (server ke asli tool se)"
else
  bad "named-checkzone fail: $("${CHECKZONE}" "${TEST_DOMAIN}" "${ZONE_FILE}" 2>&1 | head -2 | tr '\n' ' ')"
fi
if grep -q 'zone "'"${TEST_DOMAIN}"'"' "${ZONES_FILE}" 2>/dev/null; then
  ok "zones file me zone clause hai"
else
  bad "zones file me zone clause nahi mila"
fi
# ASLI JAWAB — dig named se poochhta hai, hamari file nahi padhta
SOA_OUT="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "${TEST_DOMAIN}" SOA 2>/dev/null | head -1)"
if [[ -n "${SOA_OUT}" ]]; then
  ok "dig @127.0.0.1 SOA: ${SOA_OUT}"
else
  bad "dig @127.0.0.1 SOA khamosh hai (named zone serve nahi kar raha)"
fi
WWW_OUT="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "www.${TEST_DOMAIN}" A 2>/dev/null | head -1)"
if [[ "${WWW_OUT}" == "203.0.113.10" ]]; then
  ok "dig www A = ${WWW_OUT} (jo likha tha wahi mila)"
else
  bad "dig www A galat: '${WWW_OUT}' (203.0.113.10 hona chahiye)"
fi
MX_OUT="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "${TEST_DOMAIN}" MX 2>/dev/null | head -1)"
if [[ "${MX_OUT}" == *"mail.${TEST_DOMAIN}"* ]]; then
  ok "dig MX = ${MX_OUT}"
else
  bad "dig MX galat: '${MX_OUT}'"
fi
LIVE=1
if [[ -z "${SOA_OUT}" ]]; then
  LIVE=0
  info "--- dig khamosh hai, asli wajah dhoondh rahe hain ---"
  info "rndc reload   : $("${RNDC}" reload "${TEST_DOMAIN}" 2>&1 | head -2 | tr '\n' ' ')"
  info "rndc reconfig : $("${RNDC}" reconfig 2>&1 | head -2 | tr '\n' ' ')"
  info "dig dobara    : $("${DIG}" @127.0.0.1 +short +tries=2 +time=2 "${TEST_DOMAIN}" SOA 2>&1 | head -1)"
  info "dig @server-ip: $("${DIG}" @$("${ACP_VERIFY_DIG_SELF:-127.0.0.1}") +short +tries=1 +time=2 "${TEST_DOMAIN}" SOA 2>&1 | head -1)"
  info "named unit    : ${NAMED_UNIT:-NAHI}"
  info "port 53       : $(ss -lntup 2>/dev/null | grep ':53' | head -3 | tr '\n' ' ')"
  info "journalctl    : $(journalctl -u "${NAMED_UNIT:-named}" -n 10 --no-pager 2>/dev/null | tail -6 | tr '\n' ' ')"
  info "zone file head: $(head -3 "${ZONE_FILE}" 2>/dev/null | tr '\n' ' ')"
  info "zones clause  : $(grep -m1 'zone "'"${TEST_DOMAIN}"'"' "${ZONES_FILE}" 2>/dev/null)"
  info "--- ant ---"
fi
if run_task dns.bind "{\"action\":\"verify\",\"domain\":\"${TEST_DOMAIN}\"}"; then
  ok "dns.bind verify action chala (task #${LAST_TASK_ID})"
  if grep -q 'ns1\.'"${TEST_DOMAIN}" <<<"${TASK_OUT}" || grep -q '203.0.113.10' <<<"${TASK_OUT}"; then
    ok "verify me asli SOA/A jawab aa raha hai"
  else
    bad "verify me SOA/A jawab nahi mila"
  fi
else
  bad "verify fail: ${LAST_ERR:-unknown}"
fi

# ------------------------------------------------------------------ part D ----
echo
info "D: gate — kharaab zone reject ho, purani zone surakshit rahe"
BEFORE_SHA="$(sha256sum "${ZONE_FILE}" 2>/dev/null | cut -d' ' -f1)"
if run_task dns.bind "{\"action\":\"write\",\"domain\":\"${TEST_DOMAIN}\",\"records\":[{\"domain\":\"${TEST_DOMAIN}\",\"name\":\"|/bin/sh\",\"type\":\"A\",\"value\":\"203.0.113.99\"}]}"; then
  bad "hostile record wala zone chal gaya (refuse hona chahiye tha)"
else
  ok "hostile record refuse (${LAST_ERR:-unknown})"
fi
AFTER_SHA="$(sha256sum "${ZONE_FILE}" 2>/dev/null | cut -d' ' -f1)"
if [[ -n "${BEFORE_SHA}" && "${BEFORE_SHA}" == "${AFTER_SHA}" ]]; then
  ok "purani zone bilkul waise hi hai (ek byte bhi nahi badla)"
else
  bad "purani zone badal gayi — gate kaam nahi kar raha"
fi
if [[ -z "$(ls -A "${ZONE_DIR}"/.db.* 2>/dev/null)" ]]; then
  ok "koi temp/part zone file nahi chhuti"
else
  bad "zone dir me kacchi temp file reh gayi: $(ls -A "${ZONE_DIR}"/.db.* 2>/dev/null | head -3 | tr '\n' ' ')"
fi
# wrong action + hostile domain
if run_task dns.bind '{"action":"destroy"}'; then
  bad "unknown action chal gaya (refuse hona chahiye tha)"
else
  ok "unknown action refuse (${LAST_ERR:-unknown})"
fi
if run_task dns.bind '{"action":"remove","domain":"|/bin/sh"}'; then
  bad "hostile domain chal gaya (refuse hona chahiye tha)"
else
  ok "hostile domain refuse (${LAST_ERR:-unknown})"
fi

# ------------------------------------------------------------------ part E ----
echo
info "E: dns.bind remove — zone hat jaye, named khamosh ho jaye"
if run_task dns.bind "{\"action\":\"remove\",\"domain\":\"${TEST_DOMAIN}\"}"; then
  ok "remove success (task #${LAST_TASK_ID})"
else
  bad "remove fail: ${LAST_ERR:-unknown}"
fi
[[ ! -f "${ZONE_FILE}" ]] && ok "zone file hat gayi" || bad "zone file abhi bhi hai: ${ZONE_FILE}"
if ! grep -q 'zone "'"${TEST_DOMAIN}"'"' "${ZONES_FILE}" 2>/dev/null; then
  ok "zones file se zone clause bhi hat gaya"
else
  bad "zones file me zone clause abhi bhi hai"
fi
# named ko zone chhodne me 1-2 second lag sakte hain (reconfig ke baad)
GONE=""
for try in 1 2 3; do
  GONE="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "${TEST_DOMAIN}" SOA 2>/dev/null | head -1)"
  [[ -z "${GONE}" ]] && break
  [[ "${try}" -lt 3 ]] && sleep 1
done
if [[ "${LIVE}" == "1" ]]; then
  if [[ -z "${GONE}" ]]; then
    ok "dig ab khamosh hai (zone serve nahi ho rahi)"
  else
    bad "remove ke baad bhi dig jawab de raha hai: ${GONE}"
    info "rndc reconfig : $("${RNDC}" reconfig 2>&1 | head -2 | tr '\n' ' ')"
    GONE2="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "${TEST_DOMAIN}" SOA 2>/dev/null | head -1)"
    info "reconfig ke baad dig: ${GONE2:-khamosh}"
    info "zones clause  : $(grep -c 'zone "'"${TEST_DOMAIN}"'"' "${ZONES_FILE}" 2>/dev/null) (0 hona chahiye)"
    info "zone file     : $(ls -l "${ZONE_FILE}" 2>&1 | head -1)"
  fi
else
  skip "remove ke baad dig ka test tabhi maayne rakhta hai jab pehle jawab mil raha ho (upar dekho)"
fi
if "${CHECKCONF}" "${NAMED_CONF}" >/dev/null 2>&1; then
  ok "remove ke baad bhi named-checkconf pass"
else
  bad "remove ke baad named-checkconf fail"
fi

# ------------------------------------------------------------------ part F ----
echo
info "F: status / list / sync (poora server)"
if run_task dns.bind '{"action":"status"}'; then
  ok "status action chala (task #${LAST_TASK_ID})"
  grep -q '"installed": *true' <<<"${TASK_OUT}" \
    && ok "status: installed = true" \
    || bad "status me installed true nahi mila"
else
  bad "status fail: ${LAST_ERR:-unknown}"
fi
if run_task dns.bind '{"action":"list"}'; then
  ok "list action chala (task #${LAST_TASK_ID})"
else
  bad "list fail: ${LAST_ERR:-unknown}"
fi
if run_task dns.bind '{"action":"sync"}'; then
  ok "sync action chala — saare account zones zone.json se likhe gaye (task #${LAST_TASK_ID})"
else
  bad "sync fail: ${LAST_ERR:-unknown}"
fi
if "${CHECKCONF}" "${NAMED_CONF}" >/dev/null 2>&1; then
  ok "sync ke baad named-checkconf pass"
else
  bad "sync ke baad named-checkconf fail: $("${CHECKCONF}" "${NAMED_CONF}" 2>&1 | head -2 | tr '\n' ' ')"
fi
# account zone: server par koi account hai to uski zone bani honi chahiye
ACCOUNT_ZONES="$(ls -1 "${ZONE_DIR}"/db.* 2>/dev/null | grep -v "db.${TEST_DOMAIN}" | wc -l)"
if [[ "${ACCOUNT_ZONES}" -gt 0 ]]; then
  ok "sync ne ${ACCOUNT_ZONES} account zone(s) likhi"
  SAMPLE="$(ls -1 "${ZONE_DIR}"/db.* 2>/dev/null | grep -v "db.${TEST_DOMAIN}" | head -1)"
  SAMPLE_ZONE="$(basename "${SAMPLE}" | sed 's/^db\.//')"
  SAMPLE_SOA="$("${DIG}" @127.0.0.1 +short +tries=1 +time=2 "${SAMPLE_ZONE}" SOA 2>/dev/null | head -1)"
  if [[ -n "${SAMPLE_SOA}" ]]; then
    ok "asli account zone live hai: ${SAMPLE_ZONE} -> ${SAMPLE_SOA}"
  else
    bad "account zone file hai par dig khamosh hai: ${SAMPLE_ZONE}"
  fi
else
  skip "server par koi account zone nahi (accounts banne ke baad ye check khud chalega)"
fi

DONE=1
echo
echo "=== S9 BIND9 LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
info "tasks: ${TASK_IDS}"

# report ko GitHub snapshot ke liye bhi likh do (hourly alphacp-sync le jata hai)
if mkdir -p "${REPORT_DIR}" 2>/dev/null; then
  {
    echo "=== S9 BIND9 LIVE CHECK ($(date -u +%Y-%m-%dT%H:%M:%SZ)) ==="
    echo "pass=${PASS} fail=${FAIL} skip=${SKIP}"
    echo "tasks: ${TASK_IDS}"
    echo "zones: $(ls -1 "${ZONE_DIR}"/db.* 2>/dev/null | wc -l)"
    echo "tools: checkconf=${CHECKCONF} checkzone=${CHECKZONE} rndc=${RNDC} dig=${DIG}"
  } > "${REPORT_DIR}/s9-bind-check.txt" 2>/dev/null || true
fi

[[ ${FAIL} -eq 0 ]]
