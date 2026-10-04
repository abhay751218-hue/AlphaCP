#!/usr/bin/env bash
# =============================================================================
# AlphaCP S8 — LIVE verification (real MariaDB), server par ROOT ke saath chalao
#
# Kya karta hai (sab kuch apne aap saaf bhi kar deta hai):
#   1. panel DB se ek active account chunta hai (ya ACP_VERIFY_ACCOUNT use karta hai)
#   2. paneld --run se ASLI tasks chalata hai: db.create -> db.user.create ->
#      db.user.password (reset) -> db.user.drop -> db.drop
#   3. har step MariaDB se verify karta hai:
#        - database utf8mb4 ke saath bana? (information_schema)
#        - user ban gaya + ALL PRIVILEGES ON <db>.* mila? (SHOW GRANTS)
#        - us password se ASLI login hota hai? (MYSQL_PWD env — argv me nahi)
#        - galat password reject hota hai?
#        - password reset ke baad PURANA fail aur NAYA chalta hai?
#        - task ke stored payload me password scrub (***) ho gaya?
#        - _confirm ke bina drop refuse hota hai aur database bacha rehta hai?
#        - drop ke baad database + user + grant teeno gayab?
#   4. Aakhir me summary: kitne PASS/FAIL + kaun se task ids.
#
# Test objects: <acct>_acpverify  (database AUR user dono) — end me hat jate hain.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
ENVFILE="${ACP_HOME}/etc/database.env"
# offline sim ke liye ACP_VERIFY_CLIENT override (asli server par /usr/bin/mariadb)
CLIENT="${ACP_VERIFY_CLIENT:-/usr/bin/mariadb}"; [[ -x "${CLIENT}" ]] || CLIENT="${ACP_VERIFY_CLIENT:-/usr/bin/mysql}"

PASS=0; FAIL=0; TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; DONE=0
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

DBN="$(sed -n 's/^ACP_DB_NAME=//p' "${ENVFILE}" | head -1)"; DBN="${DBN:-alphacp}"
sql()  { "${CLIENT}" -N -B -e "$1" 2>&1; }
sqlq() { MYSQL_PWD="$2" "${CLIENT}" -u "$1" --protocol=socket -e "$3" 2>&1; }  # password env se (argv me nahi)

ACCT="${ACP_VERIFY_ACCOUNT:-}"
if [[ -z "${ACCT}" ]]; then
  ACCT="$(sql "SELECT username FROM ${DBN}.accounts WHERE status='active' ORDER BY id LIMIT 1")"
  if [[ -z "${ACCT}" || "${ACCT}" == *ERROR* ]]; then
    ACCT="$(sql "SELECT username FROM ${DBN}.accounts ORDER BY id DESC LIMIT 1")"
  fi
fi
if [[ ! "${ACCT}" =~ ^[a-z][a-z0-9]{2,15}$ ]]; then
  die "account username '${ACCT}' invalid — ACP_VERIFY_ACCOUNT=<valid> ke saath chalao"
fi

DB="${ACCT}_acpverify"; USER="${ACCT}_acpverify"
PW1="Acp$(head -c 400 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 13)"
PW2="Rot$(head -c 400 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 13)"

echo "=== S8 LIVE CHECK ==="
info "panel DB   : ${DBN}"
info "account    : ${ACCT}"
info "test db    : ${DB}   (test user: ${USER}@localhost)"
echo

# ---------------------------------------------------------------- cleanup -----
cleanup() {
  if [[ "${DONE}" != "1" ]]; then
    info "cleanup (script beech me ruka) — test objects hata rahe hain"
    "${PHP_BIN}" "${PANELD}" --run db.user.drop \
      "{\"username\":\"${ACCT}\",\"user\":\"acpverify\",\"_confirm\":\"db.user.drop\"}" >/dev/null 2>&1
    "${PHP_BIN}" "${PANELD}" --run db.drop \
      "{\"username\":\"${ACCT}\",\"name\":\"acpverify\",\"_confirm\":\"db.drop\"}" >/dev/null 2>&1
  fi
}
trap cleanup EXIT

# ---------------------------------------------------------------- helpers -----
run_task() {  # type payload -> 0 success / 1 fail; LAST_TASK_ID + LAST_ERR set
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

# ---------------------------------------------------------------- start -------
[[ -z "$(sql "SHOW DATABASES LIKE '${DB}'")" ]] || die "${DB} pehle se maujood hai — pehle hatao"
[[ -z "$(sql "SELECT User FROM mysql.user WHERE User='${USER}'")" ]] || die "user ${USER} pehle se maujood hai — pehle hatao"

info "1/7 db.create — asli CREATE DATABASE"
if run_task db.create "{\"username\":\"${ACCT}\",\"name\":\"acpverify\"}"; then
  ok "db.create task success (task #${LAST_TASK_ID})"
else
  bad "db.create fail: ${LAST_ERR:-unknown}"
fi
if [[ -n "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'")" ]]; then
  ok "database ${DB} MariaDB me maujood hai"
else
  bad "database ${DB} nahi bana"
fi
CS="$(sql "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'")"
if [[ "${CS}" == "utf8mb4" ]]; then ok "charset utf8mb4 (${CS})"; else bad "charset utf8mb4 nahi (mila: ${CS})"; fi

info "2/7 db.user.create — asli user + ALL PRIVILEGES"
if run_task db.user.create \
   "{\"username\":\"${ACCT}\",\"user\":\"acpverify\",\"password\":\"${PW1}\",\"databases\":[\"acpverify\"]}"; then
  ok "db.user.create task success (task #${LAST_TASK_ID})"
else
  bad "db.user.create fail: ${LAST_ERR:-unknown}"
fi
HOSTS="$(sql "SELECT Host FROM mysql.user WHERE User='${USER}' ORDER BY Host")"
if [[ -n "${HOSTS}" ]]; then
  ok "MariaDB user ${USER} bana (host: $(tr '\n' ' ' <<<"${HOSTS}"))"
else
  bad "user ${USER} nahi bana"
fi
GRANTS="$(sql "SHOW GRANTS FOR '${USER}'@'localhost'")"
if grep -qE "ALL PRIVILEGES ON .${DB}." <<<"${GRANTS}"; then
  ok "ALL PRIVILEGES ON ${DB}.* mila"
else
  bad "expected GRANT nahi mila: $(tr '\n' '|' <<<"${GRANTS}")"
fi
if grep -q "${PW1}" <<<"$(sql "SELECT payload FROM ${DBN}.tasks ORDER BY id DESC LIMIT 1")"; then
  bad "task payload me password abhi bhi plain hai"
else
  ok "task payload me password scrub (***) ho gaya"
fi

info "3/7 asli login test — naye password se socket par"
LOGIN="$(sqlq "${USER}" "${PW1}" "SELECT 'login-ok'")"
if grep -q "login-ok" <<<"${LOGIN}"; then
  ok "user ${USER} apne password se ASLI login kar raha hai"
else
  bad "naye user se login fail (mila: ${LOGIN})"
fi
DENIED="$(sqlq "${USER}" "Definitely-Wrong-Password-1" "SELECT 1")"
if grep -qE "denied|ERROR 1045" <<<"${DENIED}"; then
  ok "galat password reject hota hai"
else
  bad "galat password se bhi login ho gaya?! (mila: ${DENIED})"
fi

info "4/7 db.user.password — ALTER USER (password reset)"
if run_task db.user.password \
   "{\"username\":\"${ACCT}\",\"user\":\"acpverify\",\"password\":\"${PW2}\"}"; then
  ok "db.user.password task success (task #${LAST_TASK_ID})"
else
  bad "db.user.password fail: ${LAST_ERR:-unknown}"
fi
NEWPW="$(sqlq "${USER}" "${PW2}" "SELECT 'rotated-ok'")"
if grep -q "rotated-ok" <<<"${NEWPW}"; then
  ok "NAYA password kaam karta hai"
else
  bad "naya password se login fail (mila: ${NEWPW})"
fi
OLDPW="$(sqlq "${USER}" "${PW1}" "SELECT 1")"
if grep -qE "denied|ERROR 1045" <<<"${OLDPW}"; then
  ok "PURANA password ab reject hota hai"
else
  bad "purana password abhi bhi chal raha hai (mila: ${OLDPW})"
fi

info "5/7 destructive confirm guard — _confirm ke bina drop refuse hona chahiye"
if run_task db.drop "{\"username\":\"${ACCT}\",\"name\":\"acpverify\"}"; then
  bad "db.drop bina _confirm ke chala gaya?! (guard toota)"
else
  ok "db.drop bina _confirm ke refuse hua"
  info "ye jaan-boojhkar fail hua — Task Queue me 'rejected' dikhega (expected, guard proof hai)"
fi
if [[ -n "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'")" ]]; then
  ok "database abhi bhi salamat hai (refuse ke baad)"
else
  bad "refuse ke baad database gayab ho gaya"
fi

info "6/7 db.user.drop — user + grants hat gaye"
if run_task db.user.drop \
   "{\"username\":\"${ACCT}\",\"user\":\"acpverify\",\"_confirm\":\"db.user.drop\"}"; then
  ok "db.user.drop task success (task #${LAST_TASK_ID})"
else
  bad "db.user.drop fail: ${LAST_ERR:-unknown}"
fi
if [[ -z "$(sql "SELECT User FROM mysql.user WHERE User='${USER}'")" ]]; then
  ok "MariaDB user ${USER} (saare hosts) hat gaya"
else
  bad "user abhi bhi maujood hai"
fi

info "7/7 db.drop — database hat gaya"
if run_task db.drop "{\"username\":\"${ACCT}\",\"name\":\"acpverify\",\"_confirm\":\"db.drop\"}"; then
  ok "db.drop task success (task #${LAST_TASK_ID})"
else
  bad "db.drop fail: ${LAST_ERR:-unknown}"
fi
if [[ -z "$(sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'")" ]]; then
  ok "database ${DB} hat gaya"
else
  bad "database abhi bhi maujood hai"
fi
if [[ -z "$(sql "SELECT Db FROM mysql.db WHERE Db='${DB}'")" ]]; then
  ok "koi leftover grant row nahi bachi"
else
  bad "mysql.db me leftover grant hai"
fi

DONE=1
echo
echo "=== S8 LIVE CHECK: ${PASS} pass, ${FAIL} fail ==="
info "tasks: ${TASK_IDS}"
echo "Panel me Task Queue kholo — upar wale ids apne result/audit ke saath dikhne chahiye."
[[ ${FAIL} -eq 0 ]]
