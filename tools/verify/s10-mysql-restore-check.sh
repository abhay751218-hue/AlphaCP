#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — LIVE verification: cpmove MySQL restore, server par ROOT ke saath
#
# Kya karta hai (sab kuch apne aap saaf bhi kar deta hai):
#   1. ek account chunta hai (ACP_VERIFY_ACCOUNT) ya temp account banata hai
#   2. server par ek ASLI cpmove archive banata hai (real tar.gz) jisme
#        cpmove-<acct>/homedir/public_html/index.html
#        cpmove-<acct>/mysql/<acct>_acpverify.sql   (CREATE TABLE + INSERTs)
#   3. usi archive ko `paneld --run db.restore` se import karta hai (sha256 diya jata hai)
#   4. verify karta hai: database bana? table bani? rows gine? (asli MariaDB se)
#   5. doosra archive jo DOOSRE database ko chhoo raha hai -> refuse hona chahiye
#      (koi naya database na bane)
#   6. cleanup: dono database drop, archive dir delete, temp account terminate
#
# Test objects: <acct>_acpverify (<acct>_acpverify2 sirf hostile test ke liye naam)
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
ENVFILE="${ACP_HOME}/etc/database.env"
CLIENT="${ACP_VERIFY_CLIENT:-/usr/bin/mariadb}"; [[ -x "${CLIENT}" ]] || CLIENT="${ACP_VERIFY_CLIENT:-/usr/bin/mysql}"
WORK="${ACP_VERIFY_WORK:-${ACP_HOME}/var/s10-verify}"

PASS=0; FAIL=0; TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; DONE=0; TEMP_ACCOUNT=0

# Report: ${ACP_HOME}/verify-reports/ me likhi jati hai. Ye folder `alphacp-sync` ke hourly
# snapshot ke saath GitHub par chala jata hai (etc/ aur var/ ko chhod kar poora ACP_HOME
# copy hota hai) — isliye live natija bina kisi ko bataye bhi padha ja sakta hai.
REPORT=""
if [[ "${ACP_VERIFY_REPORT:-1}" == "1" && -d "${ACP_HOME}" && -w "${ACP_HOME}" ]]; then
  RDIR="${ACP_HOME}/verify-reports"
  if mkdir -p "${RDIR}" 2>/dev/null; then
    REPORT="${RDIR}/s10-$(date -u +%Y%m%d-%H%M%S).txt"
    exec > >(tee -a "${REPORT}") 2>&1
  fi
fi
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }
die()  { printf '\n\033[31m[x]\033[0m %s\n' "$1"; exit 1; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  die "root ke saath chalao: sudo bash $0"
fi
[[ -n "${PHP_BIN}" && -x "${PHP_BIN}" ]] || die "php binary nahi mila (ACP_PHP=... set karo)"
[[ -x "${PANELD}" ]] || die "paneld nahi mila: ${PANELD}"
[[ -x "${CLIENT}" ]] || die "mariadb/mysql client nahi mila"
[[ -r "${ENVFILE}" ]] || die "nahi mila: ${ENVFILE}"
command -v tar >/dev/null 2>&1 || die "tar nahi mila"

DBN="$(sed -n 's/^ACP_DB_NAME=//p' "${ENVFILE}" | head -1)"; DBN="${DBN:-alphacp}"
sql() { "${CLIENT}" -N -B -e "$1" 2>&1; }

ACCT="${ACP_VERIFY_ACCOUNT:-}"
if [[ -z "${ACCT}" ]]; then
  # Panel DB me account ho par Linux user na ho to db.restore reject karta hai
  # ("Linux user ... is not an AlphaCP account") — isliye aisa account chuno jo DONO
  # jagah maujood ho (panel row + asli Linux user jiska GECOS AlphaCP marker ho).
  CAND="$(sql "SELECT username FROM ${DBN}.accounts WHERE status='active' ORDER BY id")"
  for c in ${CAND}; do
    [[ "${c}" =~ ^[a-z][a-z0-9]{2,15}$ ]] || continue
    id -u "${c}" >/dev/null 2>&1 || continue
    getent passwd "${c}" 2>/dev/null | grep -q 'AlphaCP' || continue
    ACCT="${c}"; break
  done
  [[ -n "${ACCT}" ]] || info "panel DB me koi aisa active account nahi mila jiska Linux user bhi ho"
fi
if [[ -z "${ACCT}" || "${ACCT}" == *ERROR* ]]; then
  if [[ "${ACP_VERIFY_NO_CREATE:-0}" == "1" ]]; then
    die "panel DB me account nahi mila aur ACP_VERIFY_NO_CREATE=1 set hai"
  fi
  ACCT="acpv$(head -c 400 /dev/urandom | tr -dc 'a-z0-9' | head -c 6)"
  TEMP_ACCOUNT=1
fi
[[ "${ACCT}" =~ ^[a-z][a-z0-9]{2,15}$ ]] || die "account username '${ACCT}' invalid"

DB1="${ACCT}_acpverify"; DB2="${ACCT}_acpverify2"
ROWS=3

echo "=== S10 MYSQL RESTORE LIVE CHECK ==="
info "panel DB : ${DBN}"
info "account  : ${ACCT}${TEMP_ACCOUNT:+  (temp — ant me terminate)}"
info "target   : ${DB1} (success case)  ·  ${DB2} (hostile case)"
[[ -n "${REPORT}" ]] && info "report    : ${REPORT}  (hourly sync ke saath GitHub par chala jayega)"
echo

run_task() {  # type payload -> 0/1 ; LAST_TASK_ID + LAST_ERR set
  local type="$1" payload="$2" out
  out="$("${PHP_BIN}" "${PANELD}" --run "${type}" "${payload}" 2>&1)"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"${out}" | grep -o '[0-9]*' | head -1)"
  [[ -n "${LAST_TASK_ID}" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if grep -q '"status": "success"' <<<"${out}"; then
    return 0
  fi
  LAST_ERR="$(grep -m1 '"error": "' <<<"${out}" | cut -d'"' -f4)"
  [[ -z "${LAST_ERR}" ]] && LAST_ERR="$(tail -1 <<<"${out}")"
  return 1
}

cleanup() {
  # tee (process substitution) ko flush hone ka ek mauka — warna report adhuri reh sakti hai
  [[ -n "${REPORT}" ]] && sleep 1
  if [[ "${DONE}" != "1" ]]; then
    info "cleanup (script beech me ruka)"
    "${PHP_BIN}" "${PANELD}" --run db.drop "{\"username\":\"${ACCT}\",\"name\":\"acpverify\",\"_confirm\":\"db.drop\"}" >/dev/null 2>&1
    "${PHP_BIN}" "${PANELD}" --run db.drop "{\"username\":\"${ACCT}\",\"name\":\"acpverify2\",\"_confirm\":\"db.drop\"}" >/dev/null 2>&1
    if [[ "${TEMP_ACCOUNT}" == "1" ]]; then
      "${PHP_BIN}" "${PANELD}" --run account.terminate "{\"username\":\"${ACCT}\",\"_confirm\":\"account.terminate\"}" >/dev/null 2>&1
    fi
  fi
  rm -rf "${WORK}" >/dev/null 2>&1
}
trap cleanup EXIT

[[ -z "$(sql "SHOW DATABASES LIKE '${DB1}'")" ]] || die "${DB1} pehle se maujood hai — pehle hatao"
[[ -z "$(sql "SHOW DATABASES LIKE '${DB2}'")" ]] || die "${DB2} pehle se maujood hai — pehle hatao"

# Archive wahin banni chahiye jahan se agent padh sakta hai: `db.restore` ka PathGuard
# sirf allowlisted roots se padhta hai (/home, /usr/local/alphacp). /tmp allowlisted NAHI
# hai — wahan archive banane par task "cPanel archive path is outside the allowlisted
# roots" ke saath reject ho jata hai (0.71.1 me yahi check pehle hi pakad leta hai).
ALLOW_ROOTS="${ACP_VERIFY_ALLOW_ROOTS:-}"
if [[ -z "${ALLOW_ROOTS}" ]]; then
  ALLOW_ROOTS="$("${PHP_BIN}" -r '$c = @require "'"${ACP_HOME}"'/agent/config/tasks.php"; $p = (is_array($c) && isset($c["db.restore"]["paths"])) ? $c["db.restore"]["paths"] : ["/home", "/usr/local/alphacp"]; echo implode(":", $p);' 2>/dev/null | tail -1)"
fi
[[ -n "${ALLOW_ROOTS}" ]] || ALLOW_ROOTS="/home:/usr/local/alphacp"

mkdir -p "${WORK}" || die "work dir ban nahi paya: ${WORK}"
WREAL="$(cd "${WORK}" 2>/dev/null && pwd -P)"
[[ -n "${WREAL}" ]] || die "work dir resolve nahi hua: ${WORK}"
INSIDE=0
IFS=':' read -r -a ALLOW_ARR <<< "${ALLOW_ROOTS}"
for r in "${ALLOW_ARR[@]}"; do
  [[ -n "${r}" ]] || continue
  rr="$(cd "${r}" 2>/dev/null && pwd -P)" || continue
  [[ -n "${rr}" ]] || continue
  if [[ "${WREAL}" == "${rr}" || "${WREAL}" == "${rr}/"* ]]; then INSIDE=1; break; fi
done
if [[ "${INSIDE}" == "1" ]]; then
  ok "archive dir allowlisted hai: ${WREAL}"
else
  die "archive dir ${WREAL} db.restore ki allowlist (${ALLOW_ROOTS}) ke bahar hai — ACP_VERIFY_WORK se koi allowlisted jagah do (jaise ${ACP_HOME}/var/s10-verify)"
fi

if [[ "${TEMP_ACCOUNT}" == "1" ]]; then
  info "koi account nahi mila — temp account '${ACCT}' banaya ja raha hai (ant me terminate)"
  HASH="$(openssl passwd -6 "AcpVerifyTemp-$(date +%s)-$$" 2>/dev/null || true)"
  if [[ ! "${HASH}" =~ ^\$6\$. ]]; then
    HASH="$("${PHP_BIN}" -r 'echo crypt("AcpVerifyTemp-" . bin2hex(random_bytes(4)), "\$6\$" . bin2hex(random_bytes(8)));' 2>/dev/null | tail -1)"
  fi
  [[ "${HASH}" =~ ^\$6\$. ]] || die "shadow hash generate nahi hua"
  if run_task account.create "{\"username\":\"${ACCT}\",\"domain\":\"${ACCT}.verify.local\",\"shadow_hash\":\"${HASH}\"}"; then
    ok "temp account ${ACCT} ban gaya (task #${LAST_TASK_ID})"
  else
    die "temp account create fail: ${LAST_ERR:-unknown}"
  fi
fi

# ---------------------------------------------------------------- archives ---
info "1/6 test archive bana rahe hain (real tar.gz)"
GOOD="${WORK}/cpmove-${ACCT}.tar.gz"; BAD="${WORK}/cpmove-${ACCT}-hostile.tar.gz"
SRC="${WORK}/src"
mkdir -p "${SRC}/cpmove-${ACCT}/homedir/public_html" "${SRC}/cpmove-${ACCT}/mysql"
printf '<h1>s10 check</h1>\n' > "${SRC}/cpmove-${ACCT}/homedir/public_html/index.html"

{
  printf 'USE `%s`;\n' "${DB1}"
  printf 'DROP DATABASE IF EXISTS `%s`;\n' "${DB1}"          # mysqldump --add-drop-database shape
  printf 'CREATE DATABASE `%s`;\n' "${DB1}"
  printf 'CREATE TABLE `acp_check` (`id` int NOT NULL, `note` varchar(32) NOT NULL, PRIMARY KEY (`id`));\n'
  for i in $(seq 1 "${ROWS}"); do
    printf "INSERT INTO \`acp_check\` VALUES (%d, 'row-%d');\n" "${i}" "${i}"
  done
} > "${SRC}/cpmove-${ACCT}/mysql/${DB1}.sql"

{
  printf 'USE `%s`;\n' "${DB2}"
  printf 'CREATE TABLE `acp_check` (`id` int);\n'
  printf 'DROP DATABASE `%s`;\n' "${DBN}"                     # doosra database -> refuse
} > "${SRC}/cpmove-${ACCT}/mysql/${DB2}.sql.good.tmp"

# hostile archive: usi archive me sirf hostile dump
mkdir -p "${WORK}/bad-src/cpmove-${ACCT}/homedir/public_html" "${WORK}/bad-src/cpmove-${ACCT}/mysql"
printf '<h1>s10 hostile</h1>\n' > "${WORK}/bad-src/cpmove-${ACCT}/homedir/public_html/index.html"
mv "${SRC}/cpmove-${ACCT}/mysql/${DB2}.sql.good.tmp" "${WORK}/bad-src/cpmove-${ACCT}/mysql/${DB2}.sql"

( cd "${SRC}" && tar czf "${GOOD}" "cpmove-${ACCT}" ) || die "good archive ban nahi paya"
( cd "${WORK}/bad-src" && tar czf "${BAD}" "cpmove-${ACCT}" ) || die "hostile archive ban nahi paya"
SHA_GOOD="$(sha256sum "${GOOD}" | cut -d' ' -f1)"
SHA_BAD="$(sha256sum "${BAD}" | cut -d' ' -f1)"
ok "archive ready: $(basename "${GOOD}") ($(stat -c%s "${GOOD}") bytes)"

info "2/6 db.restore — asli import (${DB1})"
if run_task db.restore "{\"username\":\"${ACCT}\",\"archive_path\":\"${GOOD}\",\"sha256\":\"${SHA_GOOD}\",\"_confirm\":\"db.restore\"}"; then
  ok "db.restore task success (task #${LAST_TASK_ID})"
else
  bad "db.restore fail: ${LAST_ERR:-unknown}"
fi

info "3/6 MariaDB se verify"
if [[ -n "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB1}'")" ]]; then
  ok "database ${DB1} bana"
else
  bad "database ${DB1} nahi bana"
fi
TABLES="$(sql "SHOW TABLES FROM ${DB1}")"
if grep -q "^acp_check$" <<<"${TABLES}"; then
  ok "table acp_check import hui"
else
  bad "table acp_check nahi mili (mila: $(tr '\n' ' ' <<<"${TABLES}"))"
fi
COUNT="$(sql "SELECT COUNT(*) FROM ${DB1}.acp_check")"
if [[ "${COUNT}" == "${ROWS}" ]]; then
  ok "${COUNT} rows import hue (expected ${ROWS})"
else
  bad "rows ${COUNT} mile, expected ${ROWS}"
fi
FIRST="$(sql "SELECT note FROM ${DB1}.acp_check WHERE id=1")"
[[ "${FIRST}" == "row-1" ]] && ok "row content sahi (row-1)" || bad "row content galat (mila: ${FIRST})"
if [[ -z "$(sql "SELECT User FROM mysql.user WHERE User='${ACCT}_acpverify'")" ]]; then
  ok "import ne koi MariaDB user nahi banaya (sirf database)"
else
  bad "import ne user bana diya — unexpected"
fi

info "4/6 hostile dump (doosre database ka naam) refuse hona chahiye"
if run_task db.restore "{\"username\":\"${ACCT}\",\"archive_path\":\"${BAD}\",\"sha256\":\"${SHA_BAD}\",\"_confirm\":\"db.restore\"}"; then
  bad "hostile dump import ho gaya?! (refuse hona chahiye tha)"
else
  ok "hostile dump refuse hua: ${LAST_ERR:-unknown}"
fi
if [[ -z "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB2}'")" ]]; then
  ok "hostile dump se koi database nahi bana (${DB2} absent)"
else
  bad "hostile dump ne ${DB2} bana diya?!"
fi
if [[ -n "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DBN}'")" ]]; then
  ok "panel ka apna database ${DBN} salamat hai"
else
  bad "panel ka database ${DBN} gayab?!"
fi

info "5/6 sha256 mismatch refuse hota hai"
WRONG="$(printf '%064d' 0)"
if run_task db.restore "{\"username\":\"${ACCT}\",\"archive_path\":\"${GOOD}\",\"sha256\":\"${WRONG}\",\"_confirm\":\"db.restore\"}"; then
  bad "galat sha256 ke saath import chal gaya?!"
else
  ok "galat sha256 refuse hua"
fi
[[ "$(sql "SELECT COUNT(*) FROM ${DB1}.acp_check")" == "${ROWS}" ]] \
  && ok "pehla database abhi bhi salamat hai" || bad "pehla database bigad gaya"

info "6/6 cleanup — drop databases, archive dir, temp account"
if run_task db.drop "{\"username\":\"${ACCT}\",\"name\":\"acpverify\",\"_confirm\":\"db.drop\"}"; then
  ok "db.drop success (task #${LAST_TASK_ID})"
else
  bad "db.drop fail: ${LAST_ERR:-unknown}"
fi
[[ -z "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB1}'")" ]] \
  && ok "database ${DB1} hat gaya" || bad "database ${DB1} abhi bhi maujood hai"
rm -rf "${WORK}"
[[ ! -d "${WORK}" ]] && ok "archive work dir hat gaya" || bad "work dir abhi bhi hai"
if [[ "${TEMP_ACCOUNT}" == "1" ]]; then
  if run_task account.terminate "{\"username\":\"${ACCT}\",\"_confirm\":\"account.terminate\"}"; then
    ok "temp account ${ACCT} hat gaya (task #${LAST_TASK_ID})"
    TEMP_ACCOUNT=0
  else
    bad "temp account terminate fail: ${LAST_ERR:-unknown} — manually: paneld --run account.terminate '{\"username\":\"${ACCT}\",\"_confirm\":\"account.terminate\"}'"
  fi
fi

DONE=1
echo
echo "=== S10 MYSQL RESTORE LIVE CHECK: ${PASS} pass, ${FAIL} fail ==="
info "tasks: ${TASK_IDS}"
echo "Task Queue me ye ids dikhne chahiye (hostile wala 'failed' — expected)."
[[ ${FAIL} -eq 0 ]]
