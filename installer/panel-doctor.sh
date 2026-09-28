#!/usr/bin/env bash
# =============================================================================
#  AlphaCP panel — DOCTOR v1.7   (ek command: fix-chain + asli error + security + verdict)
# -----------------------------------------------------------------------------
#  Fix chain (har step ke BAAD panel test hota hai, 200 milte hi ruk jata hai):
#    1  runtime saaf (logs/views/sessions/config-cache) + permission heal
#    2  php-fpm audit: <8.3 wale alphacp pools disable, >=8.3 pool ensure + restart
#   2b  systemd SANDBOX check: Ubuntu/Ondrej ka php-fpm unit ProtectSystem=full lagata hai
#       -> /usr read-only -> hamara panel /usr/local/alphacp me hai -> php-fpm kuch likh hi
#       nahi paata (CLI likh leta hai!) -> har web request 500. Yahi ASLI JAD hai.
#   2c  asli write-test php-fpm ke sandbox ke ANDAR (nsenter) - pakka proof
#    3  panel hit
#    4  agar 500:  CLI probe (as alphacp, serving pool ki ini ke saath)
#                  HTTP probe (debug temporarily ON -> message nikaalo -> restore)
#    5  auto-heal:  sandbox drop-in (ReadWritePaths)  /  DB password teeno jagah sync
#                   /  sessions table migrate
#                   /  log channel stderr fallback (agar log write hi fail ho)
#   5s  SECURITY: admin ka password agar PUBLIC wala (AlphaCP@2026 — GitHub repo me likha
#       hai) hai to naya random password set (first login par phir bhi change karna padega)
#    6  cache rebuild + FINAL VERDICT
#  v1.7 fixes: artisan ab hamesha panel folder se chalta hai (pehle cwd galat hone par
#       config:clear/cache/migrate chup-chaap fail ho jaate the).
#  Kuch delete nahi hota (pools .disabled-<ts> banke rehte hain, .env backup ke saath).
# =============================================================================
set -u

PANEL_ROOT="/usr/local/alphacp/panel"
PANEL_USER="alphacp"
PANEL_PORT="8090"
ACP_HOME="/usr/local/alphacp"
LOG="/var/log/alphacp-panel-doctor.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[!]${C_0} $*"; }
err()  { say "${C_R}[x]${C_0} $*"; }
hdr()  { say ""; say "${C_B}== $* ==${C_0}"; }

[[ "${EUID}" -eq 0 ]] || { err "root chahiye: sudo bash $0"; exit 1; }
[[ -f "${PANEL_ROOT}/artisan" ]] || { err "${PANEL_ROOT} me panel nahi mila"; exit 1; }
: > "${LOG}" 2>/dev/null || true

say ""
say "${C_B}===============================================================${C_0}"
say "${C_B}   AlphaCP PANEL DOCTOR  -  v1.7   (NAYA version)${C_0}"
say "${C_B}   yahan 'v1.7' likha ho = sahi command chali hai${C_0}"
say "${C_B}===============================================================${C_0}"

PHPBIN="/usr/bin/php"
for v in 8.5 8.4 8.3; do [[ -x "/usr/bin/php${v}" ]] && { PHPBIN="/usr/bin/php${v}"; break; }; done

ver_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" == "$2" ]]; }
# v1.7: hamesha panel folder se chalao (pehle cwd /root ya /home/ubuntu hone par "artisan" milta hi nahi tha)
ASK()    { ( cd "${PANEL_ROOT}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHPBIN}" artisan "$@" ) ; }

hit() {  # panel ko hit karo, code echo
  local c=""
  c="$(curl -k -s -o /tmp/.acp.html -w '%{http_code}' -m 15 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
  printf '%s' "${c:-none}"
}

# ------------------------------------------------------------------ 1. runtime saaf + perms
hdr "Step 1: runtime saaf + permission heal"
rm -f "${PANEL_ROOT}/storage/logs/"*.log 2>/dev/null || true
rm -f "${PANEL_ROOT}/storage/framework/views/"* 2>/dev/null || true
rm -f "${PANEL_ROOT}/storage/framework/sessions/"* 2>/dev/null || true
rm -f "${PANEL_ROOT}/bootstrap/cache/"*.php 2>/dev/null || true
mkdir -p "${PANEL_ROOT}/storage/logs" "${PANEL_ROOT}/storage/framework/views" \
         "${PANEL_ROOT}/storage/framework/sessions" "${PANEL_ROOT}/storage/framework/cache/data" \
         "${PANEL_ROOT}/bootstrap/cache"
chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true
find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type d -exec chmod 0770 {} \; 2>/dev/null || true
find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type f -exec chmod 0660 {} \; 2>/dev/null || true
ok "logs/views/sessions/config-cache saaf + ${PANEL_USER} ke naam (0770/0660)"

# ------------------------------------------------------------------ 2. php-fpm audit/fix
hdr "Step 2: php-fpm audit (kaun sa PHP panel serve kar raha hai)"
mapfile -t POOLS < <(grep -rl "alphacp-fpm.sock" /etc/php/*/fpm/pool.d/ 2>/dev/null || true)

GOODV=""
for f in "${POOLS[@]:-}"; do
  [[ -n "${f}" ]] || continue
  v="$(printf '%s' "$f" | cut -d/ -f4)"
  say "   pool: php${v}  (${f})"
  if ver_ge "${v}" "8.3"; then
    [[ -z "${GOODV}" ]] || ver_ge "${v}" "${GOODV}" && GOODV="${v}"
  else
    mv "${f}" "${f}.disabled-$(date +%s)" 2>/dev/null && warn "stale pool hataya: php${v} (<8.3 — panel ko PHP 8.3+ chahiye)"
    systemctl stop "php${v}-fpm" >/dev/null 2>&1 || true
    systemctl disable "php${v}-fpm" >/dev/null 2>&1 || true
  fi
done
GOODV="${GOODV:-8.4}"

# agar kisi 8.3+ PHP me alphacp pool nahi hai to bana do
if ! ls /etc/php/*/fpm/pool.d/alphacp.conf >/dev/null 2>&1; then
  info_v="$(ls -d /etc/php/*/fpm 2>/dev/null | cut -d/ -f4 | sort -V | tail -1)"; info_v="${info_v:-${GOODV}}"
  warn "kisi PHP me alphacp pool nahi mila — php${info_v} ke liye bana raha hoon"
  SRC="${PANEL_ROOT}/deploy/php-fpm-alphacp.conf.in"
  DST="/etc/php/${info_v}/fpm/pool.d/alphacp.conf"
  if [[ -f "${SRC}" ]]; then
    sed -e "s#@@PANEL_USER@@#${PANEL_USER}#g" -e "s#@@PANEL_ROOT@@#${PANEL_ROOT}#g" -e "s#@@ACP_HOME@@#${ACP_HOME}#g" \
      "${SRC}" > "${DST}"
  else
    cat > "${DST}" <<EOF
[alphacp]
user = ${PANEL_USER}
group = ${PANEL_USER}
listen = /run/php/alphacp-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[open_basedir] = ${PANEL_ROOT}:${ACP_HOME}/etc:${ACP_HOME}/agent/config:/tmp
php_admin_value[upload_tmp_dir] = /tmp
php_admin_value[session.save_path] = ${PANEL_ROOT}/storage/framework/sessions
EOF
  fi
  GOODV="${info_v}"
fi

# serving pool ki ini (open_basedir) nikaalo
SERV_POOL="$(grep -l "alphacp-fpm.sock" /etc/php/*/fpm/pool.d/alphacp.conf 2>/dev/null | head -1)"
OB=""
[[ -n "${SERV_POOL}" ]] && OB="$(sed -n 's/.*open_basedir\][[:space:]]*=[[:space:]]*//p' "${SERV_POOL}" | head -1)"

rm -f /run/php/alphacp-fpm.sock 2>/dev/null || true
for f in /etc/php/*/fpm/pool.d/alphacp.conf; do
  [[ -f "${f}" ]] || continue
  v="$(printf '%s' "$f" | cut -d/ -f4)"
  ver_ge "${v}" "8.3" || continue
  systemctl restart "php${v}-fpm" 2>/dev/null || service "php${v}-fpm" restart 2>/dev/null || true
  sleep 2
  [[ -S /run/php/alphacp-fpm.sock ]] && ok "php${v}-fpm restart — socket ready"
done
SOCKPID="$(ss -xlp 2>/dev/null | grep -F 'alphacp-fpm.sock' | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2)"
[[ -n "${SOCKPID}" ]] && say "   socket owner: $(ps -o cmd= -p "${SOCKPID}" 2>/dev/null | head -1)"
[[ -S /run/php/alphacp-fpm.sock ]] || warn "socket nahi bana — 'systemctl status php${GOODV}-fpm' dekho"

# ------------------------------------------------------------------ 2b/2c. systemd sandbox
hdr "Step 2b: web-layer sandbox check (systemd) — php-fpm ${ACP_HOME} me likh sakta hai?"
SB_BAD=0
FPM_UNIT="php${GOODV}-fpm"
sval() { systemctl show "$1" -p "$2" --value 2>/dev/null || systemctl show "$1" -p "$2" 2>/dev/null | cut -d= -f2; }
PROT="$(sval "${FPM_UNIT}" ProtectSystem || true)"; PROT="${PROT:-no}"
RW="$(sval "${FPM_UNIT}" ReadWritePaths || true)"
PTMP="$(sval "${FPM_UNIT}" PrivateTmp || true)"
PHOME="$(sval "${FPM_UNIT}" ProtectHome || true)"
say "   ${FPM_UNIT} : ProtectSystem=${PROT:-no}  PrivateTmp=${PTMP:-no}  ProtectHome=${PHOME:-no}"
say "   ReadWritePaths : ${RW:-(none)}"
BAD=0
case "${PANEL_ROOT}" in
  /usr/*|/boot/*|/etc/*) if [[ "${PROT}" != "no" && -n "${PROT}" ]]; then BAD=1; fi ;;
  /home/*) if [[ "${PHOME}" != "no" && -n "${PHOME}" ]]; then BAD=1; fi ;;
esac
if [[ "${PROT}" == "strict" ]]; then BAD=1; fi
if (( BAD )); then
  OKRW=0
  for d in ${RW//,/ }; do
    d="${d#-}"; [[ -n "${d}" ]] || continue
    case "${PANEL_ROOT}" in "${d}"|"${d}"/*) OKRW=1 ;; esac
  done
  if (( OKRW )); then ok "sandbox: ${PANEL_ROOT} ReadWritePaths me hai (likha ja sakta hai)"
  else SB_BAD=1; warn "SANDBOX CONFIRMED: systemd ne php-fpm ke liye ${PANEL_ROOT} READ-ONLY kar diya hai"; fi
else
  ok "sandbox: panel path par koi read-only restriction nahi"
fi

hdr "Step 2c: asli write-test (php-fpm ke sandbox ke ANDAR)"
FPMPID="$(sval "${FPM_UNIT}" MainPID || true)"; FPMPID="${FPMPID:-0}"
PROBE="${PANEL_ROOT}/storage/logs/.acp-doctor-write-test"
if [[ "${FPMPID}" -gt 0 ]] && command -v nsenter >/dev/null 2>&1 && nsenter -t "${FPMPID}" -m -- true >/dev/null 2>&1; then
  if nsenter -t "${FPMPID}" -m -- runuser -u "${PANEL_USER}" -- touch "${PROBE}" 2>/tmp/.acp-probe-err; then
    rm -f "${PROBE}" 2>/dev/null || true
    ok "sandbox ke andar likh pa raha hai ✅"
  else
    SBE="$(head -1 /tmp/.acp-probe-err 2>/dev/null)"
    warn "sandbox ke andar likh NAHI pa raha -> ${SBE}"
    case "${SBE}" in *"Read-only"*) SB_BAD=1 ;; esac
  fi
  rm -f /tmp/.acp-probe-err
else
  say "   (nsenter/systemd available nahi — probe skip)"
fi
FIXNOTE=""

# ------------------------------------------------------------------ 3. hit
hdr "Step 3: panel check"
CODE="$(hit)"
if [[ "${CODE}" == "200" ]]; then ok "login page HTTP 200"; else err "login page HTTP ${CODE}"; fi

# ------------------------------------------------------------------ 4. diagnose (agar 500)
HTTP_ERR=""
if [[ "${CODE}" != "200" ]]; then
  hdr "Step 4a: CLI probe (as ${PANEL_USER}, ${PHPBIN}, serving pool ki ini ke saath)"
  cat > /tmp/acp-doc.php <<'PHP'
<?php
$root='/usr/local/alphacp/panel';
$who=function_exists('posix_getpwuid')?(posix_getpwuid(posix_geteuid())['name']??'?'):'?';
echo "user         : {$who}\nphp          : ".PHP_VERSION."\n";
echo "open_basedir : ".(ini_get('open_basedir')?:'(none)')."\n";
foreach(["$root/storage/logs","$root/storage/framework/views","$root/bootstrap/cache","$root/.env"] as $p)
  printf("%-34s writable=%s\n",str_replace($root,'~',$p),is_writable($p)?'yes':'NO');
try{
  require $root.'/vendor/autoload.php';
  $app=require_once $root.'/bootstrap/app.php';
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo "env          : ".$app->environment()." (config cached: ".(file_exists($root.'/bootstrap/cache/config.php')?'yes':'no').")\n";
  echo "log channel  : ".config('logging.default')."\n";
  \Illuminate\Support\Facades\Log::info('doctor: log write test'); echo "log write    : OK\n";
  \Illuminate\Support\Facades\DB::select('SELECT 1'); echo "db ping      : OK\n";
  echo "users table  : ".\Illuminate\Support\Facades\DB::table('users')->count()." row(s)\n";
}catch(\Throwable $e){
  echo "\n>>>>> ASLI ERROR (CLI) <<<<<\n".get_class($e)."\n".$e->getMessage()."\n  at ".$e->getFile().':'.$e->getLine()."\n";
}
PHP
  # chhoti typo-proof: $e->getLine
  if [[ -n "${OB}" ]]; then
    runuser -u "${PANEL_USER}" -- "${PHPBIN}" -d "open_basedir=${OB}" /tmp/acp-doc.php 2>&1 | sed 's/^/   /' | tee -a "${LOG}"
  else
    runuser -u "${PANEL_USER}" -- "${PHPBIN}" /tmp/acp-doc.php 2>&1 | sed 's/^/   /' | tee -a "${LOG}"
  fi
  rm -f /tmp/acp-doc.php

  hdr "Step 4b: HTTP-level asli error (debug temporarily ON -> phir restore)"
  ENV_BAK="/tmp/.acp-env-bak.$$"; cp -a "${PANEL_ROOT}/.env" "${ENV_BAK}"
  sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' "${PANEL_ROOT}/.env"
  ASK config:clear >>"${LOG}" 2>&1 || true
  sleep 1
  curl -k -s --max-time 25 "https://127.0.0.1:${PANEL_PORT}/" -o /tmp/.acp-debug.html 2>/dev/null || true
  if [[ -s /tmp/.acp-debug.html ]]; then
    { grep -oE '<title>[^<]*</title>' /tmp/.acp-debug.html | head -1
      grep -oE '"(message|exception)":"[^"]{0,240}"' /tmp/.acp-debug.html | head -4
      sed -e 's/<[^>]*>/\n/g' /tmp/.acp-debug.html \
        | grep -E 'ErrorException|Exception:|Failed to open|Permission denied|No such file|open_basedir|not allowed|SQLSTATE|undefined|Undefined' \
        | awk '!seen[$0]++' | head -5
    } | cut -c1-220 | sed 's/^/   /' | tee -a "${LOG}"
    HTTP_ERR="$(cat /tmp/.acp-debug.html)"
  else
    say "   (debug page khaali aayi)"
  fi
  cp -a "${ENV_BAK}" "${PANEL_ROOT}/.env"; rm -f "${ENV_BAK}"
  chown "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/.env"; chmod 0640 "${PANEL_ROOT}/.env"
  ASK config:clear >>"${LOG}" 2>&1 || true
  systemctl restart "php${GOODV}-fpm" >>"${LOG}" 2>&1 || true
  rm -f /tmp/.acp-debug.html
  ok ".env restore (APP_DEBUG=false), fpm restart"

  hdr "Step 4c: laravel + fpm logs"
  LLOG="$(ls -t "${PANEL_ROOT}/storage/logs/"laravel*.log 2>/dev/null | head -1)"
  if [[ -n "${LLOG}" ]]; then
    say "   ${LLOG}"
    tail -5 "${LLOG}" | tr -s ' ' | cut -c1-220 | sed 's/^/   /'
  else
    say "   (koi laravel log file nahi — app log likh hi nahi paya)"
    say "   php-fpm log (aakhri 5):"
    tail -5 "/var/log/php${GOODV}-fpm.log" 2>/dev/null | cut -c1-200 | sed 's/^/   /'
  fi
  say "   nginx error log (PHP message wali lines):"
  grep -E "PHP message|FastCGI sent in stderr" /var/log/nginx/error.log 2>/dev/null | tail -2 | cut -c1-260 | sed 's/^/   /' | tee -a "${LOG}"
  REASON="$(grep -oE "Read-only file system|Permission denied|No space left on device|Failed to open stream|No such file or directory|open_basedir" /var/log/nginx/error.log 2>/dev/null | sort | uniq -c | sort -rn | head -3)"
  if [[ -n "${REASON}" ]]; then say "   wajah (tokens):"; printf '%s\n' "${REASON}" | sed 's/^/   /'; fi
fi

# ------------------------------------------------------------------ 5. auto-heal chain
if [[ "${CODE}" != "200" ]]; then
  hdr "Step 5: auto-heal"
  HEALED=0

  # 5a-fallback: agar error khud keh raha ho ki likhne par roka gaya -> sandbox fix chalao
  if [[ "${HTTP_ERR}" == *"tempnam"* || "${HTTP_ERR}" == *"Read-only file system"* || "${HTTP_ERR}" == *"ro file system"* ]]; then
    SB_BAD=1
  fi

  # 5a systemd sandbox -> ReadWritePaths drop-in (ubuntu/ondrej unit ki asli jad)
  if (( SB_BAD )); then
    warn "sandbox fix: ReadWritePaths=${ACP_HOME} ka drop-in laga raha hoon (${FPM_UNIT})"
    install -d "/etc/systemd/system/${FPM_UNIT}.service.d" 2>/dev/null || true
    cat > "/etc/systemd/system/${FPM_UNIT}.service.d/alphacp-panel.conf" <<EOF
# AlphaCP panel: php-fpm workers ko ${ACP_HOME} me likhne do
# (Ubuntu/Ondrej ka unit ProtectSystem=full lagata hai -> /usr read-only -> 500)
[Service]
ReadWritePaths=-${ACP_HOME}
ReadWritePaths=-/run/php
EOF
    if systemctl daemon-reload >>"${LOG}" 2>&1 && systemctl restart "${FPM_UNIT}" >>"${LOG}" 2>&1; then
      sleep 2
      if [[ "$(hit)" == "200" ]]; then
        ok "sandbox fix CHAL GAYA — drop-in: /etc/systemd/system/${FPM_UNIT}.service.d/alphacp-panel.conf"
        FIXNOTE="systemd sandbox (ProtectSystem) — drop-in laga diya (permanent, reboot-safe)"
      else
        warn "drop-in laga par abhi bhi 500 — aage ke heals dekhte hain"
        FIXNOTE="sandbox drop-in laga (par abhi bhi 500)"
      fi
      HEALED=1; SB_BAD=0
    else
      warn "systemd daemon-reload/restart fail"
    fi
  fi

  # 5a DB access (panel.env + database.env + MariaDB user — teeno sync)
  if [[ "${HTTP_ERR}" == *"Access denied for user"* ]]; then
    DBENV="${ACP_HOME}/etc/database.env"; PENV="${ACP_HOME}/etc/panel.env"
    U="$(grep -E '^ACP_DB_USER=' "${DBENV}" 2>/dev/null | cut -d= -f2-)"; U="${U:-alphacp}"
    N="$(grep -E '^ACP_DB_NAME=' "${DBENV}" 2>/dev/null | cut -d= -f2-)"; N="${N:-alphacp}"
    P="$(grep -E '^ACP_DB_PASS=' "${DBENV}" 2>/dev/null | cut -d= -f2-)"
    if [[ -n "${P}" ]]; then
      warn "DB access denied — password teeno jagah sync kar raha hoon"
      if [[ -f "${PENV}" ]]; then cp -a "${PENV}" "${PENV}.bak.$(date +%s)" 2>/dev/null || true; sed -i "s|^ACP_DB_PASS=.*|ACP_DB_PASS=${P}|" "${PENV}"
      else printf 'ACP_DB_HOST=127.0.0.1\nACP_DB_PORT=3306\nACP_DB_NAME=%s\nACP_DB_USER=%s\nACP_DB_PASS=%s\n' "${N}" "${U}" "${P}" > "${PENV}"; fi
      chown root:"${PANEL_USER}" "${PENV}" 2>/dev/null || true; chmod 0640 "${PENV}" 2>/dev/null || true
      mysql -e "CREATE USER IF NOT EXISTS '${U}'@'localhost' IDENTIFIED BY '${P}'; ALTER USER '${U}'@'localhost' IDENTIFIED BY '${P}';
                CREATE USER IF NOT EXISTS '${U}'@'127.0.0.1' IDENTIFIED BY '${P}'; ALTER USER '${U}'@'127.0.0.1' IDENTIFIED BY '${P}';
                GRANT ALL PRIVILEGES ON \`${N}\`.* TO '${U}'@'localhost'; GRANT ALL PRIVILEGES ON \`${N}\`.* TO '${U}'@'127.0.0.1'; FLUSH PRIVILEGES;" >>"${LOG}" 2>&1 \
        && { ok "panel.env + MariaDB user sync"; HEALED=1; } || warn "DB sync fail"
      rm -f "${PANEL_ROOT}/bootstrap/cache/config.php" 2>/dev/null || true
    fi
  fi

  # 5b sessions table missing
  if [[ "${HTTP_ERR}" == *"sessions"* && "${HTTP_ERR}" != *"Access denied"* && ( "${HTTP_ERR}" == *"doesn't exist"* || "${HTTP_ERR}" == *"Base table"* ) ]]; then
    warn "sessions table missing — migrate chala raha hoon"
    ASK migrate --force >>"${LOG}" 2>&1 && { ok "migrate done"; HEALED=1; } || warn "migrate fail"
  fi

  # 5c log write hi fail ho raha ho -> stderr logging par shift karo
  if [[ "${HTTP_ERR}" == *"Failed to open"* || "${HTTP_ERR}" == *"Permission denied"* || "${HTTP_ERR}" == *"logs/laravel"* ]]; then
    warn "app apni log file likh nahi paa raha — LOG_CHANNEL=stderr par shift kar raha hoon"
    cp -a "${PANEL_ROOT}/.env" "${PANEL_ROOT}/.env.bak.$(date +%s)" 2>/dev/null || true
    if grep -q '^LOG_CHANNEL=' "${PANEL_ROOT}/.env"; then
      sed -i 's/^LOG_CHANNEL=.*/LOG_CHANNEL=stderr/' "${PANEL_ROOT}/.env"
    else
      printf '\nLOG_CHANNEL=stderr\n' >> "${PANEL_ROOT}/.env"
    fi
    ASK config:clear >>"${LOG}" 2>&1 || true
    HEALED=1
  fi

  if (( HEALED )); then
    chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true
    systemctl restart "php${GOODV}-fpm" >>"${LOG}" 2>&1 || true
    sleep 2
    CODE="$(hit)"
    if [[ "${CODE}" == "200" ]]; then ok "auto-heal ke baad login page HTTP 200 🎉"; else err "auto-heal ke baad bhi HTTP ${CODE}"; fi
  else
    say "   (koi known auto-fix match nahi hua)"
  fi
fi

# ------------------------------------------------------------------ 5s. security: public password
# AlphaCP@2026 GitHub repo (public) ki AI-HANDOFF.md me likha hai. Panel chalte hi koi bhi
# https://<ip>:8090 par usse login kar sakta hai -> isliye agar admin ka password abhi bhi
# wahi hai to naya random password set karte hain. Agar aap khud password badal chuke ho
# (SAFE) to kuch nahi chhedte — doctor dobara chalane par bhi password nahi badlega.
NEWPW=""
if [[ "${CODE}" == "200" ]]; then
  hdr "Step 5s: security — admin ka password PUBLIC wala (repo me likha hua) to nahi?"
  PWCHK="/tmp/acp-pwcheck.$$.php"
  cat > "${PWCHK}" <<'PHP'
<?php
$root = getenv('ACP_PANEL_ROOT') ?: '/usr/local/alphacp/panel';
$user = getenv('ACP_CHECK_USER') ?: 'admin';
try {
    require $root . '/vendor/autoload.php';
    $app = require_once $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $hash = (string) \Illuminate\Support\Facades\DB::table('users')->where('username', $user)->value('password_hash');
    if ($hash === '') { echo "NOUSER\n"; exit(0); }
    foreach (['AlphaCP@2026'] as $leaked) {
        if (password_verify($leaked, $hash)) { echo "LEAKED\n"; exit(0); }
    }
    echo "SAFE\n";
} catch (\Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
PHP
  chmod 0644 "${PWCHK}"
  PWSTATE="$( ( cd "${PANEL_ROOT}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" ACP_PANEL_ROOT="${PANEL_ROOT}" "${PHPBIN}" "${PWCHK}" ) 2>&1 | tail -1 )"
  rm -f "${PWCHK}"
  case "${PWSTATE}" in
    SAFE)   ok "admin ka password public wala NAHI hai — kuch nahi badla" ;;
    NOUSER) warn "'admin' user DB me nahi mila — password check skip" ;;
    LEAKED)
      warn "admin ka password abhi bhi PUBLIC wala hai — naya random password set kar raha hoon"
      # 14 random + 'Ac7' = upper + lower + digit pakka (panel policy: min 10, mixed case, number)
      NEWPW="$(tr -dc 'A-Za-z0-9' </dev/urandom 2>/dev/null | head -c 14)Ac7"
      if [[ "${#NEWPW}" -ge 17 ]] && ASK alphacp:admin-password admin --password="${NEWPW}" --force-change >>"${LOG}" 2>&1; then
        STATE_DIR="${ACP_HOME}/var"; mkdir -p "${STATE_DIR}"
        ( umask 077
          printf 'panel_user=%s\npanel_pass=%s\npanel_port=%s\nupdated_at=%s\n' \
            "admin" "${NEWPW}" "${PANEL_PORT}" "$(date -Is)" > "${STATE_DIR}/panel-admin.txt" )
        chmod 0600 "${STATE_DIR}/panel-admin.txt" 2>/dev/null || true
        # installer re-run is .env value ko "purana password" maanta hai — use bhi sync karo
        if grep -q '^ACP_ADMIN_PASSWORD=' "${PANEL_ROOT}/.env" 2>/dev/null; then
          sed -i "s|^ACP_ADMIN_PASSWORD=.*|ACP_ADMIN_PASSWORD=${NEWPW}|" "${PANEL_ROOT}/.env"
          chown "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/.env" 2>/dev/null || true
          chmod 0640 "${PANEL_ROOT}/.env" 2>/dev/null || true
        fi
        if [[ -f /root/.alphacp-admin-credentials ]]; then
          sed -i "s|^Password : .*|Password : ${NEWPW}|" /root/.alphacp-admin-credentials 2>/dev/null || true
          chmod 0600 /root/.alphacp-admin-credentials 2>/dev/null || true
        fi
        ok "naya admin password set (neeche VERDICT me dikhega; file: ${STATE_DIR}/panel-admin.txt)"
      else
        NEWPW=""
        warn "password badal nahi paya — log: ${LOG}  (login ke turant baad khud badal dena!)"
      fi
      ;;
    *) warn "password check nahi ho paya: ${PWSTATE}" ;;
  esac
fi

# ------------------------------------------------------------------ 6. cache rebuild + verdict
if [[ "${CODE}" == "200" ]]; then
  hdr "Step 6: cache rebuild"
  ASK config:cache >>"${LOG}" 2>&1 || true
  ASK route:cache  >>"${LOG}" 2>&1 || true
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true
  CODE="$(hit)"
  [[ "${CODE}" == "200" ]] && ok "cache rebuild ke baad bhi HTTP 200" || warn "cache rebuild ke baad HTTP ${CODE}"
fi

IP="$(curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')"
hdr "VERDICT"
if [[ "${CODE}" == "200" ]]; then
  say "${C_G}${C_B}==> PANEL READY ✅ — theek ho gaya${C_0}"
  say "    URL      : https://${IP}:${PANEL_PORT}/"
  say "    Username : admin"
  if [[ -n "${NEWPW}" ]]; then
    say "    Password : ${C_B}${NEWPW}${C_0}   (NAYA — purana public wala ab kaam nahi karega)"
  else
    say "    Password : sudo cat ${ACP_HOME}/var/panel-admin.txt"
  fi
  say "    Login ke baad panel naya password rakhne ko kahega — apna strong password rakho."
  say ""
  if [[ -n "${FIXNOTE}" ]]; then say "    Fix     : ${FIXNOTE}"; fi
  say "    Ab browser me reload karo (cert warning -> Advanced -> Proceed)."
else
  say "${C_R}${C_B}==> PANEL: HTTP ${CODE} ❌ — upar 'ASLI ERROR' wali 5-6 lines bhej do${C_0}"
  say "    Full report: ${LOG}"
fi
say "    — panel-doctor v1.7"
say ""
