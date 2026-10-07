#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — LOGIN FIX  v1.0
#  "Login page khulta hai, credentials daalne par login nahi hota, error aata hai"
#  — is poori failure-chain ka diagnose + fix + PROOF ek hi command me.
# -----------------------------------------------------------------------------
#  Sandbox me live server ke exact panel code (0.75.0) par reproduce kiye gaye
#  5 asli bug, sab yahan fix hote hain:
#
#   B1  ENTRY-GATE LOCKOUT (sabse common)
#       EntryLoginController v1 `ports.json` (owner ki /ports screen ki ICHHA)
#       padhta tha, nginx kya ASLI me listen kar raha hai wo nahi. Server par
#       nginx sirf 8090 par sunta hai (vhost ka ACP_PORTS block khaali hai aur
#       koi apply-step maujood hi nahi) — par ports.json me 2083 likha ho to
#       user/mail role ka har login 8090 par reject:
#         "Account Panel login 2083 par hota hai — https://<host>:2083 kholein."
#       aur 2083 kholne par connection refused. => permanent lockout.
#       PROOF: isi wajah se panel ka apna tests/Feature/AuthTest.php fail hota hai
#       ("Valid credentials reach the dashboard" -> wahi error message).
#   B2  PROXY/CDN par sab customer lock: trustProxies(at:'*') ki wajah se
#       X-Forwarded-Port: 443 par getPort()=443 -> "known entry" nahi, par v1 ka
#       rule `!onCustomer` tha -> customer deny. Fail-open hona chahiye tha.
#   B3  2FA REDIRECT LOOP: session me `two_factor_passed` kho jaye (purana
#       session / 2FA baad me off) aur account par 2FA enabled na ho to
#       /dashboard <-> /two-factor infinite loop -> browser "Too many redirects".
#   B4  GET /login = 404 (login page sirf "/" par hai) — bookmark/WHMCS link
#       tootte hain. Saath me: baar-baar fail hone par account `locked_until`
#       me chala jata hai ("Account is locked for a short time").
#   B5  **ASLI WAJAH — ResellerScopeProvider ka FATAL RECURSION.**
#       v1 har global scope ke andar seedha `Auth::user()` call karta tha.
#       Laravel ka SessionGuard::user() apna `$this->user` **retrieveById()
#       RETURN hone ke BAAD** set karta hai, aur EloquentUserProvider::
#       retrieveById() `newQuery()` se query banata hai — matlab GLOBAL SCOPES
#       ke saath. Isliye har authenticated request me:
#          Auth::user() -> SessionGuard::user() -> retrieveById($id)
#            -> User query -> reseller_scope -> Auth::user() -> ... INFINITE
#       = PHP fatal ("Allowed memory size exhausted" / max nesting) = HTTP 500.
#       Login POST to 302 de deta tha, phir /dashboard par 500 — user ko yahi
#       dikhta tha: "login page khulta hai, credentials daalne par login nahi
#       hota, error aata hai."
#       Panel ke tests isko PAKAD NAHI paate the kyunki `actingAs()` guard par
#       user seedha set kar deta hai (retrieveById chalta hi nahi). Reproduce:
#       SessionAuthTest ka freshRequest() (guards bhula do) — v1 par 606,955
#       stack frames tak jaata hai. Fix: `actor()` recursion-breaker flag.
#
#  Fix chain (har step idempotent; pehle backup; har PHP file swap se PEHLE lint):
#    1  DIAGNOSE (read-only) — asli error screen/log/DB se nikaalo
#    2  LIVE LOGIN PROBE — server se hi asli browser jaisa GET / -> POST /login
#    3  unlock: locked_until/failed_logins reset + login_attempts + rate-limit saaf
#    4  EntryLoginController v2 (truth-based, fail-open, crash-proof)
#    4b ResellerScopeProvider v2 (B5 recursion-breaker) — SABSE ZAROORI FIX
#    5  EnsureTwoFactorIsVerified v2 + TwoFactorController loop-breaker
#    6  routes: GET /login alias
#    7  acp-entry-ports truth generator + cron (nginx ke ASLI ports se)
#    8  permission heal (runtime user = fpm pool user, write-test guard ke saath)
#       + optimize:clear + php-fpm restart (opcache)
#    9  SELFTEST — decide() truth table + routes + B5 recursion check
#   10  LIVE LOGIN PROBE dobara -> FINAL VERDICT
#
#  Usage:
#    sudo bash login-fix.sh                    # diagnose + fix + verify
#    sudo bash login-fix.sh --diagnose         # sirf report, kuch badalta nahi
#    sudo bash login-fix.sh --enable-ports     # upar wala + nginx par 2083/2087/2096
#                                              #   ASLI me listen karwao (separation live)
#    sudo bash login-fix.sh --rollback         # is script ke pichle backup par wapas
#    ACP_FIX_USER=root ACP_FIX_PASS='...' sudo -E bash login-fix.sh   # non-interactive
#
#  Kuch delete nahi hota: har badlav <releases>/loginfix-<ts>/ me backup hota hai.
# =============================================================================
set -uo pipefail

FIX_VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL="${ACP_HOME}/panel"
PRIMARY_PORT="${ACP_PRIMARY_PORT:-8090}"
ENTRY_FILE="${ACP_HOME}/etc/entry-ports.json"
BIN_ENTRY="${ACP_HOME}/bin/acp-entry-ports"
CRON_ENTRY="/etc/cron.d/alphacp-entry-ports"
TS="$(date -u +%Y%m%d%H%M%S)"
BK="${ACP_HOME}/releases/loginfix-${TS}"
LOGDIR="${ACP_HOME}/logs"
REPORT="${LOGDIR}/login-fix-${TS}.txt"

MODE="fix"
ENABLE_PORTS=0
for a in "$@"; do
  case "$a" in
    --diagnose)     MODE="diagnose" ;;
    --enable-ports) MODE="fix"; ENABLE_PORTS=1 ;;
    --rollback)     MODE="rollback" ;;
    --selftest)     MODE="selftest" ;;
    --version)      echo "login-fix v${FIX_VERSION}"; exit 0 ;;
    -h|--help)      sed -n '2,50p' "$0"; exit 0 ;;
    *) echo "unknown option: $a  (use: --diagnose | --enable-ports | --rollback | --selftest)" >&2; exit 2 ;;
  esac
done

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[!]${C_0} $*"; }
err()  { say "${C_R}[x]${C_0} $*"; }
hdr()  { say ""; say "${C_B}== $* ==${C_0}"; }
tee_report() { tee -a "${REPORT}" 2>/dev/null || cat; }

[[ "${EUID}" -eq 0 ]] || { err "root chahiye:  sudo bash $0"; exit 1; }
mkdir -p "${LOGDIR}" 2>/dev/null || true
: > "${REPORT}" 2>/dev/null || REPORT="/tmp/login-fix-${TS}.txt"

say ""
say "${C_B}===============================================================${C_0}"
say "${C_B}   AlphaCP LOGIN FIX  -  v${FIX_VERSION}${C_0}"
say "${C_B}   yahan 'v${FIX_VERSION}' likha ho = sahi command chali hai${C_0}"
say "${C_B}===============================================================${C_0}"

# ------------------------------------------------------------------ preflight
[[ -f "${PANEL}/artisan" ]] || { err "${PANEL} me panel nahi mila"; exit 1; }
# Panel ka RUNTIME user = php-fpm pool ka user — YAHI storage/bootstrap ka owner
# hona chahiye. artisan/panel files ke OWNER se detect karna DHOKA hai (deploy
# aksar root se hota hai): tab Step 8 storage ko root:root 0770/0660 kar deta aur
# fpm (alphacp) compiled views / file-cache / log PADH bhi nahi pata -> har page
# 500. (Live server par 7 Oct ko yahi pakda gaya: diagnose "user: root" bola,
# jabki pool alphacp tha.) Tarkeeb: env override -> chalte fpm workers -> pool
# conf -> storage/logs owner -> artisan owner -> alphacp -> root.
detect_pool_user() { # pool conf = declarative sach (restart ke baad yahi chalega)
  local pool u
  for pool in /etc/php/*/fpm/pool.d/*.conf; do
    [[ -f "${pool}" ]] || continue
    grep -q "${ACP_HOME##*/}\|${PANEL}" "${pool}" 2>/dev/null || continue   # www.conf se bacho
    u="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/ {gsub(/[[:space:]]/,"",$2); print $2; exit}' "${pool}" 2>/dev/null)"
    [[ -n "${u}" ]] && { printf '%s' "${u}"; return 0; }
  done
  return 1
}
PANEL_USER="${ACP_PANEL_USER:-}"; PANEL_USER_SRC="env ACP_PANEL_USER"
if [[ -z "${PANEL_USER}" ]] && POOL_U="$(detect_pool_user)"; then
  PANEL_USER="${POOL_U}"; PANEL_USER_SRC="fpm pool conf"
fi
if [[ -z "${PANEL_USER}" ]]; then
  # chalte workers ka title: "php-fpm[8.4]: pool <name>". NOTE: awk ka APNA cmdline
  # bhi pattern rakhta hai, isliye $2 (comm) se filter karo — warna self-match root dega.
  PANEL_USER="$(ps -eo user=,comm=,args= 2>/dev/null | awk '$2 ~ /^php-fpm/ && index($0, ": pool ") {print $1; exit}')"
  PANEL_USER_SRC="fpm worker process"
fi
if [[ -z "${PANEL_USER}" ]]; then PANEL_USER="$(stat -c '%U' "${PANEL}/storage/logs" 2>/dev/null | head -1)"; PANEL_USER_SRC="storage/logs owner"; fi
if [[ -z "${PANEL_USER}" ]]; then PANEL_USER="$(stat -c '%U' "${PANEL}/artisan" 2>/dev/null | head -1)"; PANEL_USER_SRC="artisan owner"; fi
if [[ -z "${PANEL_USER}" ]]; then PANEL_USER="alphacp"; PANEL_USER_SRC="default alphacp"; fi
id -u "${PANEL_USER}" >/dev/null 2>&1 || { PANEL_USER="root"; PANEL_USER_SRC="fallback root"; }

PHPBIN="/usr/bin/php"
for v in 8.5 8.4 8.3; do [[ -x "/usr/bin/php${v}" ]] && { PHPBIN="/usr/bin/php${v}"; break; }; done

ASK() { ( cd "${PANEL}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHPBIN}" artisan "$@" ) ; }
WORK="$(mktemp -d /tmp/acp-loginfix.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT
chmod 0755 "${WORK}" 2>/dev/null || true

# panel-user ke naam se ek PHP file chalao (CLI, open_basedir laagu nahi hota)
runphp() { # runphp <file> [args...]
  local f="$1"; shift
  cp "$f" "${WORK}/x.php"; chmod 0644 "${WORK}/x.php"; chown "${PANEL_USER}" "${WORK}/x.php" 2>/dev/null || true
  # memory_limit cap: B5 recursion check me agar bug abhi bhi ho to PHP jaldi fatal
  # de (warna box ki saari RAM kha jayega). Sirf is CLI call par lagta hai —
  # php-fpm ke pool settings ko haath nahi lagta.
  ( cd "${PANEL}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" \
      "${PHPBIN}" -d memory_limit="${ACP_FIX_PHP_MEM:-256M}" "${WORK}/x.php" )
}

# PHP file likho + lint karo; lint fail ho to KUCH bhi swap mat karo
lint_php() { "${PHPBIN}" -l "$1" >/dev/null 2>&1; }

hit() { curl -k -s -o /dev/null -w '%{http_code}' -m 15 "https://127.0.0.1:${PRIMARY_PORT}/" 2>/dev/null || echo "none"; }

backup_file() { # backup_file <path>
  local f="$1"; [[ -f "$f" ]] || return 0
  local rel="${f#/}"; mkdir -p "${BK}/$(dirname "${rel}")" 2>/dev/null || true
  cp -a "$f" "${BK}/${rel}" 2>/dev/null || true
}

# ------------------------------------------------------------------ rollback
if [[ "${MODE}" == "rollback" ]]; then
  hdr "ROLLBACK"
  LAST="$(ls -1d "${ACP_HOME}"/releases/loginfix-* 2>/dev/null | sort | tail -1)"
  [[ -n "${LAST}" ]] || { err "koi loginfix backup nahi mila (${ACP_HOME}/releases/)"; exit 1; }
  say "backup: ${LAST}"
  ( cd "${LAST}" && find . -type f -print0 ) | while IFS= read -r -d '' rel; do
    rel="${rel#./}"; dst="/${rel}"
    if [[ -f "${dst}" ]]; then cp -a "${LAST}/${rel}" "${dst}" && ok "restore ${dst}"; else warn "skip ${dst} (ab maujood nahi)"; fi
  done
  ASK optimize:clear >/dev/null 2>&1 || true
  systemctl restart php8.4-fpm >/dev/null 2>&1 || systemctl restart php-fpm >/dev/null 2>&1 || true
  sleep 2; ok "panel HTTP $(hit)"
  exit 0
fi

mkdir -p "${BK}" 2>/dev/null || true

# =========================================================== STEP 1: DIAGNOSE
hdr "Step 1: DIAGNOSE (read-only — asli wajah yahan dikhegi)"
{
say "panel root      : ${PANEL}"
say "runtime user    : ${PANEL_USER}  [${PANEL_USER_SRC}]   (artisan owner: $(stat -c '%U' "${PANEL}/artisan" 2>/dev/null || echo '?'))"
say "                  ^ fpm isi user se chalta hai; storage/bootstrap isi ka hona chahiye"
say "panel version   : $(grep -o '"version":[^,]*' "${PANEL}/MANIFEST.json" 2>/dev/null | head -1 | tr -d '" ' || echo '?')"
say "php (fpm/cli)   : ${PHPBIN}  $(${PHPBIN} -r 'echo PHP_VERSION;' 2>/dev/null)"
say "panel HTTP      : $(hit)"
say ""
say "--- nginx: sach me kaun se SSL ports LISTEN kar rahe hain ---"
if command -v ss >/dev/null 2>&1; then ss -Hltn 2>/dev/null | awk '{print $4}' | grep -oE '[0-9]+$' | sort -un | tr '\n' ' '; echo; else warn "ss nahi mila"; fi
NGXCONF=""
for c in /etc/nginx/sites-available/alphacp-panel.conf /etc/nginx/sites-enabled/alphacp-panel.conf /etc/nginx/alpha/nginx.conf; do
  [[ -f "$c" ]] && { NGXCONF="$c"; break; }
done
say "nginx vhost     : ${NGXCONF:-?}"
if [[ -n "${NGXCONF}" ]]; then
  say "  listen lines  : $(grep -E '^[[:space:]]*listen' "${NGXCONF}" | tr -s ' ' | tr '\n' ';')"
  if grep -q 'ACP_PORTS_START' "${NGXCONF}"; then
    inside="$(awk '/ACP_PORTS_START/{f=1;next}/ACP_PORTS_END/{f=0}f' "${NGXCONF}" | grep -c 'listen' || true)"
    say "  ACP_PORTS block: ${inside} listen line(s)  $( [[ "${inside:-0}" == "0" ]] && echo '<-- KHAALI: 2083/2087/2096 par nginx sunta hi NAHI' )"
  else
    say "  ACP_PORTS block: markers hi nahi hain"
  fi
fi
say ""
say "--- entry config files ---"
for f in "${ACP_HOME}/etc/entry-ports.json" "${ACP_HOME}/etc/ports.json" "${ACP_HOME}/var/ports.json"; do
  if [[ -f "$f" ]]; then say "  HAI  $f : $(tr -d '\n' < "$f" | cut -c1-200)"; else say "  nahi $f"; fi
done
say ""
say "--- login routes: kaunsa controller handle karta hai ---"
( cd "${PANEL}" && grep -n "EntryLoginController\|Auth\\\\LoginController" routes/web.php | head -5 ) || true
ASK route:list --path=login 2>/dev/null | head -10 || warn "route:list nahi chala"
say ""
say "--- PHP syntax (auth files) ---"
for f in app/Http/Controllers/Auth/EntryLoginController.php app/Http/Controllers/Auth/LoginController.php \
         app/Http/Controllers/Auth/TwoFactorController.php app/Http/Middleware/EnsureTwoFactorIsVerified.php \
         routes/web.php bootstrap/app.php; do
  if [[ -f "${PANEL}/$f" ]]; then
    if "${PHPBIN}" -l "${PANEL}/$f" >/dev/null 2>&1; then say "  OK   $f"; else say "  FAIL $f"; "${PHPBIN}" -l "${PANEL}/$f" 2>&1 | head -3; fi
  fi
done
say ""
say "--- kaun se bug ABHI maujood hain (B1..B5) ---"
F_CTRL="${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php"
F_MW="${PANEL}/app/Http/Middleware/EnsureTwoFactorIsVerified.php"
F_TFC="${PANEL}/app/Http/Controllers/Auth/TwoFactorController.php"
F_RSP="${PANEL}/app/Providers/ResellerScopeProvider.php"
F_RT="${PANEL}/routes/web.php"
b1=0; { [[ -f "${F_CTRL}" ]] && ! grep -q "public static function decide" "${F_CTRL}"; } && b1=1
b3=0; { [[ -f "${F_MW}" ]]   && ! grep -q "LOOP FIX" "${F_MW}"; } && b3=1
b3b=0; { [[ -f "${F_TFC}" ]] && ! grep -q "loop-breaker" "${F_TFC}"; } && b3b=1
b4=0; { [[ -f "${F_RT}" ]]   && ! grep -qF "name('login.page')" "${F_RT}"; } && b4=1
b5=0; { [[ -f "${F_RSP}" ]]  && ! grep -q "resolvingActor" "${F_RSP}"; } && b5=1
mark_bug() { # mark_bug <flag> <label>
  if [[ "$1" -eq 1 ]]; then say "  ${C_R}BUG${C_0}  $2"; else say "  ${C_G}OK${C_0}   $2"; fi
}
mark_bug ${b1}  "B1/B2 EntryLoginController v1 — ports.json par gate (customer lockout, fail-closed)"
mark_bug ${b3}  "B3    2FA middleware me self-heal nahi (redirect loop)"
mark_bug ${b3b} "B3b   TwoFactorController me loop-breaker nahi"
mark_bug ${b4}  "B4    GET /login alias route nahi (404)"
mark_bug ${b5}  "B5    ResellerScopeProvider v1 — scope me Auth::user() = FATAL recursion (har authenticated page 500)"
[[ -f "${F_RSP}" ]] || say "       (ResellerScopeProvider.php mila hi nahi — B5 laagu nahi)"
say ""
say "--- runtime ownership (root-owned file = php-fpm likh nahi paata = 500) ---"
for d in storage bootstrap/cache; do
  bad="$(find "${PANEL}/${d}" ! -user "${PANEL_USER}" 2>/dev/null | head -5)"
  if [[ -n "${bad}" ]]; then say "  GALAT owner (${d}):"; printf '%s\n' "${bad}" | sed 's/^/      /'; else say "  OK   ${d} sab ${PANEL_USER} ka"; fi
done
say ""
say "--- session / cache driver ---"
grep -E '^(SESSION_DRIVER|CACHE_STORE|APP_ENV|APP_DEBUG|SESSION_SECURE_COOKIE|SESSION_DOMAIN)=' "${PANEL}/.env" 2>/dev/null | sed 's/^/  /' || say "  (.env padha nahi gaya)"
} 2>&1 | tee_report

# ---- DB state (users ka lock/2fa/role) — PHP se, credentials chhue bina -----
cat > "${WORK}/dbprobe.php" <<PHPEOF
<?php
declare(strict_types=1);
require '${PANEL}/vendor/autoload.php';
\$app = require '${PANEL}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- panel users (role / status / lock / 2fa) ---\n";
try {
    \$rows = DB::table('users')->join('roles','roles.id','=','users.role_id')
        ->orderBy('roles.level')->orderBy('users.id')
        ->get(['users.id','users.username','roles.name as role','roles.level','users.status',
               'users.locked_until','users.failed_logins','users.two_factor_enabled',
               'users.force_password_change','users.last_login_at']);
    foreach (\$rows as \$r) {
        \$flag = [];
        if (\$r->locked_until !== null && strtotime((string)\$r->locked_until) > time()) { \$flag[] = 'LOCKED'; }
        if ((int)\$r->failed_logins > 0)  { \$flag[] = 'failed='.\$r->failed_logins; }
        if ((int)\$r->two_factor_enabled) { \$flag[] = '2FA'; }
        if ((int)\$r->force_password_change) { \$flag[] = 'FORCE-PW-CHANGE'; }
        if (\$r->status !== 'active')     { \$flag[] = 'status='.\$r->status; }
        printf("  #%-3s %-20s role=%-9s level=%s  last=%s  %s\n",
            \$r->id, \$r->username, \$r->role, \$r->level, \$r->last_login_at ?: '-', implode(' ', \$flag) ?: '-');
    }
    if (\$rows->isEmpty()) { echo "  (koi user nahi!)\n"; }
} catch (\Throwable \$e) { echo '  users query FAIL: '.\$e->getMessage()."\n"; }

echo "--- aakhri 8 login_attempts ---\n";
try {
    foreach (DB::table('login_attempts')->orderByDesc('id')->limit(8)->get() as \$a) {
        printf("  %s  %-16s success=%s reason=%s ip=%s\n",
            \$a->created_at, (string)\$a->username, (int)\$a->success, (string)(\$a->reason ?? '-'), \$a->ip);
    }
} catch (\Throwable \$e) { echo '  login_attempts FAIL: '.\$e->getMessage()."\n"; }

echo "--- aakhri 8 auth.* audit entries ---\n";
try {
    foreach (DB::table('audit_logs')->where('action','like','auth.%')->orderByDesc('id')->limit(8)->get() as \$a) {
        printf("  %s  %-26s %-8s %s\n", \$a->created_at, \$a->action, \$a->severity, (string)(\$a->meta ?? ''));
    }
} catch (\Throwable \$e) { echo '  audit_logs FAIL: '.\$e->getMessage()."\n"; }
PHPEOF
runphp "${WORK}/dbprobe.php" 2>&1 | tee_report

{
say ""
say "--- laravel.log (aakhri 25 ERROR/EXCEPTION lines) ---"
for lg in "${PANEL}/storage/logs/laravel.log" "${PANEL}/storage/logs/laravel-$(date -u +%Y-%m-%d).log"; do
  [[ -f "$lg" ]] || continue
  say "  [${lg}]"
  grep -E "ERROR|CRITICAL|Exception" "$lg" 2>/dev/null | tail -25 | cut -c1-300 | sed 's/^/    /' || say "    (koi error line nahi)"
done
say ""
say "--- nginx panel error log (aakhri 10) ---"
tail -10 /var/log/nginx/alphacp-panel.error.log 2>/dev/null | sed 's/^/    /' || say "    (nahi mila)"
say ""
say "--- php-fpm error log (aakhri 10) ---"
tail -10 /var/log/php/alphacp-fpm-error.log 2>/dev/null | sed 's/^/    /' || say "    (nahi mila)"
} 2>&1 | tee_report

# ================================================== STEP 2: LIVE LOGIN PROBE
cat > "${WORK}/probe.py" <<'PYEOF'
import re, sys
h = open(sys.argv[1], encoding='utf-8', errors='replace').read()
what = sys.argv[2] if len(sys.argv) > 2 else 'token'
if what == 'token':
    m = re.search(r'name="_token"[^>]*?value="([^"]+)"', h) or re.search(r'value="([^"]+)"[^>]*?name="_token"', h)
    print(m.group(1) if m else '')
else:
    out = []
    for blk in re.findall(r'<div class="flash error"[^>]*>(.*?)</div>', h, re.S):
        out.append(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', blk)).strip())
    m = re.search(r'<title>(.*?)</title>', h, re.S)
    if m: out.append('TITLE=' + m.group(1).strip())
    for m in re.finditer(r'(Too many redirects|419|Page Expired|Server Error|403|404)', h):
        out.append('HIT=' + m.group(1))
    print(' | '.join(dict.fromkeys(out))[:600])
PYEOF

PROBE_USER="${ACP_FIX_USER:-}"
PROBE_PASS="${ACP_FIX_PASS:-}"
if [[ -z "${PROBE_USER}" && -t 0 && "${MODE}" != "diagnose" ]]; then
  read -r -p "Test ke liye panel username (khaali = live login test skip): " PROBE_USER || true
  if [[ -n "${PROBE_USER}" ]]; then
    read -r -s -p "Uska password (screen par nahi dikhega): " PROBE_PASS || true; echo
  fi
fi

login_probe() { # login_probe <label> <user> <pass>
  local label="$1" u="$2" p="$3"
  local base="https://127.0.0.1:${PRIMARY_PORT}"
  local jar="${WORK}/jar-${label}.txt" body="${WORK}/body-${label}.html"
  rm -f "${jar}" "${body}"
  local code loc fin
  code="$(curl -k -s -c "${jar}" -b "${jar}" -o "${body}" -w '%{http_code}' -m 20 "${base}/" 2>/dev/null || echo none)"
  say "  [${label}] GET  /            -> HTTP ${code}"
  [[ "${code}" == "200" ]] || { warn "login page hi nahi khula — pehle panel 500 fix karo (panel-doctor)"; return 1; }
  local tok; tok="$(python3 "${WORK}/probe.py" "${body}" token 2>/dev/null || echo '')"
  [[ -n "${tok}" ]] || { warn "CSRF token nahi mila (login page ka HTML badla?)"; return 1; }
  local out
  out="$(curl -k -s -c "${jar}" -b "${jar}" -o "${body}" -w '%{http_code}|%{redirect_url}' -m 25 \
          --data-urlencode "_token=${tok}" --data-urlencode "username=${u}" --data-urlencode "password=${p}" \
          "${base}/login" 2>/dev/null || echo 'curlfail|')"
  code="${out%%|*}"; loc="${out#*|}"
  say "  [${label}] POST /login (${u}) -> HTTP ${code}${loc:+   Location: ${loc}}"
  local msgs; msgs="$(python3 "${WORK}/probe.py" "${body}" errors 2>/dev/null || true)"
  [[ -n "${msgs}" ]] && say "  [${label}] SCREEN PAR: ${msgs}"
  if [[ "${code}" == "302" && -n "${loc}" ]]; then
    fin="$(curl -k -s -c "${jar}" -b "${jar}" -o "${body}" -w '%{http_code}|%{url_effective}|%{num_redirects}' \
            -m 30 -L --max-redirs 8 "${loc}" 2>/dev/null)"
    local rc=$?
    if [[ ${rc} -eq 47 ]]; then
      err "  [${label}] REDIRECT LOOP mila (curl: too many redirects) -> B3 (2FA loop)"
      return 2
    fi
    say "  [${label}] FINAL: ${fin}"
    case "${fin}" in
      200*/dashboard*) ok "  [${label}] LOGIN CHAL GAYA ✅ (dashboard 200)"; return 0 ;;
      200*)            ok "  [${label}] login ke baad page 200 mila"; return 0 ;;
      *)               warn "  [${label}] dashboard tak nahi pahuncha"; return 3 ;;
    esac
  fi
  [[ "${code}" == "419" ]] && err "  [${label}] 419 = CSRF/session (cookie store nahi ho rahi)"
  [[ "${code}" == "429" ]] && err "  [${label}] 429 = rate limit (throttle:login) — thodi der baad ya Step 3 ke baad try"
  [[ "${code}" =~ ^5 ]] && err "  [${label}] 5xx = server error — laravel.log upar print hua hai"
  return 3
}

if [[ -n "${PROBE_USER}" && -n "${PROBE_PASS}" ]]; then
  hdr "Step 2: LIVE LOGIN PROBE (fix se PEHLE — asli error yahi hai)"
  OUT="$(login_probe "before" "${PROBE_USER}" "${PROBE_PASS}" 2>&1)"; PROBE_BEFORE=$?
  printf '%s\n' "${OUT}" | tee_report
else
  hdr "Step 2: LIVE LOGIN PROBE"
  warn "username/password nahi mile -> live probe skip. (Non-interactive: ACP_FIX_USER=.. ACP_FIX_PASS=.. sudo -E bash $0)"
fi

if [[ "${MODE}" == "diagnose" ]]; then
  hdr "DIAGNOSE-ONLY mode: kuch badla nahi gaya"
  say "report: ${REPORT}"
  exit 0
fi

# ============================================================== STEP 3: UNLOCK
hdr "Step 3: lockout / throttle saaf (baar-baar fail hone se account lock ho jata hai)"
cat > "${WORK}/unlock.php" <<PHPEOF
<?php
declare(strict_types=1);
require '${PANEL}/vendor/autoload.php';
\$app = require '${PANEL}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
\$n = 0;
try {
    \$n = DB::table('users')->whereNotNull('locked_until')->update(['locked_until' => null]);
    DB::table('users')->where('failed_logins', '>', 0)->update(['failed_logins' => 0]);
    DB::table('users')->where('status', 'locked')->update(['status' => 'active']);
    \$la = DB::table('login_attempts')->delete();
    echo "  unlocked users: {\$n}   login_attempts cleared: {\$la}\n";
} catch (\Throwable \$e) { echo '  unlock FAIL: '.\$e->getMessage()."\n"; }
PHPEOF
runphp "${WORK}/unlock.php" 2>&1 | tee_report
ASK cache:clear >/dev/null 2>&1 && ok "cache clear (rate-limit keys bhi)" || warn "cache:clear nahi chala"

# ============================================ STEP 4: EntryLoginController v2
hdr "Step 4: EntryLoginController v2 (truth-based, fail-open, crash-proof)"
CTRL="${PANEL}/app/Http/Controllers/Auth/EntryLoginController.php"
mkdir -p "$(dirname "${CTRL}")"
if [[ -f "${CTRL}" ]]; then
  backup_file "${CTRL}"
  if grep -q 'public static function decide' "${CTRL}"; then
    ok "v2 pehle se installed — skip"
  else
    warn "v1 mila (lockout wala) — backup: ${BK}${CTRL#/}... replace ho raha hai"
  fi
fi
cat > "${WORK}/EntryLoginController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * cPanel-jaisi ENTRY SEPARATION — v2 (truth-based, fail-open).
 *
 *   * Server Manager (root / reseller)  : 8090 (brand) + 2087/2086 (WHM compat)
 *   * Account Panel  (user / mail)      : 2083 (cPanel compat) + 2096 (webmail)
 *
 * Asli cPanel me root 2083 par login HI nahi ho sakta aur customer 2087 par
 * nahi — yahan wahi rule hai.
 *
 * v1 me ek design galti thi jis se panel LOCK ho jata tha:
 *   gate "ports.json" (owner ki /ports screen se save hui ichha) par bharosa
 *   karta tha, nginx par kya ACTUALLY listen ho raha hai us par nahi. Owner ne
 *   2083 enable kiya par nginx apply-step kabhi chala hi nahi (2083 par koi
 *   listener bana hi nahi) -> customer/user role ka har login 8090 par
 *   "Account Panel login 2083 par hota hai" se reject, aur 2083 kholne par
 *   connection refused. Isi tarah reverse-proxy (Cloudflare/tunnel, X-Forwarded
 *   -Port: 443) par getPort() 443 deta tha -> customer entry "unknown" -> phir
 *   bhi denial. Panel ke apne AuthTest bhi isi se fail hote the.
 *
 * v2 ke niyam (har ek ka matlab: kabhi koi user lockout NAHI hota):
 *   1. Truth file = /usr/local/alphacp/etc/entry-ports.json — ise ROOT ka
 *      `acp-entry-ports` script nginx/ss se banata hai, isliye isme sirf wahi
 *      ports hote hain jo sach me listen kar rahe hain. File nahi hai/mil nahi
 *      rahi = single-entry mode = gate OFF (pehle jaisa sab ek URL par).
 *   2. Denial sirf tab hota hai jab DUSRI entry sach me live ho.
 *   3. Port ya role pehchana na ja sake (proxy, 443, custom role) -> allow.
 *   4. Galat password/unknown user -> gate chup rehta hai, parent ka generic
 *      "Username or password is incorrect." hi dikhta hai (role enumeration
 *      port se nahi ho sakti).
 *   5. Koi bhi Throwable -> report + allow. Gate kabhi login todta nahi.
 *
 * Base LoginController untouched hai; routes ka import alias badalta hai:
 *   use App\Http\Controllers\Auth\EntryLoginController as LoginController;
 *
 * @acp-config acp.entry_ports_file (optional override of self::TRUTH_FILE)
 */
final class EntryLoginController extends LoginController
{
    /** WHM/Server Manager side ke candidate ports (8090 = AlphaCP brand port). */
    public const MANAGER_PORTS  = [8090, 2087, 2086];

    /** cPanel/Webmail (Account Panel) side ke candidate ports. */
    public const CUSTOMER_PORTS = [2083, 2096, 2082, 2095];

    public const MANAGER_ROLES  = ['root', 'reseller'];
    public const CUSTOMER_ROLES = ['user', 'mail'];

    /** Root likhta hai (0644), panel user padhta hai — open_basedir me allowed. */
    public const TRUTH_FILE = '/usr/local/alphacp/etc/entry-ports.json';

    public function login(Request $request): RedirectResponse
    {
        $denial = null;

        try {
            $denial = $this->entryDenial($request);
        } catch (\Throwable $e) {
            report($e);          // rule 5: gate kabhi login ko todta nahi
            $denial = null;
        }

        if ($denial !== null) {
            Audit::log('auth.entry_denied', 'warning', null, null, [
                'username' => self::asString($request->input('username')),
                'port'     => self::requestPort($request),
                'reason'   => $denial,
            ]);

            return back()->withErrors(['username' => $denial])->onlyInput('username');
        }

        return parent::login($request);
    }

    /**
     * Sirf VALID credentials par entry check hota hai — warna port se role
     * enumerate ho jata. Har "pata nahi" case me null (allow) milta hai.
     */
    private function entryDenial(Request $request): ?string
    {
        $live = self::liveEntries();

        if ($live === null) {
            return null;                       // single-entry mode, koi gate nahi
        }

        $username = self::asString($request->input('username'));
        $password = self::asString($request->input('password'));

        if ($username === '' || $password === '') {
            return null;                       // validation parent karega
        }

        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            return null;                       // unknown user -> generic error
        }

        $hash = is_string($user->password_hash) ? $user->password_hash : '';

        if ($hash === '' || ! Hash::check($password, $hash)) {
            return null;                       // galat password -> generic error
        }

        return self::decide(
            strtolower((string) ($user->role?->name ?? '')),
            self::requestPort($request),
            $live,
            self::hostLabel($request),
        );
    }

    /**
     * PURE decision — na DB, na file, na request. Isi wajah se ye panel ke
     * feature test me bhi chalta hai aur server ke `login-fix --selftest` me bhi.
     *
     * @param array{manager: list<int>, customer: list<int>}|null $live
     */
    public static function decide(string $role, ?int $port, ?array $live, string $host = '<host>'): ?string
    {
        if ($live === null || $port === null) {
            return null;                       // rule 3: confirm nahi -> allow
        }

        $manager  = in_array($role, self::MANAGER_ROLES, true);
        $customer = in_array($role, self::CUSTOMER_ROLES, true);

        if (! $manager && ! $customer) {
            return null;                       // unknown/custom role -> allow
        }

        $onManager  = in_array($port, $live['manager'], true);
        $onCustomer = in_array($port, $live['customer'], true);

        if (! $onManager && ! $onCustomer) {
            return null;                       // proxy / 443 / anjaan entry -> allow
        }

        // rule 2: dusri entry sach me live ho tabhi bhejo, warna lockout.
        if ($onCustomer && $manager && $live['manager'] !== []) {
            return 'Server Manager (owner/reseller) login '
                 . self::portLinks($live['manager'], $host) . ' par hota hai.';
        }

        if ($onManager && $customer && $live['customer'] !== []) {
            return 'Account Panel (customer/webmail) login '
                 . self::portLinks($live['customer'], $host) . ' par hota hai.';
        }

        return null;
    }

    /**
     * Truth file padho. Na mile / galat ho / koi maany prakar ka port na ho
     * -> null, matlab gate OFF (single-entry mode). Kabhi exception nahi.
     *
     * @return array{manager: list<int>, customer: list<int>}|null
     */
    public static function liveEntries(?string $path = null): ?array
    {
        if ($path === null) {
            $path = (string) config('acp.entry_ports_file');
            if ($path === '') {
                // ACP_HOME se bano — dev/sim box par /usr/local/alphacp hota hi nahi.
                $home = rtrim((string) config('acp.home', '/usr/local/alphacp'), '/');
                $path = ($home !== '' ? $home : '/usr/local/alphacp') . '/etc/entry-ports.json';
            }
        }
        $cfg  = self::readJson($path);

        if ($cfg === null) {
            return null;
        }

        $manager  = self::intList($cfg['manager'] ?? null, self::MANAGER_PORTS);
        $customer = self::intList($cfg['customer'] ?? null, self::CUSTOMER_PORTS);

        if ($manager === [] && $customer === []) {
            return null;                       // koi entry live hi nahi -> gate OFF
        }

        return ['manager' => $manager, 'customer' => $customer];
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        try {
            // open_basedir ke bahar ka path ErrorException deta hai -> catch.
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }
            $raw = @file_get_contents($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $cfg = json_decode($raw, true);

        return is_array($cfg) ? $cfg : null;
    }

    /**
     * Truth file ke ports ko allowlist se kaato — koi bhi galat/bharosemand
     * file se panel anjaane port par redirect nahi karega.
     *
     * @param list<int> $allowed
     * @return list<int>
     */
    private static function intList(mixed $raw, array $allowed): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $p) {
            $n = is_numeric($p) ? (int) $p : 0;
            if (in_array($n, $allowed, true)) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    private static function requestPort(Request $request): ?int
    {
        try {
            $port = (int) $request->getPort();
        } catch (\Throwable) {
            return null;
        }

        return ($port > 0 && $port <= 65535) ? $port : null;
    }

    private static function hostLabel(Request $request): string
    {
        try {
            $host = (string) $request->getHost();
        } catch (\Throwable) {
            $host = '';
        }

        $host = trim($host);

        return ($host !== '' && ! str_contains($host, "\n")) ? $host : '<host>';
    }

    /** @param list<int> $ports */
    private static function portLinks(array $ports, string $host): string
    {
        $links = array_map(
            static fn (int $p): string => 'https://' . $host . ':' . $p,
            $ports,
        );

        return implode(' ya ', $links);
    }

    /** array/object/null input se PHP notice bachao (validation parent karta hai). */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
PHPEOF
if lint_php "${WORK}/EntryLoginController.php"; then
  install -m 0644 -o "${PANEL_USER}" -g "${PANEL_USER}" "${WORK}/EntryLoginController.php" "${CTRL}" 2>/dev/null \
    || { cp "${WORK}/EntryLoginController.php" "${CTRL}"; chown "${PANEL_USER}:${PANEL_USER}" "${CTRL}"; chmod 0644 "${CTRL}"; }
  ok "installed: ${CTRL}"
else
  err "naya EntryLoginController lint FAIL — swap nahi kiya (purana barkaraar)"
  "${PHPBIN}" -l "${WORK}/EntryLoginController.php" 2>&1 | head -5
fi

# ====================================== STEP 4b: ResellerScopeProvider v2 (B5)
hdr "Step 4b: ResellerScopeProvider v2 — FATAL RECURSION fix (B5)"
RSP="${PANEL}/app/Providers/ResellerScopeProvider.php"
if [[ -f "${RSP}" ]]; then
  backup_file "${RSP}"
  if grep -q 'resolvingActor' "${RSP}"; then
    ok "v2 pehle se installed — skip"
    RSP_DONE=1
  else
    warn "v1 mila (recursion wala) — backup: ${BK}${RSP#/}... replace ho raha hai"
    RSP_DONE=0
  fi
else
  warn "${RSP} maujood nahi (shayad ye panel version purana hai) — skip"
  RSP_DONE=1
fi
if [[ "${RSP_DONE}" -eq 0 ]]; then
  cat > "${WORK}/ResellerScopeProvider.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * WHM-reseller parity (cPanel jaisa scoping):
 *
 *  1. Reseller ko Accounts pages par SIRF apne accounts dikhte hain
 *     (route-model-binding samet — doosre ka account URL se bhi 404).
 *  2. Reseller ko Users pages par sirf apne accounts ke owner-users +
 *     users jo usne khud banaye (+ khud) dikhte hain.
 *  3. Reseller apne barabar ya upar wala role (reseller/root) create
 *     nahi kar sakta — sirf user/mail jaise niche wale roles.
 *
 * Root par koi asar nahi (isRoot bypass). CLI (actor null) par bhi nahi —
 * isliye demo/seeder commands normal chalti hain.
 *
 * ---------------------------------------------------------------------------
 * v2 — FATAL RECURSION FIX (login-fix v1.0)
 * ---------------------------------------------------------------------------
 * v1 har global scope me seedha `Auth::user()` call karta tha. Laravel ka
 * SessionGuard::user() apna `$this->user` **retrieveById() return hone ke BAAD**
 * set karta hai, aur EloquentUserProvider::retrieveById() `newQuery()` se query
 * banata hai — matlab GLOBAL SCOPES ke saath. Isliye:
 *
 *   GET /dashboard
 *     -> Auth::user()                    (guard: user abhi null)
 *     -> SessionGuard::user()
 *     -> EloquentUserProvider::retrieveById($id)
 *     -> User query -> global scope
 *     -> Auth::user()  <-- guard ka user ABHI BHI null
 *     -> retrieveById() ... INFINITE LOOP
 *
 * Nateeja: login POST to 302 de deta tha, par uske baad ka pehla hi
 * authenticated request PHP fatal (memory/nesting) -> HTTP 500. Browser me
 * yahi "login nahi ho raha, error aa raha" dikhta tha. Panel ke tests isko
 * pakad nahi paate the kyunki `actingAs()` guard par user SEEDHA set kar deta
 * hai (retrieveById chalta hi nahi). Reproduce: tools/sim/login-entry-sim.sh P9.
 *
 * Fix: `actor()` ek recursion-breaker flag ke saath actor nikaalta hai. Auth khud
 * user load kar raha ho tab scope CHUP rehta hai (filter nahi lagta) — jo zaroori
 * bhi hai, warna session-auth ki query hi scope me phas jaati. Guard ek baar user
 * cache kar leta hai, uske baad har query par sahi scope lagta hai.
 * `finally` ki wajah se flag kabhi stuck nahi hota (exception par bhi reset).
 */
final class ResellerScopeProvider extends ServiceProvider
{
    /** Recursion breaker — sirf auth ke apne retrieveById() call ke dauran true. */
    private static bool $resolvingActor = false;

    public function boot(): void
    {
        Account::addGlobalScope('reseller_scope', function ($query): void {
            $user = self::actor();

            if ($user !== null && ! $user->isRoot() && $user->hasPermission('accounts.view')) {
                $query->where('reseller_id', $user->id);
            }
        });

        User::addGlobalScope('reseller_scope', function ($query): void {
            $user = self::actor();

            if ($user !== null && ! $user->isRoot() && $user->hasPermission('users.view')) {
                $query->where(function ($q) use ($user): void {
                    $q->where('users.id', $user->id)
                        ->orWhere('users.created_by', $user->id)
                        ->orWhereHas('hostingAccount', fn ($s) => $s->where('reseller_id', $user->id));
                });
            }
        });

        User::creating(function (User $new): void {
            $actor = self::actor();

            if ($actor === null || $actor->isRoot()) {
                return;
            }

            $role       = Role::query()->find($new->role_id);
            $actorLevel = (int) ($actor->role?->level ?? 1);

            if ($role !== null && (int) $role->level <= $actorLevel) {
                abort(403, 'Aap apne barabar ya upar wala role create nahi kar sakte.');
            }
        });
    }

    /**
     * Request ka actor — recursion-safe. Auth khud user resolve kar raha ho to
     * null (scope us query par filter nahi lagayega).
     */
    private static function actor(): ?User
    {
        if (self::$resolvingActor) {
            return null;
        }

        self::$resolvingActor = true;

        try {
            $user = Auth::user();
        } catch (\Throwable) {
            $user = null;   // scoping kabhi request ko todta nahi
        } finally {
            self::$resolvingActor = false;
        }

        return $user instanceof User ? $user : null;
    }
}
PHPEOF
  if lint_php "${WORK}/ResellerScopeProvider.php"; then
    install -m 0644 -o "${PANEL_USER}" -g "${PANEL_USER}" "${WORK}/ResellerScopeProvider.php" "${RSP}" 2>/dev/null \
      || { cp "${WORK}/ResellerScopeProvider.php" "${RSP}"; chown "${PANEL_USER}:${PANEL_USER}" "${RSP}"; chmod 0644 "${RSP}"; }
    ok "installed: ${RSP}"
  else
    err "naya ResellerScopeProvider lint FAIL — swap nahi kiya (purana barkaraar)"
    "${PHPBIN}" -l "${WORK}/ResellerScopeProvider.php" 2>&1 | head -5
  fi
fi

# =============================================== STEP 5: 2FA loop-breaker
hdr "Step 5: 2FA redirect-loop fix (B3)"
MW="${PANEL}/app/Http/Middleware/EnsureTwoFactorIsVerified.php"
backup_file "${MW}"
cat > "${WORK}/EnsureTwoFactorIsVerified.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks panel routes until the user's second factor is verified for this
 * session. Login sets `two_factor_passed` when the user has no 2FA configured
 * OR after a correct TOTP code.
 *
 * LOOP FIX (v2): agar account par 2FA enabled hi nahi hai par session me
 * `two_factor_passed` kho gaya hai (purana session, admin ne 2FA off kiya,
 * session driver badla, ya login ke beech ka ek adhoora request), to ye
 * middleware /two-factor par bhejta tha aur TwoFactorController::challenge()
 * wahan se /dashboard par — matlab /dashboard <-> /two-factor ka INFINITE
 * REDIRECT LOOP. Browser me yahi "login nahi ho raha, error aa raha" dikhta
 * hai (ERR_TOO_MANY_REDIRECTS). Ab flag khud heal ho jata hai.
 */
class EnsureTwoFactorIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 2FA account par hai hi nahi -> challenge ka koi matlab nahi.
        if ($user !== null && ! $user->two_factor_enabled) {
            $request->session()->put('two_factor_passed', true);
        }

        if (! $request->session()->get('two_factor_passed', false)) {
            return redirect()->route('twofactor.challenge');
        }

        return $next($request);
    }
}
PHPEOF
if lint_php "${WORK}/EnsureTwoFactorIsVerified.php"; then
  install -m 0644 -o "${PANEL_USER}" -g "${PANEL_USER}" "${WORK}/EnsureTwoFactorIsVerified.php" "${MW}" 2>/dev/null \
    || { cp "${WORK}/EnsureTwoFactorIsVerified.php" "${MW}"; chown "${PANEL_USER}:${PANEL_USER}" "${MW}"; chmod 0644 "${MW}"; }
  ok "installed: ${MW}"
else
  err "middleware lint FAIL — swap nahi kiya"
fi

TFC="${PANEL}/app/Http/Controllers/Auth/TwoFactorController.php"
backup_file "${TFC}"
if grep -q "loop-breaker" "${TFC}" 2>/dev/null; then
  ok "TwoFactorController me loop-breaker pehle se hai — skip"
elif [[ -f "${TFC}" ]]; then
  python3 - "${TFC}" <<'PYEOF'
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
old = """        if (! $user->two_factor_enabled || request()->session()->get('two_factor_passed')) {
            return redirect()->route('dashboard');
        }"""
new = """        if (! $user->two_factor_enabled || request()->session()->get('two_factor_passed')) {
            request()->session()->put('two_factor_passed', true);   // loop-breaker (login-fix v1.0)
            return redirect()->route('dashboard');
        }"""
if old in s:
    open(p, 'w', encoding='utf-8').write(s.replace(old, new, 1)); print("  patched")
else:
    print("  ANCHOR-NAHI-MILA")
PYEOF
  if grep -q "loop-breaker" "${TFC}"; then ok "TwoFactorController patched"; else warn "TwoFactorController anchor nahi mila — file jaisi thi waisi (middleware fix kaafi hai)"; fi
fi

# =============================================== STEP 6: GET /login alias
hdr "Step 6: GET /login alias (B4 — pehle 404 deta tha)"
ROUTES="${PANEL}/routes/web.php"
backup_file "${ROUTES}"
if grep -q "'/login', \[LoginController::class, 'show'\]" "${ROUTES}" 2>/dev/null; then
  ok "GET /login pehle se hai — skip"
else
  python3 - "${ROUTES}" <<'PYEOF'
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
anchor = "    Route::get('/', [LoginController::class, 'show'])->name('login');"
add = ("\n    // /login bhi wahi login page — bookmark/WHMCS/cPanel aadat. Pehle 404 deta tha.\n"
       "    Route::get('/login', [LoginController::class, 'show'])->name('login.page');")
if anchor in s:
    open(p, 'w', encoding='utf-8').write(s.replace(anchor, anchor + add, 1)); print("  patched")
else:
    print("  ANCHOR-NAHI-MILA")
PYEOF
  if lint_php "${ROUTES}"; then
    if grep -q "'/login', \[LoginController::class, 'show'\]" "${ROUTES}"; then ok "routes/web.php patched"; else warn "route anchor nahi mila — routes jaise the waise"; fi
  else
    err "routes lint FAIL -> backup wapas"
    cp -a "${BK}${ROUTES#/}" "${ROUTES}" && ok "routes/web.php restore"
  fi
fi

# ============================================ STEP 7: entry-ports truth file
hdr "Step 7: acp-entry-ports (nginx ke ASLI ports -> ${ENTRY_FILE})"
backup_file "${BIN_ENTRY}"
mkdir -p "${ACP_HOME}/bin" "${ACP_HOME}/etc"
cat > "${WORK}/acp-entry-ports" <<'SHEOF'
#!/usr/bin/env bash
# =============================================================================
#  acp-entry-ports v1.0 — nginx ABHI kin panel ports par sun raha hai, uski
#  "truth file" banata hai:  /usr/local/alphacp/etc/entry-ports.json
#
#  Kyun: EntryLoginController (root/reseller vs customer entry separation) pehle
#  owner ki /ports screen ki ichha (ports.json) padhta tha. Ichha aur haqiqat
#  alag ho sakti hai — nginx par 2083 ka listener bana hi na ho — aur tab
#  customer login hamesha ke liye lock ho jata tha. Ab gate sirf isi file ko
#  maanta hai, aur ye file sirf sach me LISTENING ports se banti hai.
#
#  Teen chhanni (intersection), teeno pass honi chahiye:
#    1. nginx config me `listen <port> ssl;` ho        (nginx -T)
#    2. port sach me bound ho                          (ss -Hltn / netstat)
#    3. port AlphaCP ki jaani-pehchani entry list me ho (8090/2087/2086 | 2083/2096/2082/2095)
#
#  Root chalata hai (cron + login-fix script). Kabhi bhi exit 1 nahi karta —
#  file na ban sakti ho to purani file waisi hi rehti hai (gate fail-open hai).
#
#  Usage:  acp-entry-ports [--print] [--quiet]
# =============================================================================
set -uo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
OUT="${ACP_ENTRY_PORTS_FILE:-${ACP_HOME}/etc/entry-ports.json}"

MANAGER_CANDIDATES="8090 2087 2086"
CUSTOMER_CANDIDATES="2083 2096 2082 2095"

PRINT=0; QUIET=0
for a in "$@"; do
  case "$a" in
    --print) PRINT=1 ;;
    --quiet) QUIET=1 ;;
    --version) echo "acp-entry-ports v${VERSION}"; exit 0 ;;
    *) echo "unknown option: $a" >&2; exit 0 ;;
  esac
done

log() { [[ ${QUIET} -eq 1 ]] || printf '%s\n' "$*"; }

# --------------------------------------------------------------- 1. nginx config
nginx_ssl_ports() {
  local dump=""
  if command -v nginx >/dev/null 2>&1; then
    dump="$(nginx -T 2>/dev/null || true)"
  fi
  if [[ -z "${dump}" ]]; then
    # nginx binary nahi/chala nahi — config files se hi padh lo
    for d in /etc/nginx /usr/local/nginx/conf; do
      [[ -d "${d}" ]] && dump+="$(grep -rhn '' "${d}" 2>/dev/null || true)"$'\n'
    done
  fi
  printf '%s\n' "${dump}" \
    | grep -E '^[[:space:]]*listen[[:space:]]' \
    | grep -Ei '[[:space:]]ssl([[:space:]]*;|;|$)' \
    | sed -E 's/^[[:space:]]*listen[[:space:]]+//; s/^\[::\]://' \
    | sed -E 's/[[:space:]]*ssl.*$//; s/;.*$//' \
    | grep -E '^[0-9]{1,5}$' \
    | sort -un
}

# --------------------------------------------------------------- 2. bound ports
listening_ports() {
  if command -v ss >/dev/null 2>&1; then
    ss -Hltn 2>/dev/null | awk '{print $4}' | grep -oE '[0-9]+$' | sort -un; return
  fi
  if command -v netstat >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | awk 'NR>2 {print $4}' | grep -oE '[0-9]+$' | sort -un; return
  fi
  # /proc fallback (state 0A = LISTEN)
  awk 'NR>1 && $4=="0A" {split($2,a,":"); print strtonum("0x" a[2])}' /proc/net/tcp 2>/dev/null | sort -un
}

in_list() { local n="$1"; shift; for c in $*; do [[ "${n}" == "${c}" ]] && return 0; done; return 1; }

NGINX_PORTS="$(nginx_ssl_ports)"
BOUND_PORTS="$(listening_ports)"

pick() { # pick <candidates...>
  local out="" p
  for p in $*; do
    if grep -qx "${p}" <<<"${NGINX_PORTS}" && grep -qx "${p}" <<<"${BOUND_PORTS}"; then
      out+="${out:+,}${p}"
    fi
  done
  printf '%s' "${out}"
}

# shellcheck disable=SC2086
MANAGER="$(pick ${MANAGER_CANDIDATES})"
# shellcheck disable=SC2086
CUSTOMER="$(pick ${CUSTOMER_CANDIDATES})"

to_json_array() { local s="$1"; [[ -z "${s}" ]] && { printf '[]'; return; }; printf '[%s]' "${s}"; }

TMP="$(mktemp "${OUT}.tmp.XXXXXX" 2>/dev/null || mktemp)"
cat > "${TMP}" <<JSON
{
  "manager": $(to_json_array "${MANAGER}"),
  "customer": $(to_json_array "${CUSTOMER}"),
  "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "generated_by": "acp-entry-ports v${VERSION}",
  "note": "nginx -T + ss -Hltn se sach me LISTENING panel ports. EntryLoginController sirf isi ko maanta hai. Haath se edit mat karo."
}
JSON

if mkdir -p "$(dirname "${OUT}")" 2>/dev/null && mv -f "${TMP}" "${OUT}" 2>/dev/null; then
  chmod 0644 "${OUT}" 2>/dev/null || true
  chown root:root "${OUT}" 2>/dev/null || true
  rm -f "${TMP}" 2>/dev/null || true
  log "acp-entry-ports v${VERSION}: manager=[${MANAGER}] customer=[${CUSTOMER}] -> ${OUT}"
else
  rm -f "${TMP}" 2>/dev/null || true
  log "acp-entry-ports v${VERSION}: ${OUT} likha nahi ja saka (purani file barkaraar) — gate fail-open rahega"
fi

if [[ ${PRINT} -eq 1 ]]; then
  echo "--- nginx ssl listen ports ---"; printf '%s\n' "${NGINX_PORTS:-(none)}"
  echo "--- bound tcp ports ---";        printf '%s\n' "${BOUND_PORTS:-(none)}"
  echo "--- ${OUT} ---"; cat "${OUT}" 2>/dev/null || echo "(nahi bana)"
fi
exit 0
SHEOF
if bash -n "${WORK}/acp-entry-ports"; then
  install -m 0755 -o root -g root "${WORK}/acp-entry-ports" "${BIN_ENTRY}" 2>/dev/null \
    || { cp "${WORK}/acp-entry-ports" "${BIN_ENTRY}"; chmod 0755 "${BIN_ENTRY}"; chown root:root "${BIN_ENTRY}"; }
  ln -sf "${BIN_ENTRY}" /usr/local/bin/acp-entry-ports 2>/dev/null || true
  ok "installed: ${BIN_ENTRY}"
  EP_OUT="$("${BIN_ENTRY}" 2>&1)"; printf '%s\n' "${EP_OUT}" | tee_report
else
  err "acp-entry-ports bash -n FAIL — install nahi kiya"
fi

if [[ ! -f "${CRON_ENTRY}" ]] || ! grep -q acp-entry-ports "${CRON_ENTRY}" 2>/dev/null; then
  cat > "${CRON_ENTRY}" <<CRONEOF
# AlphaCP entry-ports truth file refresh (login-fix v${FIX_VERSION}). Managed file; do not edit.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * root ${BIN_ENTRY} --quiet >/dev/null 2>&1
@reboot      root sleep 20 && ${BIN_ENTRY} --quiet >/dev/null 2>&1
CRONEOF
  chmod 0644 "${CRON_ENTRY}"; chown root:root "${CRON_ENTRY}"
  ok "cron: ${CRON_ENTRY} (har 5 min refresh)"
else
  ok "cron pehle se hai — skip"
fi

# ============================================ STEP 7b: optional nginx ports
if [[ ${ENABLE_PORTS} -eq 1 ]]; then
  hdr "Step 7b: nginx par 2083/2087/2096 ASLI me listen karwao (--enable-ports)"
  VHOST=""
  for c in /etc/nginx/sites-available/alphacp-panel.conf /etc/nginx/sites-enabled/alphacp-panel.conf /etc/nginx/alpha/nginx.conf; do
    [[ -f "$c" ]] && { VHOST="$c"; break; }
  done
  if [[ -z "${VHOST}" ]]; then
    warn "nginx vhost nahi mila — skip"
  else
    backup_file "${VHOST}"
    BLOCK="    # --- AlphaCP entry ports (login-fix v${FIX_VERSION} ${TS}) ---
    listen 2083 ssl;
    listen 2087 ssl;
    listen 2096 ssl;
    listen [::]:2083 ssl;
    listen [::]:2087 ssl;
    listen [::]:2096 ssl;"
    if grep -q 'listen 2083 ssl' "${VHOST}"; then
      ok "2083 already configured — skip"
    elif grep -q 'ACP_PORTS_START' "${VHOST}"; then
      python3 - "${VHOST}" <<PYEOF
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
block = """${BLOCK}"""
out, seen = [], False
for line in s.splitlines():
    out.append(line)
    if 'ACP_PORTS_START' in line and not seen:
        seen = True
        out.append(block)
open(p, 'w', encoding='utf-8').write("\n".join(out) + "\n")
print("  ACP_PORTS block bhar diya" if seen else "  MARKER-NAHI-MILA")
PYEOF
    else
      python3 - "${VHOST}" <<PYEOF
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
block = """${BLOCK}"""
a = "    listen 8090 ssl;"
open(p, 'w', encoding='utf-8').write(s.replace(a, a + "\n" + block, 1) if a in s else s)
print("  listen 8090 ke baad ports jod diye" if a in s else "  ANCHOR-NAHI-MILA")
PYEOF
    fi
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx 2>/dev/null && ok "nginx reload OK" || warn "nginx reload nahi hua (config test pass tha)"
    else
      err "nginx -t FAIL -> vhost wapas"
      nginx -t 2>&1 | tail -5
      cp -a "${BK}${VHOST#/}" "${VHOST}" && ok "vhost restore"
    fi
    if command -v ufw >/dev/null 2>&1; then
      for p in 2083 2087 2096; do ufw allow "${p}/tcp" comment "AlphaCP entry" >/dev/null 2>&1 && ok "ufw allow ${p}/tcp"; done
      warn "AWS Lightsail/OCI firewall me bhi 2083,2087,2096 kholne padenge (console se) — server se nahi khulte"
    fi
    EP_OUT2="$("${BIN_ENTRY}" 2>&1 || true)"; printf '%s\n' "${EP_OUT2}" | tee_report
  fi
fi

# ================================== STEP 8: perms + cache + fpm restart
hdr "Step 8: permission heal + cache rebuild + php-fpm restart (opcache)"
for d in storage bootstrap/cache; do
  [[ -d "${PANEL}/${d}" ]] || continue
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL}/${d}" 2>/dev/null || true
  find "${PANEL}/${d}" -type d -exec chmod 0770 {} \; 2>/dev/null || true
  find "${PANEL}/${d}" -type f -exec chmod 0660 {} \; 2>/dev/null || true
done
mkdir -p "${PANEL}/storage/framework/views" "${PANEL}/storage/framework/sessions" \
         "${PANEL}/storage/framework/cache/data" "${PANEL}/storage/logs" "${PANEL}/bootstrap/cache"
chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL}/storage" "${PANEL}/bootstrap/cache" 2>/dev/null || true
ok "ownership ${PANEL_USER}:${PANEL_USER}"
# REGRESSION GUARD: fpm user sach me likh pata hai ya nahi (views/cache/logs).
# Na padh sakta = har page 500 (compiled views). Ye check usi ko pakadta hai.
WTEST_OK=1
for wd in storage/framework/cache/data storage/framework/views storage/logs; do
  if runuser -u "${PANEL_USER}" -- touch "${PANEL}/${wd}/.acp-wtest" 2>/dev/null; then
    rm -f "${PANEL}/${wd}/.acp-wtest" 2>/dev/null || true
  else
    WTEST_OK=0; err "fpm user likh NAHI sakta: ${wd}"
  fi
done
if [[ ${WTEST_OK} -eq 1 ]]; then
  ok "fpm user (${PANEL_USER}) storage me likh sakta hai (cache/views/logs)"
else
  err "storage ownership abhi bhi galat — alag se chalao: sudo bash installer/panel-perm-fix.sh"
fi
rm -f "${PANEL}/storage/framework/views/"* 2>/dev/null || true
ASK optimize:clear >/dev/null 2>&1 && ok "optimize:clear (config/route/view/cache/event)" || warn "optimize:clear nahi chala"
ASK config:clear >/dev/null 2>&1 || true
ASK route:clear  >/dev/null 2>&1 || true
ASK view:clear   >/dev/null 2>&1 || true
for svc in php8.4-fpm php8.3-fpm php-fpm; do
  systemctl restart "${svc}" >/dev/null 2>&1 && { ok "restart ${svc}"; break; }
done
sleep 2
CODE="$(hit)"; [[ "${CODE}" == "200" ]] && ok "panel HTTP ${CODE}" || err "panel HTTP ${CODE}"

# ============================================== STEP 9: SELFTEST (16 checks)
hdr "Step 9: SELFTEST — gate ka truth table + routes + B5 recursion check"
cat > "${WORK}/selftest.php" <<PHPEOF
<?php
declare(strict_types=1);
require '${PANEL}/vendor/autoload.php';
\$app = require '${PANEL}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Auth\EntryLoginController as E;

\$pass = 0; \$fail = 0;
\$t = function (string \$name, \$got, \$want) use (&\$pass, &\$fail): void {
    \$g = is_string(\$got) ? \$got : var_export(\$got, true);
    \$w = is_string(\$want) ? \$want : var_export(\$want, true);
    \$good = (\$want === null) ? (\$got === null)
          : ((\$got !== null) && str_contains(\$got, (string) \$want));
    if (\$good) { \$pass++; printf("  PASS  %s\n", \$name); }
    else { \$fail++; printf("  FAIL  %s\n        got=%s want=%s\n", \$name, \$g, \$w); }
};

\$both = ['manager' => [8090, 2087], 'customer' => [2083, 2096]];
\$only = ['manager' => [8090], 'customer' => []];

\$t('root@8090 (dono entry live) -> allow',      E::decide('root', 8090, \$both),     null);
\$t('root@2087 -> allow',                        E::decide('root', 2087, \$both),     null);
\$t('reseller@8090 -> allow',                    E::decide('reseller', 8090, \$both), null);
\$t('customer(user)@2083 -> allow',              E::decide('user', 2083, \$both),     null);
\$t('mail@2096 -> allow',                        E::decide('mail', 2096, \$both),     null);
\$t('root@2083 -> deny (manager entry batao)',   E::decide('root', 2083, \$both),     '8090');
\$t('reseller@2096 -> deny',                     E::decide('reseller', 2096, \$both), '8090');
\$t('customer@8090 -> deny (2083 batao)',        E::decide('user', 8090, \$both),     '2083');
\$t('B2 proxy: customer@443 -> allow (fail-open)', E::decide('user', 443, \$both),    null);
\$t('unknown role@8090 -> allow',                E::decide('admin', 8090, \$both),    null);
\$t('port null -> allow',                        E::decide('user', null, \$both),     null);
\$t('gate OFF (live=null) -> allow',             E::decide('user', 8090, null),       null);
\$t('B1 REGRESSION: sirf 8090 live, customer@8090 -> allow (lockout NAHI)',
   E::decide('user', 8090, \$only), null);

echo "\n  liveEntries() = ";
\$live = E::liveEntries();
echo \$live === null ? "null  => SINGLE-ENTRY MODE (gate OFF, sab ek URL par)\n"
                     : json_encode(\$live) . "  => separation ACTIVE\n";

// route sanity
\$rt = null;
foreach (app('router')->getRoutes() as \$r) {
    if (\$r->getName() === 'login.attempt') { \$rt = \$r->getActionName(); }
}
echo '  login.attempt -> ' . (\$rt ?? '(nahi mila)') . "\n";
if (\$rt !== null && str_contains(\$rt, 'EntryLoginController')) { \$pass++; echo "  PASS  route EntryLoginController par hai\n"; }
elseif (\$rt !== null && str_contains(\$rt, 'LoginController'))   { \$pass++; echo "  PASS  route LoginController par hai (gate off bhi chalega)\n"; }
else { \$fail++; echo "  FAIL  login.attempt route ka controller pehchana nahi gaya\n"; }

// GET /login alias
\$has = false;
foreach (app('router')->getRoutes() as \$r) {
    if (in_array('GET', \$r->methods(), true) && \$r->uri() === 'login') { \$has = true; }
}
if (\$has) { \$pass++; echo "  PASS  GET /login route maujood hai\n"; }
else { \$fail++; echo "  FAIL  GET /login route nahi mila\n"; }

// B5 REGRESSION — global scope recursion (asli browser jaisa session resolve).
// actingAs() jaisa shortcut NAHI: guard ka cached user bhula kar session se
// retrieveById() karwate hain, taaki global scopes sach me chalein.
try {
    \$row = Illuminate\Support\Facades\DB::table('users')
        ->where('status', 'active')->orderBy('id')->first();
    \$target = \$row === null ? null
        : App\Models\User::query()->withoutGlobalScopes()->find(\$row->id);
} catch (\Throwable \$e) { \$target = null; }

if (! \$target instanceof App\Models\User) {
    echo "  SKIP  B5 recursion check (koi active user nahi mila)\n";
} else {
    try {
        \$gname = app('auth')->guard('web')->getName();
        app('session')->put(\$gname, \$target->getAuthIdentifier());
        app('auth')->forgetGuards();          // <-- ab guard session se load karega
        \$resolved = app('auth')->guard('web')->user();
        if (\$resolved !== null
            && (int) \$resolved->getAuthIdentifier() === (int) \$target->getAuthIdentifier()) {
            \$pass++; echo "  PASS  B5: session se user resolve hua (recursion nahi)\n";
        } else {
            \$fail++; echo "  FAIL  B5: session se user resolve NAHI hua (scope ne filter kar diya?)\n";
        }
        app('session')->forget(\$gname);
        app('auth')->forgetGuards();
    } catch (\Throwable \$e) {
        \$fail++; echo "  FAIL  B5: " . get_class(\$e) . ': ' . \$e->getMessage() . "\n";
    }
}

printf("\n=== SELFTEST: %d pass, %d fail ===\n", \$pass, \$fail);
exit(\$fail === 0 ? 0 : 1);
PHPEOF
ST_OUT="$(runphp "${WORK}/selftest.php" 2>&1)"; ST_RC=$?
printf '%s\n' "${ST_OUT}" | tee_report
[[ ${ST_RC} -eq 0 ]] && ok "SELFTEST pass (${ST_RC})" || err "SELFTEST fail (rc=${ST_RC}) — upar dekho"

# ================================== STEP 9b: regression tests install karo
hdr "Step 9b: PHPUnit regression tests (aage koi dobara ye bug na laaye)"
TESTDIR="${PANEL}/tests/Feature"

install_test() { # install_test <Name> <workfile>
  local name="$1" src="$2" dst="${TESTDIR}/${1}.php"
  backup_file "${dst}"
  if lint_php "${src}"; then
    install -m 0644 -o "${PANEL_USER}" -g "${PANEL_USER}" "${src}" "${dst}" 2>/dev/null \
      || { cp "${src}" "${dst}"; chown "${PANEL_USER}:${PANEL_USER}" "${dst}"; chmod 0644 "${dst}"; }
    ok "installed: ${dst}"
  else
    err "${name}.php lint FAIL — install nahi kiya (panel par koi asar nahi)"
  fi
}

if [[ -d "${TESTDIR}" ]]; then
  # Entry-gate (B1/B2) truth table + gate-off behaviour
  cat > "${WORK}/EntryLoginTest.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Auth\EntryLoginController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Entry separation (Server Manager 8090/2087 vs Account Panel 2083/2096) —
 * regression tests for the LOCKOUT bug fixed by installer/login-fix.sh v1.0.
 *
 * Purana behaviour (v1) gate `ports.json` (owner ki ichha) se chalta tha:
 * owner ne 2083 "enable" kiya, nginx ne kabhi 2083 par suna nahi, aur
 * customer/user role ka har login 8090 par reject ho gaya. Isi wajah se
 * tests/Feature/AuthTest.php bhi fail hone lagta tha.
 *
 * Naya behaviour (v2): gate sirf us truth file ko maanta hai jo nginx ke
 * ASLI listening ports se banti hai, aur har "pata nahi" case me fail-OPEN
 * hai — koi bhi user kabhi lock nahi hota.
 */
class EntryLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const LEVELS = ['root' => 1, 'reseller' => 2, 'user' => 3, 'mail' => 4];

    private function makeUsers(): void
    {
        foreach (self::LEVELS as $name => $level) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name],
                ['label' => ucfirst($name), 'level' => $level, 'is_system' => true],
            );

            User::query()->create([
                'username'              => $name . 'u',
                'email'                 => $name . '@example.test',
                'password_hash'         => Hash::make('CorrectHorse1'),
                'role_id'               => $role->id,
                'status'                => 'active',
                'force_password_change' => false,
                'two_factor_enabled'    => false,
            ]);
        }
    }

    /** Truth file likho/hatao. `null` = gate OFF (single-entry mode). */
    private function truthFile(?array $manager, ?array $customer): string
    {
        $path = sys_get_temp_dir() . '/acp-entry-ports-' . uniqid() . '.json';

        if ($manager !== null) {
            file_put_contents($path, json_encode(['manager' => $manager, 'customer' => $customer ?? []]));
        }

        config()->set('acp.entry_ports_file', $manager === null ? $path . '.missing' : $path);

        return $path;
    }

    /** @return array{status:int, loc:?string, err:string} */
    private function attempt(string $base, string $username, string $password = 'CorrectHorse1'): array
    {
        Auth::guard('web')->logout();
        $this->flushSession();

        $response = $this->post($base . '/login', ['username' => $username, 'password' => $password]);

        // ErrorBag / MessageBag / plain array — sirf VALUES chahiye, keys nahi
        // (Laravel testing session me errors kabhi-kabhi [':message' => [...]]
        // shape me aate hain, isliye Arr::flatten keys bhi utha leta tha).
        $errs    = [];
        $collect = static function ($value) use (&$collect, &$errs): void {
            if ($value instanceof \Illuminate\Support\ViewErrorBag) {
                foreach ($value->getBags() as $bag) {
                    $collect($bag);
                }
                return;
            }
            if ($value instanceof \Illuminate\Contracts\Support\MessageBag) {
                foreach ($value->getMessages() as $messages) {
                    $collect($messages);
                }
                return;
            }
            if (is_array($value)) {
                // testing session me MessageBag serialize hoke
                // ['format' => ':message', 'messages' => [...]] ban jata hai
                if (isset($value['messages']) && is_array($value['messages'])) {
                    $collect($value['messages']);
                    return;
                }
                foreach ($value as $item) {
                    $collect($item);
                }
                return;
            }
            if (is_string($value) && $value !== '') {
                $errs[] = $value;
            }
        };
        $collect(session('errors'));

        return [
            'status' => $response->status(),
            'loc'    => $response->headers->get('Location'),
            'err'    => implode('|', $errs),
        ];
    }

    private function assertLoggedIn(string $base, string $username, string $why): void
    {
        $a = $this->attempt($base, $username);
        $this->assertTrue(
            $a['status'] === 302 && str_contains((string) $a['loc'], 'dashboard'),
            "{$why} => status={$a['status']} loc={$a['loc']} err=[{$a['err']}]",
        );
    }

    private function assertDenied(string $base, string $username, string $needle, string $why): void
    {
        $a = $this->attempt($base, $username);
        $this->assertStringContainsString($needle, $a['err'], "{$why} => err=[{$a['err']}] loc={$a['loc']}");
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ pure

    public function test_decide_truth_table(): void
    {
        $both = ['manager' => [8090, 2087], 'customer' => [2083, 2096]];
        $only = ['manager' => [8090], 'customer' => []];

        $this->assertNull(EntryLoginController::decide('root', 8090, $both));
        $this->assertNull(EntryLoginController::decide('root', 2087, $both));
        $this->assertNull(EntryLoginController::decide('reseller', 8090, $both));
        $this->assertNull(EntryLoginController::decide('user', 2083, $both));
        $this->assertNull(EntryLoginController::decide('mail', 2096, $both));

        $this->assertStringContainsString('8090', (string) EntryLoginController::decide('root', 2083, $both));
        $this->assertStringContainsString('8090', (string) EntryLoginController::decide('reseller', 2096, $both));
        $this->assertStringContainsString('2083', (string) EntryLoginController::decide('user', 8090, $both));

        // fail-open cases — kabhi lockout nahi
        $this->assertNull(EntryLoginController::decide('user', 443, $both), 'proxy/443 par fail-open');
        $this->assertNull(EntryLoginController::decide('admin', 8090, $both), 'unknown role par fail-open');
        $this->assertNull(EntryLoginController::decide('user', null, $both), 'port unknown -> fail-open');
        $this->assertNull(EntryLoginController::decide('user', 8090, null), 'truth file nahi -> gate off');

        // B1 regression: customer entry live hi nahi, to customer ko 8090 se mat roko
        $this->assertNull(EntryLoginController::decide('user', 8090, $only), 'sirf 8090 live -> customer allow');
        $this->assertNull(EntryLoginController::decide('mail', 8090, $only));
    }

    public function test_live_entries_rejects_unknown_ports(): void
    {
        $path = $this->truthFile([8090, 9999, 'x'], [2083, 1234]);
        $live = EntryLoginController::liveEntries($path);

        $this->assertSame(['manager' => [8090], 'customer' => [2083]], $live);
        @unlink($path);
    }

    public function test_live_entries_null_when_file_missing(): void
    {
        $this->assertNull(EntryLoginController::liveEntries('/nonexistent/acp-entry-ports.json'));
    }

    // ------------------------------------------------------- end to end

    public function test_no_truth_file_means_everyone_logs_in_on_8090(): void
    {
        $this->makeUsers();
        $this->truthFile(null, null);

        foreach (['rootu', 'reselleru', 'useru', 'mailu'] as $u) {
            $this->assertLoggedIn('https://panel.test:8090', $u, "gate off: {$u}");
        }
    }

    public function test_only_8090_live_means_no_customer_lockout(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090], []);

        $this->assertLoggedIn('https://panel.test:8090', 'rootu', 'manager 8090');
        $this->assertLoggedIn('https://panel.test:8090', 'reselleru', 'reseller 8090');
        $this->assertLoggedIn('https://panel.test:8090', 'useru', 'customer 8090 (pehle LOCKOUT tha)');
        $this->assertLoggedIn('https://panel.test:8090', 'mailu', 'mail 8090 (pehle LOCKOUT tha)');
        @unlink($path);
    }

    public function test_real_separation_when_both_entries_are_live(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090, 2087], [2083, 2096]);

        $this->assertLoggedIn('https://panel.test:8090', 'rootu', 'root@8090');
        $this->assertLoggedIn('https://panel.test:2087', 'reselleru', 'reseller@2087');
        $this->assertLoggedIn('https://panel.test:2083', 'useru', 'customer@2083');
        $this->assertLoggedIn('https://panel.test:2096', 'mailu', 'mail@2096');

        $this->assertDenied('https://panel.test:2083', 'rootu', '8090', 'root@customer-entry');
        $this->assertDenied('https://panel.test:2096', 'reselleru', '8090', 'reseller@webmail-entry');
        $this->assertDenied('https://panel.test:8090', 'useru', '2083', 'customer@manager-entry');
        @unlink($path);
    }

    public function test_wrong_password_still_gives_the_generic_error(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090, 2087], [2083, 2096]);

        // Port se role enumerate na ho: galat password par generic hi error.
        $a = $this->attempt('https://panel.test:8090', 'useru', 'WrongPass123');
        $this->assertSame('Username or password is incorrect.', $a['err']);

        $b = $this->attempt('https://panel.test:2083', 'rootu', 'WrongPass123');
        $this->assertSame('Username or password is incorrect.', $b['err']);
        @unlink($path);
    }

    public function test_array_input_does_not_crash_the_gate(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090], []);

        Auth::guard('web')->logout();
        $this->flushSession();
        $r = $this->post('https://panel.test:8090/login', ['username' => ['a'], 'password' => ['b']]);

        $this->assertNotSame(500, $r->status(), 'array input par gate crash nahi hona chahiye');
        @unlink($path);
    }

    public function test_authenticated_session_without_two_factor_flag_does_not_loop(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'root'], ['label' => 'Root', 'level' => 1, 'is_system' => true]);
        User::query()->create([
            'username' => 'rootu', 'email' => 'r@example.test', 'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id, 'status' => 'active', 'force_password_change' => false,
            'two_factor_enabled' => false,
        ]);
        $this->truthFile(null, null);

        $this->be(User::query()->where('username', 'rootu')->firstOrFail());
        $this->withSession([]);   // two_factor_passed gayab — pehle infinite loop banta tha

        $hops = [];
        $url  = '/dashboard';
        for ($i = 0; $i < 6; $i++) {
            $r   = $this->get($url);
            $loc = $r->headers->get('Location');
            $hops[] = $url . ' -> ' . $r->status() . ($loc ? ' ' . $loc : ' (render)');
            if ($r->status() !== 302 || ! $loc) {
                break;
            }
            $url = parse_url($loc, PHP_URL_PATH) ?: '/';
        }

        $this->assertSame(200, $r->status(), 'REDIRECT LOOP: ' . implode(' | ', $hops));
    }

    public function test_get_login_serves_the_login_page(): void
    {
        $this->truthFile(null, null);
        $this->get('/login')->assertOk()->assertSee('Panel Login');
        $this->get('/')->assertOk()->assertSee('Panel Login');
    }
}
PHPEOF
  # B5 recursion: browser jaisa fresh request (session se retrieveById)
  cat > "${WORK}/SessionAuthTest.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SESSION-AUTH regression tests — installer/login-fix.sh v1.0 ke saath aaye.
 *
 * Ye suite isliye zaroori hai kyunki panel ke baaki tests `actingAs()` use karte
 * hain, jo guard par user SEEDHA set kar deta hai. Asli browser me user session
 * se `EloquentUserProvider::retrieveById()` ke zariye load hota hai — aur us
 * query par global scopes lagte hain. `ResellerScopeProvider` v1 scope ke andar
 * `Auth::user()` call karta tha, jo khud `retrieveById()` trigger karta tha:
 *
 *     Auth::user() -> SessionGuard::user() -> retrieveById()
 *       -> User query -> reseller_scope -> Auth::user() -> ... (infinite)
 *
 * Nateeja: login POST 302 deta tha, phir pehla hi authenticated request PHP
 * fatal (memory/nesting) -> HTTP 500. User ko yahi dikhta tha: "login page
 * khulta hai, credentials daalne par login nahi hota, error aata hai."
 *
 * `freshRequest()` niche wahi asli haalat banata hai: guards bhula do, taaki
 * user session se dobara resolve ho.
 */
class SessionAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const LEVELS = ['root' => 1, 'reseller' => 2, 'user' => 3, 'mail' => 4];

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'level' => self::LEVELS[$name] ?? 3, 'is_system' => true],
        );
    }

    private function makeUser(string $roleName, string $username, ?int $createdBy = null): User
    {
        return User::query()->create([
            'username'              => $username,
            'email'                 => $username . '@example.test',
            'password_hash'         => Hash::make('CorrectHorse1'),
            'full_name'             => ucfirst($username),
            'role_id'               => $this->role($roleName)->id,
            'status'                => 'active',
            'force_password_change' => false,
            'two_factor_enabled'    => false,
            'created_by'            => $createdBy,
        ]);
    }

    /** Reseller ko scoping wale permission keys do (PermissionCatalog se independent). */
    private function grantResellerScope(): void
    {
        foreach (['accounts.view', 'users.view'] as $key) {
            RolePermission::query()->firstOrCreate([
                'role_id'        => $this->role('reseller')->id,
                'permission_key' => $key,
            ]);
        }
    }

    /**
     * Browser jaisa ASLI request: guards bhool jao taaki user session se
     * (retrieveById + global scopes ke saath) resolve ho.
     */
    private function freshRequest(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }

    // ------------------------------------------------- B5: fatal recursion

    #[DataProvider('rolesProvider')]
    public function test_authenticated_page_works_on_a_fresh_request(string $roleName): void
    {
        $this->grantResellerScope();
        config()->set('acp.entry_ports_file', '/nonexistent/acp-entry-ports.json');

        $this->makeUser($roleName, $roleName . 'u');

        $this->post('https://panel.test:8090/login', [
            'username' => $roleName . 'u',
            'password' => 'CorrectHorse1',
        ])->assertRedirect();

        $this->assertTrue($this->app['auth']->guard('web')->check(), 'login ke baad guard authenticated hona chahiye');

        // /security/password = sabse halka authenticated page (auth + 2fa + password.fresh)
        $response = $this->freshRequest('https://panel.test:8090/security/password');

        $this->assertNotSame(500, $response->status(), 'global-scope recursion se 500 (ResellerScopeProvider v1 bug)');
        $this->assertSame(200, $response->status(), 'session se user resolve hoke page 200 dena chahiye');
    }

    /** @return array<string, array{0: string}> */
    public static function rolesProvider(): array
    {
        return [
            'root'     => ['root'],
            'reseller' => ['reseller'],
            'user'     => ['user'],
            'mail'     => ['mail'],
        ];
    }

    public function test_dashboard_also_survives_a_fresh_request(): void
    {
        $this->grantResellerScope();
        config()->set('acp.entry_ports_file', '/nonexistent/acp-entry-ports.json');
        $this->makeUser('root', 'rootu');

        $this->post('https://panel.test:8090/login', ['username' => 'rootu', 'password' => 'CorrectHorse1']);
        $response = $this->freshRequest('https://panel.test:8090/dashboard');

        $this->assertNotSame(500, $response->status(), 'dashboard par 500 = recursion wapas aa gaya');
    }

    // ------------------------------------------------- scoping abhi bhi sahi

    public function test_reseller_user_scope_still_applies(): void
    {
        $this->grantResellerScope();

        $reseller = $this->makeUser('reseller', 'res1');
        $this->makeUser('user', 'mine', $reseller->id);
        $this->makeUser('user', 'theirs');

        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $visible = User::query()->pluck('username')->all();

        $this->assertContains('res1', $visible, 'reseller khud ko dekh sakta hai');
        $this->assertContains('mine', $visible, 'apna banaya hua user dikhta hai');
        $this->assertNotContains('theirs', $visible, 'doosre ka user nahi dikhna chahiye');
        $this->assertSame(3, User::query()->withoutGlobalScope('reseller_scope')->count(),
            'scope hataane par teeno users maujood hain (yaani scope hi chhupa raha hai)');
    }

    public function test_root_sees_every_user(): void
    {
        $this->grantResellerScope();

        $root = $this->makeUser('root', 'rootu');
        $this->makeUser('reseller', 'res1', $root->id);
        $this->makeUser('user', 'cust1');

        $this->actingAs($root->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $this->assertSame(
            ['cust1', 'res1', 'rootu'],
            User::query()->orderBy('username')->pluck('username')->all(),
            'root par scope laagu nahi hota',
        );
    }

    public function test_account_scope_does_not_break_queries(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');

        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $this->assertSame(0, Account::query()->count(), 'khaali accounts table par scope crash nahi karna chahiye');
    }

    public function test_guest_queries_are_unscoped(): void
    {
        $this->grantResellerScope();
        $this->makeUser('root', 'rootu');
        $this->makeUser('reseller', 'res1');
        $this->makeUser('user', 'cust1');

        // CLI / seeder / login-controller: koi actor nahi -> koi scope nahi
        $this->assertSame(3, User::query()->count());
    }

    // ------------------------------------------------- create guard (rule 3)

    public function test_reseller_cannot_create_equal_or_higher_role(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');
        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->makeUser('reseller', 'res2', $reseller->id);
    }

    public function test_reseller_can_create_lower_role(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');
        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true]);

        $made = $this->makeUser('user', 'cust1', $reseller->id);

        $this->assertDatabaseHas('users', ['username' => 'cust1', 'created_by' => $reseller->id]);
        $this->assertSame($reseller->id, (int) $made->created_by);
    }

    public function test_root_can_create_any_role(): void
    {
        $this->grantResellerScope();
        $root = $this->makeUser('root', 'rootu');
        $this->actingAs($root->fresh())->withSession(['two_factor_passed' => true]);

        $this->makeUser('reseller', 'res1', $root->id);

        $this->assertDatabaseHas('users', ['username' => 'res1']);
    }
}
PHPEOF
  install_test EntryLoginTest "${WORK}/EntryLoginTest.php"
  install_test SessionAuthTest "${WORK}/SessionAuthTest.php"
  say "     chalane ke liye (dev deps chahiye): cd ${PANEL} && sudo -u ${PANEL_USER} ${PHPBIN} artisan test --filter EntryLoginTest"
  say "                                          cd ${PANEL} && sudo -u ${PANEL_USER} ${PHPBIN} artisan test --filter SessionAuthTest"
else
  warn "${TESTDIR} nahi mila — tests install skip"
fi

# ============================================ STEP 10: FINAL VERDICT
hdr "Step 10: FINAL VERDICT"
RC=0
if [[ -n "${PROBE_USER}" && -n "${PROBE_PASS}" ]]; then
  OUT2="$(login_probe "after" "${PROBE_USER}" "${PROBE_PASS}" 2>&1)"; RC=$?
fi
{
if [[ -n "${PROBE_USER}" && -n "${PROBE_PASS}" ]]; then
  printf '%s\n' "${OUT2}"
  say ""
  if [[ ${RC} -eq 0 ]]; then
    say "${C_G}==> LOGIN FIX COMPLETE ✅  (${PROBE_USER} dashboard tak pahunch gaya)${C_0}"
  else
    say "${C_Y}==> login abhi bhi nahi hua. Upar 'SCREEN PAR:' aur laravel.log ki lines bhejo.${C_0}"
    say "    report: ${REPORT}"
  fi
else
  CODE="$(hit)"
  say "panel HTTP: ${CODE}"
  if [[ "${CODE}" == "200" ]]; then
    say "${C_G}==> FIX APPLY HO GAYA ✅  (live login test skip hua tha — browser se try karo)${C_0}"
  else
    say "${C_Y}==> panel HTTP ${CODE} — pehle panel-doctor chalao${C_0}"
  fi
fi
say ""
say "Backup : ${BK}"
say "Report : ${REPORT}"
say "Rollback: sudo bash $0 --rollback"
say ""
say "Entry separation ab kaise chalti hai:"
say "  * gate SIRF tab active hota hai jab nginx sach me 2083/2096 par sun raha ho"
say "  * abhi: $( [[ -f "${ENTRY_FILE}" ]] && tr -d '\n' < "${ENTRY_FILE}" | cut -c1-160 || echo '(truth file nahi)' )"
say "  * separation LIVE karni ho:  sudo bash $0 --enable-ports"
say "  * truth file haath se dekhni ho:  acp-entry-ports --print"
} 2>&1 | tee_report

# ------------------------------------------------------------------ sync
command -v alphacp-sync >/dev/null 2>&1 && { hdr "GitHub sync"; alphacp-sync 2>&1 | tail -5 || true; }
exit 0
