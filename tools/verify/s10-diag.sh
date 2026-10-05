#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — READ-ONLY diagnostic (kuch bhi nahi badalta, sirf padhta hai)
#
# Ye tab chalao jab `s10-mysql-restore-check.sh` fail ho — isse pata chalega:
#   * panel/agent kaun sa version chal raha hai
#   * db.restore kaunsi paths allow karta hai (PathGuard allowlist)
#   * archive dir (ACP_HOME/var/s10-verify) likhne layak hai ya nahi
#   * panel DB me kaunse account hain, aur unka asli LINUX user hai ya nahi
#     (bina Linux user ke db.restore reject karta hai)
#   * MariaDB root se baat ho rahi hai ya nahi
#   * pichle db.restore tasks ka kya natija aaya (agent log se)
#   * pichli run se kuch bacha to nahi (database / archive dir)
#
# Output ~30 line ka hota hai — usay copy karke bhej do.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
ENVFILE="${ACP_HOME}/etc/database.env"
CLIENT="${ACP_VERIFY_CLIENT:-/usr/bin/mariadb}"; [[ -x "${CLIENT}" ]] || CLIENT="/usr/bin/mysql"
LOG="${ACP_HOME}/var/log/agent.log"

line() { printf '\n== %s ==\n' "$1"; }
kv()   { printf '%-22s %s\n' "$1" "$2"; }

line "host"
kv "date (UTC)" "$(date -u '+%Y-%m-%d %H:%M:%SZ')"
kv "kernel"     "$(uname -srm 2>/dev/null || echo '?')"
kv "whoami"     "$(id -un 2>/dev/null) (uid $(id -u 2>/dev/null))"

line "versions"
if [[ -r "${ACP_HOME}/panel/.env" ]]; then
  kv "panel ACP_VERSION" "$(sed -n 's/^ACP_VERSION=//p' "${ACP_HOME}/panel/.env" | head -1)"
else
  kv "panel .env" "nahi mila (${ACP_HOME}/panel/.env)"
fi
for f in "${ACP_HOME}/agent/VERSION" "${ACP_HOME}/agent/version.txt" "${ACP_HOME}/VERSION"; do
  [[ -r "${f}" ]] && { kv "agent version" "$(head -1 "${f}") (${f})"; break; }
done
kv "php"     "${PHP_BIN:-nahi mila}"
kv "paneld"  "$([[ -x "${PANELD}" ]] && echo "${PANELD} (ok)" || echo "${PANELD} (NAHI MILA)")"

line "paths / allowlist"
kv "ACP_HOME"      "$([[ -d "${ACP_HOME}" ]] && echo "${ACP_HOME} (dir ok)" || echo "${ACP_HOME} (DIR NAHI)")"
kv "ACP_HOME/var"  "$([[ -d "${ACP_HOME}/var" ]] && echo "maujood, mode $(stat -c%a "${ACP_HOME}/var" 2>/dev/null), owner $(stat -c%U "${ACP_HOME}/var" 2>/dev/null)" || echo 'nahi (mkdir -p se ban jayega)')"
kv "incoming (drop)"  "$([[ -d "${ACP_HOME}/incoming" ]] && echo "maujood, $(ls -1 "${ACP_HOME}/incoming" 2>/dev/null | wc -l) file(s)" || echo 'nahi')"
kv "/home"         "$([[ -d /home ]] && echo "dir ok, mode $(stat -c%a /home 2>/dev/null)" || echo 'DIR NAHI')"

ALLOW="/home:/usr/local/alphacp"
if [[ -n "${PHP_BIN}" && -x "${PHP_BIN}" && -r "${ACP_HOME}/agent/config/tasks.php" ]]; then
  got="$("${PHP_BIN}" -r '$c=@require "'"${ACP_HOME}"'/agent/config/tasks.php"; $p=(is_array($c)&&isset($c["db.restore"]["paths"]))?$c["db.restore"]["paths"]:[]; echo implode(":", $p);' 2>/dev/null | tail -1)"
  [[ -n "${got}" ]] && ALLOW="${got}"
fi
kv "db.restore paths" "${ALLOW}   <- archive yahin hona chahiye"
kv "archive dir"      "${ACP_HOME}/var/s10-verify (check script ka default)"

line "mariadb"
kv "client" "$([[ -x "${CLIENT}" ]] && echo "${CLIENT}" || echo "${CLIENT} (NAHI MILA)")"
if [[ -x "${CLIENT}" ]]; then
  kv "server version" "$("${CLIENT}" -N -B -e 'SELECT VERSION()' 2>&1 | head -1)"
  kv "root socket"    "$(if [[ -n "$("${CLIENT}" -N -B -e 'SELECT 1' 2>/dev/null)" ]]; then echo 'ok (root se query chal rahi hai)'; else echo 'FAIL — MariaDB root se baat nahi ho rahi'; fi)"
fi
DBN="alphacp"
if [[ -r "${ENVFILE}" ]]; then
  DBN="$(sed -n 's/^ACP_DB_NAME=//p' "${ENVFILE}" | head -1)"; DBN="${DBN:-alphacp}"
  kv "env file" "${ENVFILE} (ok)"
else
  kv "env file" "${ENVFILE} (NAHI MILA)"
fi
kv "panel DB" "${DBN}"

line "accounts (panel DB -> asli Linux user?)"
if [[ -x "${CLIENT}" ]]; then
  ROWS="$("${CLIENT}" -N -B -e "SELECT username, status FROM ${DBN}.accounts ORDER BY id LIMIT 12" 2>&1)"
  if [[ "${ROWS}" == *ERROR* || -z "${ROWS}" ]]; then
    echo "  panel DB se account nahi mile: ${ROWS:-khaali}"
  else
    printf '  %-18s %-8s %-8s %s\n' USERNAME STATUS LINUX GECOS
    while read -r u s; do
      [[ -n "${u}" ]] || continue
      if id -u "${u}" >/dev/null 2>&1; then
        g="$(getent passwd "${u}" 2>/dev/null | cut -d: -f5)"
        if [[ "${g}" == *AlphaCP* ]]; then mark="AlPHA-OK"; else mark="(koi AlphaCP marker nahi)"; fi
        printf '  %-18s %-8s %-8s %s\n' "${u}" "${s}" "yes" "${g:-} ${mark}"
      else
        printf '  %-18s %-8s %-8s %s\n' "${u}" "${s}" "NAHI" "<- db.restore isay reject karega"
      fi
    done <<<"${ROWS}"
  fi
  echo
  echo "  pichli run se bache hue test databases:"
  LEFTOVER="$("${CLIENT}" -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%acpverify%'" 2>&1)"
  [[ -n "${LEFTOVER}" && "${LEFTOVER}" != *ERROR* ]] && echo "    ${LEFTOVER}" || echo "    koi nahi (saaf)"
fi

line "pichle db.restore tasks (agent log)"
if [[ -r "${LOG}" ]]; then
  grep -i 'db\.restore' "${LOG}" 2>/dev/null | tail -8 | sed 's/^/  /'
  echo "  (log: ${LOG})"
else
  for alt in "${ACP_HOME}/var/log/paneld.log" /var/log/alphacp/agent.log /var/log/alphacp-agent.log; do
    [[ -r "${alt}" ]] && { echo "  (${LOG} nahi mila; ${alt} se):"; grep -i 'db\.restore' "${alt}" 2>/dev/null | tail -8 | sed 's/^/  /'; break; }
  done
  echo "  koi agent log nahi mila to Task Queue ka screenshot kaafi hai."
fi

line "bacha-khucha (pichli run)"
for d in /tmp/acp-s10-check "${ACP_HOME}/var/s10-verify"; do
  [[ -e "${d}" ]] && echo "  ${d} abhi bhi maujood hai (rm -rf se saaf ho jayega)" || echo "  ${d} — nahi hai (theek)"
done

echo
echo "== END =="
exit 0
