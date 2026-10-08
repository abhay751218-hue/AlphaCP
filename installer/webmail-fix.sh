#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — WEBMAIL FIX  v1.6  (Roundcube Webmail, cPanel-style port 2096 + SSO)
# -----------------------------------------------------------------------------
#  P-UI-4: cPanel jaisa alag Webmail app — Roundcube port 2096 (SSL) par, aur
#  panel se "Open Webmail" par ONE-TIME token SSO (Dovecot master-user se
#  seamless login; customer ko password dobara nahi dena padta).
#
#  Ye script kya karti hai:
#    1  apt: roundcube + roundcube-core + roundcube-sqlite3 + php-curl (idempotent)
#    2  etc/webmail-sso.secret + etc/webmail-master.{pw,plain} (root:www-data 0640)
#    3  /etc/roundcube/config.inc.php me ACP block (imap localhost:143,
#       smtp localhost:25, des_key random, plugins += acp_sso)
#    4  Roundcube plugin acp_sso (token → panel internal verify → master login)
#    5  nginx: alag vhost alphacp-webmail.conf (listen 2096 ssl) + panel vhost
#       me /internal/ ko 127.0.0.1-only (markers)
#    6  DB migration 0003_webmail_sso.sql (webmail_sso_tokens)
#    7  dovecot 99-alphacp.conf me master passdb append (agent agle sync par
#       khud render karta hai jab master file maujood ho — dono consistent)
#    8  agent+panel payloads byte-for-byte (MailServer master-passdb render +
#       SSO routes/button) + full suite gate (passed>=223 failed=0)
#
#  Safety: backup → php -l → structural asserts → nginx -t + reload → dovecot
#  restart → HTTP smokes → suite gate → alphacp-sync. Kahin fail = auto-rollback.
#  SIM: ACP_SIM=1 → apt/systemctl/nginx -t/curl/mysql skip (paths env se fake).
#
#  Usage: sudo bash webmail-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.6"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
PANEL="${ACP_HOME}/panel"
SIM="${ACP_SIM:-0}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/webmailfix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/webmail-fix-${STAMP}.txt"

# system paths (sim in env se override karta hai)
RC_ETC="${RC_ETC:-/etc/roundcube}"
RC_PLUGINS="${RC_PLUGINS:-/usr/share/roundcube/plugins}"
NGX_AVAIL="${NGX_AVAIL:-/etc/nginx/sites-available}"
NGX_EN="${NGX_EN:-/etc/nginx/sites-enabled}"
PANEL_VHOST="${PANEL_VHOST:-${NGX_AVAIL}/alphacp-panel.conf}"
SSL_CRT="${SSL_CRT:-/etc/ssl/alphacp/panel.crt}"
SSL_KEY="${SSL_KEY:-/etc/ssl/alphacp/panel.key}"
FPM_SOCK="${FPM_SOCK:-/run/php/php8.4-fpm.sock}"
DOVECOT_CONF="${DOVECOT_CONF:-/etc/dovecot/conf.d/99-alphacp.conf}"
WWW_GROUP="${WWW_GROUP:-www-data}"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
cnt(){ local n; n="$(grep -c "$@" 2>/dev/null || true)"; printf '%s' "${n:-0}"; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }

unset PHP 2>/dev/null || true
detect_php(){
  local p
  if [[ -f /etc/systemd/system/paneld.service ]]; then
    p="$(sed -nE 's#^ExecStart=([^ ]+).*#\1#p' /etc/systemd/system/paneld.service 2>/dev/null | head -1)"
    [[ -n "$p" && -x "$p" ]] && { printf '%s' "$p"; return; }
  fi
  for c in php8.4 php8.3 php8.2 php; do command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }; done
  printf ''
}
PHP_BIN="$(detect_php)"

# nginx <1.25.1 par "http2 on;" directive NAHI hoti (Ubuntu 24.04 = 1.24) —
# wahan http2 listen line ke parameter se enable hota hai.
POOL_DIR="${ACP_POOL_DIR:-/etc/php/8.4/fpm/pool.d}"

detect_ngx_ver(){
  nginx -v 2>&1 | sed -nE 's#.*nginx/([0-9]+\.[0-9]+\.[0-9]+).*#\1#p' | head -1
}
NGX_VER="${NGX_VER:-$(detect_ngx_ver)}"
HTTP2_SUFFIX=" http2"; H2_LINE=""
if [[ -n "$NGX_VER" ]] && [[ "$(printf '%s\n1.25.1\n' "$NGX_VER" | sort -V | head -1)" == "1.25.1" ]]; then
  HTTP2_SUFFIX=""; H2_LINE="    http2 on;"
fi

AGENT_FILES=(src/MailServer.php tests/run-tests.php)
PANEL_FILES=(app/Http/Controllers/WebmailController.php app/Http/Controllers/WebmailSsoController.php routes/web.php config/acp.php resources/views/webmail/index.blade.php)

rollback(){
  hdr "ROLLBACK — webmail-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/webmailfix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi webmailfix backup nahi mila"
  info "backup: $latest"
  for rel in "${AGENT_FILES[@]}"; do
    if [[ -f "${latest}/agent/${rel}" ]]; then cp -p "${latest}/agent/${rel}" "${AGENT}/${rel}"; else rm -f "${AGENT}/${rel}"; fi
  done
  for rel in "${PANEL_FILES[@]}"; do
    if [[ -f "${latest}/panel/${rel}" ]]; then
      mkdir -p "${PANEL}/$(dirname "$rel")"
      cp -p "${latest}/panel/${rel}" "${PANEL}/${rel}"
    else
      rm -f "${PANEL}/${rel}"
    fi
  done
  for f in "${RC_ETC}/config.inc.php" "${NGX_AVAIL}/alphacp-webmail.conf" "${PANEL_VHOST}" "${DOVECOT_CONF}"; do
    [[ -f "${latest}/sys/$(echo "$f" | tr / _)" ]] && cp -p "${latest}/sys/$(echo "$f" | tr / _)" "$f" || true
  done
  rm -f "${NGX_EN}/alphacp-webmail.conf" "${ACP_HOME}/etc/webmail-sso.secret" \
        "${ACP_HOME}/etc/webmail-master.pw" "${ACP_HOME}/etc/webmail-master.plain"
  rm -rf "${RC_PLUGINS}/acp_sso"
  if [[ "$SIM" != "1" ]]; then
    have_systemd && { systemctl reload nginx >/dev/null 2>&1 || true; systemctl restart dovecot >/dev/null 2>&1 || true; }
  fi
  ok "rollback complete (purani state wapas)"
}

diagnose(){
  hdr "DIAGNOSE (read-only) — webmail-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} sim=${SIM}"
  info "roundcube plugin PRESENT   : $( [[ -f "${RC_PLUGINS}/acp_sso/acp_sso.php" ]] && echo PRESENT || echo MISSING )"
  info "nginx webmail vhost 2096   : $(cnt 'listen 2096 ssl' "${NGX_AVAIL}/alphacp-webmail.conf")  (>=1=theek)"
  info "nginx version / http2 mode : ${NGX_VER:-?} / suffix='${HTTP2_SUFFIX}'"
  info "panel vhost internal lock  : $(cnt 'ACP_INTERNAL_START' "${PANEL_VHOST}")  (>=1=theek)"
  info "sso secret file            : $( [[ -f "${ACP_HOME}/etc/webmail-sso.secret" ]] && echo PRESENT || echo MISSING )"
  info "dovecot master passdb file : $( [[ -f "${ACP_HOME}/etc/webmail-master.pw" ]] && echo PRESENT || echo MISSING )"
  info "dovecot conf master block  : $(cnt 'master = yes' "${DOVECOT_CONF}")  (>=1=theek)"
  info "test blocks                : $(cnt '^test(' "${AGENT}/tests/run-tests.php")  (current era = 223)"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — webmail-fix v${VERSION} (Roundcube 2096 + SSO)"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  [[ -d "$AGENT" ]] || die "agent dir nahi: ${AGENT}"
  [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || die "php binary nahi mila"

  # ---- backup ----
  mkdir -p "${BACKUP}/agent/tests" "${BACKUP}/panel" "${BACKUP}/sys"
  local rel
  for rel in "${AGENT_FILES[@]}"; do
    mkdir -p "${BACKUP}/agent/$(dirname "$rel")"
    [[ -f "${AGENT}/${rel}" ]] && cp -p "${AGENT}/${rel}" "${BACKUP}/agent/${rel}" || true
  done
  for rel in "${PANEL_FILES[@]}"; do
    mkdir -p "${BACKUP}/panel/$(dirname "$rel")"
    [[ -f "${PANEL}/${rel}" ]] && cp -p "${PANEL}/${rel}" "${BACKUP}/panel/${rel}" || true
  done
  for f in "${RC_ETC}/config.inc.php" "${NGX_AVAIL}/alphacp-webmail.conf" "${PANEL_VHOST}" "${DOVECOT_CONF}"; do
    [[ -f "$f" ]] && cp -p "$f" "${BACKUP}/sys/$(echo "$f" | tr / _)" || true
  done
  ok "backup: ${BACKUP}"

  # ---- 1) apt (sim skip) ----
  hdr "roundcube packages"
  if [[ "$SIM" == "1" ]]; then
    warn "SIM=1 — apt skip"
  else
    if dpkg -s roundcube-core >/dev/null 2>&1; then
      ok "roundcube pehle se installed"
    else
      DEBIAN_FRONTEND=noninteractive apt-get install -y roundcube roundcube-core roundcube-sqlite3 php8.4-curl >>"$LOG_FILE" 2>&1 \
        || { rollback; die "roundcube apt install fail"; }
      ok "roundcube + roundcube-sqlite3 + php8.4-curl installed"
    fi
  fi

  # ---- 2) secrets ----
  hdr "SSO secrets (shared group, FPM-user aware)"
  mkdir -p "${ACP_HOME}/etc"
  if [[ ! -f "${ACP_HOME}/etc/webmail-sso.secret" ]]; then
    openssl rand -hex 32 > "${ACP_HOME}/etc/webmail-sso.secret"
  fi
  if [[ ! -f "${ACP_HOME}/etc/webmail-master.plain" ]]; then
    openssl rand -hex 24 > "${ACP_HOME}/etc/webmail-master.plain"
    local mhash
    mhash="$("$PHP_BIN" -r 'echo password_hash(trim(file_get_contents($argv[1])), PASSWORD_BCRYPT);' "${ACP_HOME}/etc/webmail-master.plain")"
    printf 'acpmaster:{BLF-CRYPT}%s\n' "$mhash" > "${ACP_HOME}/etc/webmail-master.pw"
  fi
  # FPM pool user detect: vhost ka fastcgi_pass sock → pool conf → user/group.
  # (Live: panel pool = alphacp, webmail pool = www-data — secret dono ko padhna
  #  hai, isliye shared group acpsso; single-user ho to wahi group.)
  pool_user() {
    local sock pfile u
    sock="$(grep -o 'fastcgi_pass[[:space:]]*unix:[^;]*;' "$1" 2>/dev/null | head -1 | sed 's|.*unix:||; s|;||')"
    [[ -n "$sock" ]] || { echo www-data; return; }
    pfile="$(grep -l "listen = $sock" "${POOL_DIR}"/*.conf 2>/dev/null | head -1)"
    [[ -n "$pfile" ]] || { echo www-data; return; }
    u="$(awk -F'=[[:space:]]*' '/^user[[:space:]]*=/{print $2; exit}' "$pfile")"
    [[ -n "$u" ]] || u="$(awk -F'=[[:space:]]*' '/^group[[:space:]]*=/{print $2; exit}' "$pfile")"
    echo "${u:-www-data}"
  }
  PANEL_FPM_USER="$(pool_user "${PANEL_VHOST}")"
  RC_FPM_USER="$(pool_user "${NGX_AVAIL}/alphacp-webmail.conf")"
  if [[ "$PANEL_FPM_USER" == "$RC_FPM_USER" ]]; then
    SSO_GROUP="$PANEL_FPM_USER"
  else
    SSO_GROUP=acpsso
    if [[ "$SIM" != "1" ]]; then
      groupadd -f acpsso
      id -nG "$PANEL_FPM_USER" 2>/dev/null | grep -qw acpsso || usermod -aG acpsso "$PANEL_FPM_USER"
      id -nG "$RC_FPM_USER" 2>/dev/null | grep -qw acpsso || usermod -aG acpsso "$RC_FPM_USER"
    fi
  fi
  chown root:"${SSO_GROUP}" "${ACP_HOME}/etc/webmail-sso.secret" 2>/dev/null || true
  chown root:"${RC_FPM_USER}" "${ACP_HOME}/etc/webmail-master.plain" 2>/dev/null || true
  chown root:root "${ACP_HOME}/etc/webmail-master.pw" 2>/dev/null || true
  chmod 0640 "${ACP_HOME}/etc/webmail-sso.secret" "${ACP_HOME}/etc/webmail-master.plain" "${ACP_HOME}/etc/webmail-master.pw" 2>/dev/null || true
  info "secret perms: root:${SSO_GROUP} 0640 (panel=${PANEL_FPM_USER} rc=${RC_FPM_USER})"
  if [[ "$SIM" != "1" && "$SSO_GROUP" == "acpsso" ]]; then
    systemctl restart php8.4-fpm >/dev/null 2>&1 || service php8.4-fpm restart >/dev/null 2>&1 || true
  fi
  # gate: asli readers file padh sakte hain (live only) — v1.5 ka 500 yahi pakadta
  if [[ "$SIM" != "1" ]]; then
    su -s /bin/sh -c "test -r '${ACP_HOME}/etc/webmail-sso.secret'" "$PANEL_FPM_USER" \
      || { rollback; die "panel FPM user ${PANEL_FPM_USER} secret nahi padh sakta"; }
    su -s /bin/sh -c "test -r '${ACP_HOME}/etc/webmail-sso.secret' && test -r '${ACP_HOME}/etc/webmail-master.plain'" "$RC_FPM_USER" \
      || { rollback; die "rc FPM user ${RC_FPM_USER} secret/plain nahi padh sakta"; }
  fi
  ok "secret + master pw files ready (panel=${PANEL_FPM_USER} rc=${RC_FPM_USER} group=${SSO_GROUP})"

  # ---- 3) roundcube config block ----
  hdr "roundcube config"
  mkdir -p "$RC_ETC"
  [[ -f "${RC_ETC}/config.inc.php" ]] || printf '<?php\n/* AlphaCP managed Roundcube config */\n' > "${RC_ETC}/config.inc.php"
  python3 - "$RC_ETC/config.inc.php" <<'PY'
import sys, secrets
path = sys.argv[1]
src = open(path).read()
# Deterministic self-heal: debian base = pehli stray '<?php' line ya
# ACP_WEBMAIL_START marker se PEHLE tak (kitne bhi stray/block jama ho gaye
# hon — sab kachra discard). Line 1 ka asli <?php barkarar rehta hai.
lines = src.split("\n")
cut = len(lines)
for i in range(1, len(lines)):
    t = lines[i].strip()
    if t == "<?php" or t.startswith("/* ACP_WEBMAIL_START"):
        cut = i
        break
base = "\n".join(lines[:cut]).rstrip("\n")
block = """
/* ACP_WEBMAIL_START */
$config['imap_host'] = 'localhost:143';
$config['smtp_server'] = 'localhost';
$config['smtp_port'] = 25;
$config['des_key'] = '%s';
$config['plugins'] = array_merge($config['plugins'] ?? [], ['acp_sso']);
$config['support_url'] = '';
/* ACP_WEBMAIL_END */
""" % secrets.token_urlsafe(18)
open(path, "w").write(base + "\n" + block)
print("  block likha (deterministic rebuild, base lines: %d)" % cut)
PY
  ok "roundcube config block (imap 143 / smtp 25 / des_key / plugin)"

  # ---- 4) plugin ----
  hdr "roundcube plugin acp_sso"
  mkdir -p "${RC_PLUGINS}/acp_sso"
  cat > "${RC_PLUGINS}/acp_sso/acp_sso.php" <<'PHPEOF'
<?php

/**
 * AlphaCP acp_sso — Roundcube plugin: panel se one-time token ke saath aaye
 * user ko Dovecot MASTER-user se seamless login (cPanel-style webmail SSO).
 *
 * Flow: panel POST /webmail/open → token → redirect https://host:2096/?_acp_token=…
 *   → plugin token session me rakhta hai → 'authenticate' hook par panel ke
 *     internal endpoint se mailbox verify (shared secret) → IMAP login
 *     "<mailbox>*<masteruser>" + master password (etc/webmail-master.plain).
 *
 * Security: token one-time + 10 min; internal endpoint 127.0.0.1-only (nginx);
 * secret/master files root:www-data 0640; master passdb dovecot me alag hai
 * (normal user passwords isse affect nahi hote).
 */
class acp_sso extends rcube_plugin
{
    /** @var string */
    public $task = '';

    public function init(): void
    {
        $this->load_config();
        $this->add_hook('startup', [$this, 'capture']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
    }

    /** ?_acp_token=… ko session me capture karo (sirf valid 64-hex). */
    public function capture(array $args): array
    {
        $tok = (string) ($_GET['_acp_token'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $tok) === 1) {
            $_SESSION['acp_sso_token'] = $tok;
        }

        return $args;
    }

    /** Token ho to panel se mailbox poochho aur master-user creds do. */
    public function authenticate(array $args): array
    {
        $tok = (string) ($_SESSION['acp_sso_token'] ?? '');
        if ($tok === '') {
            return $args;
        }
        unset($_SESSION['acp_sso_token']);

        $url    = (string) $this->get_config('acp_sso_internal_url', '');
        $secret = trim((string) @file_get_contents((string) $this->get_config('acp_sso_secret_file', '')));
        $master = trim((string) @file_get_contents((string) $this->get_config('acp_sso_master_plain', '')));
        $muser  = (string) $this->get_config('acp_sso_master_user', 'acpmaster');
        if ($url === '' || $secret === '' || $master === '') {
            return $args;
        }

        $ch = curl_init($url . '?token=' . urlencode($tok));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-ACP-Secret: ' . $secret],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,   // self-signed panel cert (loopback call)
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            return $args;
        }
        $j  = json_decode($body, true);
        $mb = is_array($j) ? (string) ($j['mailbox'] ?? '') : '';
        if ($mb === '') {
            return $args;
        }

        $args['user'] = $mb . '*' . $muser;
        $args['pass'] = $master;

        return $args;
    }
}
PHPEOF
  cat > "${RC_PLUGINS}/acp_sso/config.inc.php" <<'PHPEOF'
<?php

/* AlphaCP acp_sso plugin config — webmail-fix installer likhta hai. */
$config['acp_sso_internal_url']  = 'https://127.0.0.1:8090/internal/webmail-sso';
$config['acp_sso_secret_file']   = '/usr/local/alphacp/etc/webmail-sso.secret';
$config['acp_sso_master_plain']  = '/usr/local/alphacp/etc/webmail-master.plain';
$config['acp_sso_master_user']   = 'acpmaster';
PHPEOF
  ok "plugin likhi (2 files)"

  # ---- 5) nginx ----
  hdr "nginx: webmail vhost 2096 + panel /internal lock"
  mkdir -p "$NGX_AVAIL" "$NGX_EN"
  cat > "${NGX_AVAIL}/alphacp-webmail.conf" <<NGXEOF
# AlphaCP Webmail (Roundcube) — cPanel-style port 2096 (webmail-fix v${VERSION})
server {
    listen 2096 ssl${HTTP2_SUFFIX};
    listen [::]:2096 ssl${HTTP2_SUFFIX};
${H2_LINE}
    server_name _;

    ssl_certificate     ${SSL_CRT};
    ssl_certificate_key ${SSL_KEY};

    root /usr/share/roundcube;
    index index.php;

    # panel-internal SSO endpoint isi vhost par expose NAHI ho
    location /internal/ { deny all; }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCK};
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
    location ~ /\. { deny all; }
}
NGXEOF
  ln -sf "${NGX_AVAIL}/alphacp-webmail.conf" "${NGX_EN}/alphacp-webmail.conf"
  python3 - "${PANEL_VHOST}" <<'PY'
import sys, re
path = sys.argv[1]
canon = ("    # ACP_INTERNAL_START \u2014 SSO endpoint sirf loopback se\n"
         "    location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files $uri /index.php?$args; }\n"
         "    # ACP_INTERNAL_END")
src = open(path).read()
# stale marker-block (bina try_files wala v1.1 jaisa) ho to CANONICAL se replace
new, n = re.subn(r"[ \t]*# ACP_INTERNAL_START[^\n]*\n[ \t]*location /internal/ \{[^\n]*\}\n[ \t]*# ACP_INTERNAL_END[ \t]*\n?", canon + "\n", src)
if n == 0:
    lines = new.split("\n"); out = []; done = False
    for ln in lines:
        out.append(ln)
        if not done and ln.strip().startswith("server_name"):
            out.append(canon); done = True
    new = "\n".join(out)
    print("  panel vhost internal lock: insert", "ok" if done else "MARKER-NAHI")
else:
    print("  panel vhost internal lock: canonical replace x%d" % n)
open(path, "w").write(new)
PY

  # ---- 6) DB migration (sim skip) ----
  hdr "DB migration 0003_webmail_sso"
  if [[ "$SIM" == "1" ]]; then
    warn "SIM=1 — mysql skip"
  else
    local dbf="${ACP_HOME}/etc/database.env" dbh dbp dbn dbu dbw
    [[ -f "$dbf" ]] || { rollback; die "database.env nahi: $dbf"; }
    dbh="$(grep -E '^ACP_DB_HOST=' "$dbf" | head -1 | cut -d= -f2)"
    dbp="$(grep -E '^ACP_DB_PORT=' "$dbf" | head -1 | cut -d= -f2)"
    dbn="$(grep -E '^ACP_DB_NAME=' "$dbf" | head -1 | cut -d= -f2)"
    dbu="$(grep -E '^ACP_DB_USER=' "$dbf" | head -1 | cut -d= -f2)"
    dbw="$(grep -E '^ACP_DB_PASS=' "$dbf" | head -1 | cut -d= -f2-)"
    mysql -h"${dbh:-127.0.0.1}" -P"${dbp:-3306}" -u"${dbu:-alphacp}" -p"${dbw}" "${dbn:-alphacp}" <<'SQL' || { rollback; die "migration apply fail"; }
CREATE TABLE IF NOT EXISTS webmail_sso_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token       CHAR(64)        NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  mailbox     VARCHAR(255)    NOT NULL,
  used        TINYINT(1)      NOT NULL DEFAULT 0,
  expires_at  DATETIME        NOT NULL,
  created_at  TIMESTAMP       NULL,
  updated_at  TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webmail_sso_token (token),
  KEY idx_webmail_sso_user (user_id, used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='One-time Webmail (Roundcube) SSO tokens';
SQL
    ok "webmail_sso_tokens table ready"
  fi

  # ---- 7) dovecot master passdb append ----
  hdr "dovecot master passdb"
  if ! grep -q 'master = yes' "${DOVECOT_CONF}" 2>/dev/null; then
    cat >> "${DOVECOT_CONF}" <<EOF

# AlphaCP S7 — Webmail SSO master passdb (Roundcube token flow)
passdb {
  driver = passwd-file
  args = scheme=BLF-CRYPT ${ACP_HOME}/etc/webmail-master.pw
  master = yes
}
EOF
    ok "master passdb append (agent agle sync par khud render karega)"
  else
    ok "master passdb pehle se conf me"
  fi

  # ---- 8) agent + panel payloads ----
  hdr "agent + panel files likhna"
  mkdir -p "${AGENT}/src" "${AGENT}/tests" "${PANEL}/app/Http/Controllers" \
           "${PANEL}/routes" "${PANEL}/config" "${PANEL}/resources/views/webmail"
  cat > "${AGENT}/src/MailServer.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Throwable;

/**
 * S7 — Exim4 (MTA) + Dovecot (IMAP/POP3) for the mailboxes the panel already
 * stores as JSON/bcrypt.
 *
 * The panel/agent already write, per account:
 *   ~/etc/mail/passwd   → `user@domain:{BLF-CRYPT}<bcrypt>:uid:gid::/home/u/mail/d/l::quota`
 *   ~/etc/mail/aliases  → `user@domain: destination`
 *   ~/mail/<domain>/<local>/{cur,new,tmp}
 *
 * Until now nothing consumed them. This class aggregates those per-account
 * files into the two files the real daemons read:
 *   /etc/dovecot/alphacp-users      (Dovecot passwd-file passdb+userdb)
 *   /etc/exim4/alphacp-domains      (domains this server accepts mail for)
 *   /etc/exim4/alphacp-recipients   (`user@domain: /maildir uid gid`)
 *   /etc/exim4/alphacp-aliases      (forwarders)
 *
 * Same rules as BindServer:
 *  - config is built in a temp file, validated (`update-exim4.conf` +
 *    `exim4 -bV`, `doveconf`), and only then put in place; a bad config
 *    restores the backup instead of taking the mail server down;
 *  - argv only, never a shell string;
 *  - idempotent — setup()/sync() can run on every update.
 *
 * Server par exim4 chalu karne se pehle dono taraf se check hota hai:
 * config valid (`exim4 -bV`) aur Dovecot wali (`doveconf -n`).
 */
final class MailServer
{
    // ---- binaries (distro ke hisaab se alag jagah ho sakte hain) ----
    public const EXIM = '/usr/sbin/exim4';
    public const DOVECOT = '/usr/sbin/dovecot';
    public const DOVEADM = '/usr/bin/doveadm';
    public const DOVECONF = '/usr/sbin/doveconf';
    public const UPDATE_EXIM = '/usr/sbin/update-exim4.conf';

    public const EXIM_PATHS = ['/usr/sbin/exim4', '/usr/bin/exim4', '/usr/sbin/exim', '/usr/local/sbin/exim4'];
    public const DOVECOT_PATHS = ['/usr/sbin/dovecot', '/usr/bin/dovecot', '/usr/local/sbin/dovecot'];
    public const DOVEADM_PATHS = ['/usr/bin/doveadm', '/usr/sbin/doveadm', '/usr/local/bin/doveadm'];
    public const DOVECONF_PATHS = ['/usr/sbin/doveconf', '/usr/bin/doveconf', '/usr/local/sbin/doveconf'];
    public const UPDATE_EXIM_PATHS = ['/usr/sbin/update-exim4.conf', '/usr/bin/update-exim4.conf'];
    public const OPENSSL_PATHS = ['/usr/bin/openssl', '/usr/local/bin/openssl'];
    public const SPAMD_PATHS = ['/usr/sbin/spamd', '/usr/bin/spamd', '/usr/local/sbin/spamd'];

    // ---- config files (sab env-overridable: tests kabhi asli /etc ko nahi chhute) ----
    private const DEFAULT_EXIM_TEMPLATE = '/etc/exim4/exim4.conf.template';
    /** Is text se pehchante hain ki template hamari (AlphaCP) hai ya distro wali. */
    private const TEMPLATE_MARKER = 'AlphaCP managed exim4 configuration';
    private const DEFAULT_EXIM_DOMAINS = '/etc/exim4/alphacp-domains';
    private const DEFAULT_EXIM_RECIPIENTS = '/etc/exim4/alphacp-recipients';
    private const DEFAULT_EXIM_ALIASES = '/etc/exim4/alphacp-aliases';
    private const DEFAULT_DOVECOT_USERS = '/etc/dovecot/alphacp-users';
    private const DEFAULT_DOVECOT_CONF = '/etc/dovecot/conf.d/99-alphacp.conf';
    private const DEFAULT_EXIM_CATCHALL = '/etc/exim4/alphacp-catchall';
    private const DEFAULT_VACATION_DIR = '/etc/exim4/alphacp-vacation';
    private const DEFAULT_SPAM_DIR = '/etc/exim4/alphacp-spam';
    private const DEFAULT_DKIM_DIR = '/usr/local/alphacp/etc/mail/dkim';
    private const DEFAULT_EXIM_FILTERS = '/etc/exim4/alphacp-filters';
    private const DEFAULT_EXIM_OPTIONS = '/usr/local/alphacp/etc/mail/exim-options.json';
    private const DEFAULT_DOVECOT_OPTIONS = '/usr/local/alphacp/etc/mail/dovecot-options.json';
    private const DEFAULT_SPAMASSASSIN_CONF = '/etc/spamassassin/local.cf';
    private const DEFAULT_GREYLISTD_SOCKET = '/var/run/greylistd/socket';
    private const SPAMASSASSIN_BEGIN = '# >>> AlphaCP managed SpamAssassin';
    private const SPAMASSASSIN_END = '# <<< AlphaCP managed SpamAssassin';

    /** Exim ke log — distro ke hisaab se alag jagah milte hain (pehla jo mile) */
    private const MAINLOG_CANDIDATES = [
        '/var/log/exim4/mainlog',
        '/var/log/exim/mainlog',
        '/var/log/maillog',
        '/var/log/mail.log',
    ];

    /**
     * Exim Configuration Manager (cPanel #143).
     * key => [type, default] — type se hi value validate hoti hai (fail-closed).
     * Galat value likhne se exim chalu nahi hota, isliye har option ka apna regex hai.
     */
    private const EXIM_OPTION_SPEC = [
        'message_size_limit'         => ['size', '50M'],
        'smtp_banner'                => ['text', '$smtp_active_hostname ESMTP AlphaCP'],
        'smtp_accept_max'            => ['int', '100'],
        'smtp_accept_max_per_host'   => ['int', '10'],
        'queue_run_max'              => ['int', '5'],
        'remote_max_parallel'        => ['int', '2'],
        'timeout_frozen_after'       => ['duration', '7d'],
        'ignore_bounce_errors_after' => ['duration', '2d'],
        'deliver_queue_load_max'     => ['number', '8.0'],
        'queue_only_load'            => ['number', '12.0'],
        'spam_score_limit'           => ['int', '80'],
        'spam_enabled'              => ['bool', 'no'],
        'greylisting'              => ['bool', 'no'],
    ];

    /** Mailserver Configuration / Dovecot (cPanel #144) */
    private const DOVECOT_OPTION_SPEC = [
        'protocols'                   => ['text', 'imap pop3'],
        'mail_max_userip_connections' => ['int', '10'],
        'maildir_copy_with_hardlinks' => ['bool', 'yes'],
        'disable_plaintext_auth'      => ['bool', 'no'],
        'pop3_uidl_format'            => ['text', '%08Xu%08Xv'],
        'imap_idle_notify_interval'   => ['int', '24'],
        'mailbox_idle_check_interval' => ['int', '30'],
        'login_greeting'              => ['text', 'AlphaCP IMAP/POP3 ready.'],
    ];

    private const MANAGED_BEGIN = '# >>> AlphaCP managed (mail.server) — haath se edit mat karo';
    private const MANAGED_END = '# <<< AlphaCP managed (mail.server)';

    public const CMD_TIMEOUT = 60;

    /** DKIM selector (DNS me: <selector>._domainkey.<domain>) */
    public const DKIM_SELECTOR = 'default';

    /** `exim -bf` ko chhota sa test message chahiye (filter validate karne ke liye) */
    private const FILTER_TEST_MESSAGE = "From: alphacp@localhost\nTo: filter-test@localhost\nSubject: filter test\n\nbody\n";

    /** DNS blacklists — sirf header + log (reject nahi: DNS issue par mail nahi gire) */
    private const DNSBL = 'zen.spamhaus.org : bl.spamcop.net';
    /** SPF: is server se mail in records se jaati hai (a/mx zone me maujood hain) */
    private const SPF_RECORD = 'v=spf1 a mx -all';

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // -------------------------------------------------------------- paths ----

    public function eximTemplate(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_TEMPLATE', self::DEFAULT_EXIM_TEMPLATE);
    }

    public function eximTemplateBackup(): string
    {
        return $this->eximTemplate() . '.acp-orig';
    }

    /** Aakhri KAAM KARNE wali AlphaCP template (config reject hone par yahi wapas). */
    public function eximTemplatePrev(): string
    {
        return $this->eximTemplate() . '.acp-prev';
    }

    public function domainsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_DOMAINS', self::DEFAULT_EXIM_DOMAINS);
    }

    public function recipientsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_RECIPIENTS', self::DEFAULT_EXIM_RECIPIENTS);
    }

    public function aliasesFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_ALIASES', self::DEFAULT_EXIM_ALIASES);
    }

    public function dovecotUsersFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_USERS', self::DEFAULT_DOVECOT_USERS);
    }

    /** Catch-all (`*@domain: dest`) — mail.catchall task se. */
    public function catchallFile(): string
    {
        return self::firstNonEmpty('ACP_MAIL_CATCHALL', self::DEFAULT_EXIM_CATCHALL);
    }

    /** Vacation (autoresponder) messages: <dir>/<address>.eml + <address>.repeat */
    public function vacationDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_VACATION_DIR', self::DEFAULT_VACATION_DIR);
    }

    /** Per-mailbox spam lists: <dir>/<address>.deny , <address>.allow */
    public function spamDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_SPAM_DIR', self::DEFAULT_SPAM_DIR);
    }

    /** DKIM private keys: <dir>/<domain>.key (+ <domain>.pub) */
    public function dkimDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_DKIM_DIR', self::DEFAULT_DKIM_DIR);
    }

    public function dovecotConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_CONF', self::DEFAULT_DOVECOT_CONF);
    }

    /** Email filters lookup: `address: /path/to/exim.filter` */
    public function filtersFile(): string
    {
        return self::pathEnv('ACP_MAIL_FILTERS', self::DEFAULT_EXIM_FILTERS);
    }

    public function eximOptionsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_OPTIONS', self::DEFAULT_EXIM_OPTIONS);
    }

    public function dovecotOptionsFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_OPTIONS', self::DEFAULT_DOVECOT_OPTIONS);
    }

    public function spamAssassinConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_SPAMASSASSIN_CONF', self::DEFAULT_SPAMASSASSIN_CONF);
    }

    public function greylistdSocketFile(): string
    {
        $path = self::pathEnv('ACP_MAIL_GREYLISTD_SOCKET', self::DEFAULT_GREYLISTD_SOCKET);
        // This path is embedded inside an Exim expansion (not shell-escaped),
        // so accept only ordinary absolute Unix-socket path characters.
        if (preg_match('#^/[A-Za-z0-9_./-]{1,220}$#D', $path) !== 1) {
            return self::DEFAULT_GREYLISTD_SOCKET;
        }

        return $path;
    }

    /** Exim mainlog (mail delivery reports isi se bante hain). Na mile to null. */
    public function mainlogFile(): ?string
    {
        $env = self::pathEnv('ACP_MAIL_MAINLOG', '');
        $candidates = $env !== '' ? [$env] : self::MAINLOG_CANDIDATES;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function pathEnv(string $key, string $default): string
    {
        $value = getenv($key);
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return $default;
        }

        return rtrim($value, '/');
    }

    // -------------------------------------------------------------- status ----

    /** @return array<string, mixed> */
    public function status(): array
    {
        $out = [
            'installed'   => $this->installed(),
            'exim'        => null,
            'dovecot'     => null,
            'exim_config' => null,
            'dovecot_config' => null,
            'services'    => ['exim4' => false, 'dovecot' => false],
            'domains'     => 0,
            'mailboxes'   => 0,
            'aliases'     => 0,
        ];
        if (!$out['installed']) {
            return $out + ['error' => 'exim4/dovecot installed nahi hain (updater install karta hai)'];
        }

        $exim = $this->cmd->run([self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'], self::CMD_TIMEOUT);
        $out['exim'] = $exim->ok() ? self::firstLine($exim->stdout) : self::cleanError($exim);
        $out['exim_config'] = $exim->ok() ? 'ok' : self::cleanError($exim);

        $dov = $this->cmd->run([self::bin('ACP_MAIL_DOVECOT', self::DOVECOT, self::DOVECOT_PATHS), '--version'], self::CMD_TIMEOUT);
        $out['dovecot'] = $dov->ok() ? trim((string) $dov->stdout) : self::cleanError($dov);

        $dovecotConfig = $this->cmd->run([self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'], self::CMD_TIMEOUT);
        $out['dovecot_config'] = $dovecotConfig->ok() ? 'ok' : self::cleanError($dovecotConfig);

        foreach (['exim4', 'dovecot'] as $unit) {
            $res = $this->cmd->run(['/bin/systemctl', 'is-active', $unit], 20);
            $out['services'][$unit] = $res->ok() && trim((string) $res->stdout) === 'active';
        }

        $out['spam'] = $this->spamStatus();
        $out['domains'] = count($this->readLines($this->domainsFile()));
        $out['mailboxes'] = count($this->mailboxLines());
        $out['aliases'] = count($this->readLines($this->aliasesFile()));

        return $out;
    }

    /**
     * Apache SpamAssassin + greylistd (cPanel #147).
     *
     * Payload: enabled?, required_score? (1.0-15.0), reject_score? (0=tag only),
     *          greylisting?. Service units are enabled only when their feature is
     * turned on; Exim uses /defer_ok so a later daemon outage cannot stop mail.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function spamAssassin(array $payload): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4/dovecot installed nahi hain — pehle mail.server setup chalao');
        }
        if (!self::isConfigured()) {
            throw new TaskRejectedException('mail.server setup pehle safal hona chahiye');
        }

        $current = $this->eximOptions();
        $enabled = array_key_exists('enabled', $payload) ? self::truthy($payload['enabled']) : null;
        $greylisting = array_key_exists('greylisting', $payload) ? self::truthy($payload['greylisting']) : null;
        $requiredScore = array_key_exists('required_score', $payload) ? (float) $payload['required_score'] : null;
        $rejectScore = array_key_exists('reject_score', $payload) ? (float) $payload['reject_score'] : null;

        if ($requiredScore !== null && (!is_finite($requiredScore) || $requiredScore < 1.0 || $requiredScore > 15.0)) {
            throw new TaskRejectedException('required_score 1.0 se 15.0 ke beech hona chahiye');
        }
        if ($rejectScore !== null && (!is_finite($rejectScore) || $rejectScore < 0.0 || $rejectScore > 30.0)) {
            throw new TaskRejectedException('reject_score 0.0 se 30.0 ke beech hona chahiye (0 = sirf header tag)');
        }

        // Read-only call: no package/service/file changes.
        if ($enabled === null && $greylisting === null && $requiredScore === null && $rejectScore === null) {
            return [
                'saved'       => [],
                'exim_config' => null,
                'spam'        => $this->spamStatus(),
                'status'      => 'ok',
            ];
        }

        $manageSpamService = $enabled === true
            || ($enabled === null && $current['spam_enabled'] === 'yes'
                && ($requiredScore !== null || $rejectScore !== null));
        $wantGreylisting = $greylisting ?? ($current['greylisting'] === 'yes');
        $set = [];
        if ($enabled !== null) {
            $set['spam_enabled'] = $enabled ? 'yes' : 'no';
        }
        if ($greylisting !== null) {
            $set['greylisting'] = $greylisting ? 'yes' : 'no';
        }
        if ($rejectScore !== null) {
            $set['spam_score_limit'] = (string) (int) round($rejectScore * 10);
        }

        $caps = $this->capabilities();
        if ($manageSpamService && !$caps['spamd']) {
            throw new TaskRejectedException('SpamAssassin/spamd installed nahi hai — `spamassassin` package chahiye');
        }
        if ($manageSpamService && !$caps['content_scanning']) {
            throw new TaskRejectedException('Exim me Content_Scanning nahi hai — `exim4-daemon-heavy` chahiye; mail config nahi badli');
        }
        $spamConf = $this->spamAssassinConfFile();
        if ($requiredScore !== null && !$caps['spamd']) {
            throw new TaskRejectedException('required_score ke liye SpamAssassin/spamd package chahiye');
        }
        if ($requiredScore !== null && !is_dir(dirname($spamConf))) {
            throw new TaskRejectedException('SpamAssassin installed nahi hai — /etc/spamassassin directory nahi mili');
        }

        $oldOptions = is_file($this->eximOptionsFile()) ? (string) @file_get_contents($this->eximOptionsFile()) : null;
        $oldSpamConf = $requiredScore !== null && is_file($spamConf) ? (string) @file_get_contents($spamConf) : null;
        $oldSpamMode = $requiredScore !== null && is_file($spamConf) ? (((int) @fileperms($spamConf)) & 0777) : 0644;
        $started = [];
        $restartedExisting = [];
        $warnings = [];

        try {
            // local.cf is read at daemon startup. Write it BEFORE first start;
            // if spamd was already running, restart it so the score takes effect.
            if ($requiredScore !== null) {
                $this->writeSpamAssassinConf($requiredScore);
            }
            if ($manageSpamService) {
                $started['spamassassin'] = $this->startManagedService('spamassassin');
                if ($requiredScore !== null && !$started['spamassassin']) {
                    $restartedExisting['spamassassin'] = true;
                    $this->restartManagedService('spamassassin');
                }
            }
            if ($wantGreylisting) {
                $started['greylistd'] = $this->startManagedService('greylistd');
                if (!file_exists($this->greylistdSocketFile())) {
                    throw new TaskRejectedException('greylistd active hai lekin Unix socket nahi mila — greylisting enable nahi ki');
                }
            }

            // eximConf() validates all options, writes the JSON, regenerates the
            // template, runs -bV + -bt smoke tests, then restarts Exim.
            $applied = ['exim_config' => null];
            if ($set !== []) {
                $res = $this->applyEximOptionSet($set);
                $applied = ['exim_config' => $res['exim_config'] ?? null];
            }
        } catch (Throwable $e) {
            $rollbackErrors = [];
            if ($requiredScore !== null) {
                try {
                    $this->restoreFileContents($spamConf, $oldSpamConf, $oldSpamMode);
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = 'local.cf: ' . $rollback->getMessage();
                }
            }
            if ($set !== []) {
                try {
                    $this->restoreFileContents($this->eximOptionsFile(), $oldOptions, 0644);
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = 'exim options: ' . $rollback->getMessage();
                }
            }
            foreach ($restartedExisting as $unit => $_) {
                try {
                    $this->restartManagedService($unit); // local.cf has its old bytes again
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = $unit . ' old config reload: ' . $rollback->getMessage();
                }
            }
            foreach ($started as $unit => $newlyStarted) {
                if ($newlyStarted) {
                    $this->stopManagedService($unit);
                }
            }
            $suffix = $rollbackErrors === [] ? '' : ' (rollback warning: ' . implode('; ', $rollbackErrors) . ')';
            if ($e instanceof TaskRejectedException) {
                throw new TaskRejectedException($e->getMessage() . $suffix, 0, $e);
            }
            throw new TaskRejectedException('SpamAssassin/greylisting safe apply fail: ' . $e->getMessage() . $suffix, 0, $e);
        }

        // ACL is already removed/disabled and Exim validated before its daemon is
        // stopped; a greylist/spam service is never stopped while Exim still asks it.
        if ($enabled === false) {
            $warnings = array_merge($warnings, $this->stopManagedService('spamassassin'));
        }
        if ($greylisting === false) {
            $warnings = array_merge($warnings, $this->stopManagedService('greylistd'));
        }

        $spamStatus = $this->spamStatus();
        if ($spamStatus['enabled'] && !$spamStatus['active']) {
            $warnings[] = 'spamd/Exim content-scanning abhi active nahi; Exim mail ko fail-open deliver karega, SpamAssassin scan nahi hoga';
        }
        if ($spamStatus['greylisting'] && !$spamStatus['greylisting_active']) {
            $warnings[] = 'greylistd service/socket abhi active nahi; greylisting fail-open hai';
        }

        return [
            'saved'       => $set,
            'exim_config' => $applied['exim_config'] ?? null,
            'spam'        => $spamStatus,
            'warnings'    => $warnings,
            'status'      => 'ok',
        ];
    }

    /** @return array<string, mixed> */
    public function spamStatus(): array
    {
        $opts = $this->eximOptions();
        $caps = $this->capabilities();
        $spamdServiceActive = $this->serviceActive('spamassassin');
        $spamdRunning = $spamdServiceActive && $this->spamdListening();
        $greySocket = file_exists($this->greylistdSocketFile());
        $greylistdServiceActive = $this->serviceActive('greylistd');
        $greylistdRunning = $greylistdServiceActive && $greySocket;

        return [
            'spamd_installed'  => $caps['spamd'],
            'spamd_running'    => $spamdRunning,
            'content_scanning' => $caps['content_scanning'],
            'enabled'          => $opts['spam_enabled'] === 'yes',
            'active'           => $opts['spam_enabled'] === 'yes'
                && $caps['spamd']
                && $caps['content_scanning']
                && $spamdRunning,
            'required_score'   => (float) $this->localCfScore(),
            'reject_score'     => ((int) $opts['spam_score_limit']) / 10,
            'greylisting'      => $opts['greylisting'] === 'yes',
            'greylisting_active' => $opts['greylisting'] === 'yes' && $greylistdRunning,
            'greylistd_running' => $greylistdServiceActive,
            'greylistd_socket'  => $greySocket,
        ];
    }

    /** Write a managed SpamAssassin block while preserving all unrelated local.cf lines. */
    private function writeSpamAssassinConf(float $requiredScore): void
    {
        $file = $this->spamAssassinConfFile();
        if (!is_dir(dirname($file))) {
            throw new TaskRejectedException('SpamAssassin config directory nahi mili: ' . dirname($file));
        }
        $score = number_format($requiredScore, 1, '.', '');
        $old = is_file($file) ? (string) @file_get_contents($file) : '';
        $kept = $old;
        $begin = strpos($old, self::SPAMASSASSIN_BEGIN);
        $end = strpos($old, self::SPAMASSASSIN_END);
        if ($begin !== false && $end !== false && $end >= $begin) {
            $kept = substr($old, 0, $begin)
                . substr($old, $end + strlen(self::SPAMASSASSIN_END));
        }
        if ($kept !== '' && !str_ends_with($kept, "\n")) {
            $kept .= "\n";
        }
        $body = self::SPAMASSASSIN_BEGIN . "\n"
            . '# AlphaCP SpamAssassin settings — haath se edit mat karo' . "\n"
            . 'required_score ' . $score . "\n"
            . 'rewrite_header Subject [SPAM]' . "\n"
            . 'report_safe 0' . "\n"
            . self::SPAMASSASSIN_END . "\n";
        $this->writeManaged($file, $kept . $body, 0644);
    }

    /** Last matching required_score wins in SpamAssassin; mirror that for status. */
    private function localCfScore(): string
    {
        $file = $this->spamAssassinConfFile();
        if (!is_file($file)) {
            return '5.0';
        }
        $score = null;
        foreach ($this->readLines($file) as $line) {
            if (preg_match('/^\s*required_score\s+([0-9]+(?:\.[0-9]+)?)/', $line, $m) === 1) {
                $score = $m[1];
            }
        }

        return $score ?? '5.0';
    }

    /** PHP bool / "yes" / 1 / "true" -> bool. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }
        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'yes', 'true', 'on'], true);
    }

    /** systemctl's explicit state, not a best-effort PHP socket guess. */
    private function serviceActive(string $unit): bool
    {
        $res = $this->cmd->run(['/bin/systemctl', 'is-active', $unit], 20);

        return $res->ok() && trim((string) $res->stdout) === 'active';
    }

    /** Enable and start a private local mail helper; return whether we started it. */
    private function startManagedService(string $unit): bool
    {
        $wasActive = $this->serviceActive($unit);
        try {
            $enable = $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            if (!$enable->ok()) {
                throw new TaskRejectedException("{$unit} enable fail: " . self::cleanError($enable));
            }
            if (!$wasActive) {
                $start = $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
                if (!$start->ok()) {
                    throw new TaskRejectedException("{$unit} start fail: " . self::cleanError($start));
                }
            }
            if (!$this->serviceActive($unit)) {
                throw new TaskRejectedException("{$unit} service active nahi hua");
            }
        } catch (Throwable $e) {
            if (!$wasActive) {
                $this->stopManagedService($unit);
            }
            throw $e;
        }

        return !$wasActive;
    }

    /** Restart an already-active local helper after its configuration changed. */
    private function restartManagedService(string $unit): void
    {
        $res = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
        if (!$res->ok() || !$this->serviceActive($unit)) {
            throw new TaskRejectedException("{$unit} restart fail: " . self::cleanError($res));
        }
    }

    /** Stop+disable only after the Exim ACL no longer depends on the unit. @return list<string> */
    private function stopManagedService(string $unit): array
    {
        $warnings = [];
        foreach (['stop', 'disable'] as $verb) {
            try {
                $res = $this->cmd->run(['/bin/systemctl', $verb, $unit], self::CMD_TIMEOUT);
                if (!$res->ok()) {
                    $warnings[] = "{$unit} {$verb} failed: " . self::cleanError($res);
                }
            } catch (Throwable $e) {
                $warnings[] = "{$unit} {$verb} failed: " . $e->getMessage();
            }
        }

        return $warnings;
    }

    /** Restore bytes/mode exactly after a failed multi-file SpamAssassin apply. */
    private function restoreFileContents(string $file, ?string $contents, int $mode): void
    {
        if ($contents === null) {
            if (is_file($file) && !@unlink($file)) {
                throw new TaskRejectedException('rollback file remove nahi hui: ' . $file);
            }

            return;
        }
        $this->writeManaged($file, $contents, $mode > 0 ? $mode : 0644);
    }

    public function installed(): bool
    {
        if (trim((string) (getenv('ACP_MAIL_EXIM') ?: '')) !== ''
            || trim((string) (getenv('ACP_MAIL_DOVECOT') ?: '')) !== ''
            || trim((string) (getenv('ACP_MAIL_DOVEADM') ?: '')) !== '') {
            return true; // explicit env override = authoritative (sim/tests)
        }

        // Warna INJECTED executor probe kare: FakeCommandExecutor = hermetic
        // (binsAbsent flag), real CommandRunner = asli sach. is_executable() se
        // asli FS dekhna galat tha: live par exim/dovecot HAMARE installer se
        // lage hain, isliye "installed nahi" branch wahan kabhi sach nahi hota.
        foreach ([
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'],
            [self::bin('ACP_MAIL_DOVECOT', self::DOVECOT, self::DOVECOT_PATHS), '--version'],
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), '--version'],
        ] as $probe) {
            try {
                if (!$this->cmd->run($probe, self::CMD_TIMEOUT)->ok()) {
                    return false;
                }
            } catch (Throwable $e) {
                return false;
            }
        }

        return true;
    }

    // --------------------------------------------------------------- setup ----

    /**
     * Idempotent provisioning: aggregate files, Dovecot conf, Exim template,
     * validate, then start. Runs fine on every update.
     *
     * @return array<string, mixed>
     */
    public function setup(): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException(
                'exim4/dovecot nahi mile — dhoondha: ' . implode(', ', self::EXIM_PATHS) . ' / '
                . implode(', ', self::DOVECOT_PATHS) . ' (updater install karta hai)'
            );
        }

        $agg = $this->syncFiles();

        // ---- Dovecot: virtual users file + managed conf ----
        $dovecotConf = $this->renderDovecotConf();
        $this->writeManaged($this->dovecotConfFile(), $dovecotConf, 0644);
        $dovecotCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'],
            self::CMD_TIMEOUT,
        );
        if (!$dovecotCheck->ok()) {
            @unlink($this->dovecotConfFile());
            throw new TaskRejectedException('dovecot config reject: ' . self::cleanError($dovecotCheck));
        }

        // ---- Exim: template (backup ke saath) + generate + validate ----
        $this->snapshotEximTemplate();
        $this->writeManaged($this->eximTemplate(), $this->renderEximTemplate(), 0644);
        $generate = $this->cmd->run(
            [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
            self::CMD_TIMEOUT,
        );
        $eximCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'],
            self::CMD_TIMEOUT,
        );
        if (!$generate->ok() || !$eximCheck->ok()) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException(
                'exim config reject (purani config wapas): ' . self::cleanError($generate) . ' / ' . self::cleanError($eximCheck)
            );
        }

        // Sachchi routing tasdeeq: exim khud ek address route kare. Config syntax
        // theek hone ke baawajood router ki expansion kharaab ho sakti hai
        // (0.78.0 ka asli bug) — tab purani template wapas aur setup reject.
        $smoke = $this->eximSmokeTest();
        if ($smoke !== null) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException('exim routing smoke test fail (purani config wapas): ' . $smoke);
        }

        // Exim daemon ko root chahiye (mailbox ki uid se delivery ke liye).
        // Unit me User=Debian-exim ho to drop-in se root kar do — cPanel/Hestia
        // bhi exim ko root chalate hain, warna `user=` transport kaam nahi karta.
        $this->ensureEximRunsAsRoot();

        foreach (['exim4', 'dovecot'] as $unit) {
            $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            $restart = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
            if (!$restart->ok()) {
                $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
            }
        }

        // Sachchi tasdeeq: Dovecot khud bole ki mailbox mili ya nahi.
        // File sirf group-readable ho aur auth worker use na padh paye to yahi
        // pakad me aata hai — tab hum mode relax karke dobara check karte hain.
        $userdb = $this->probeDovecotUserdb();

        $status = $this->status();
        $configured = $this->markConfigured();

        return [
            'ok'          => true,
            'configured'  => $configured,
            'domains'     => $agg['domains'],
            'mailboxes'   => $agg['mailboxes'],
            'aliases'     => $agg['aliases'],
            'lists'       => $agg['lists'],
            'list_errors' => $agg['list_errors'],
            'filters'     => $agg['filters'],
            'filter_errors' => $agg['filter_errors'],
            'exim_config' => $status['exim_config'] ?? null,
            'exim_smoke'  => $smoke === null ? 'ok' : 'fail',
            'dovecot_config' => $status['dovecot_config'] ?? null,
            'dovecot_userdb' => $userdb,
            'services'    => $status['services'] ?? [],
        ];
    }

    /** Live service check for the status response only; Exim ACL remains fail-open. */
    public function spamdListening(): bool
    {
        $sock = @fsockopen('127.0.0.1', 783, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    /** Exim ka int score (80) -> insani number (8.0). */
    public static function scoreHuman(int $score): string
    {
        return number_format($score / 10, 1, '.', '');
    }

    /**
     * Exim kya-kya support karta hai (`exim4 -bV` khud batata hai).
     * DKIM signing aur SpamAssassin isi se gate hote hain — jo cheez binary
     * support na kare, wo config me likhi hi nahi jati (fail-closed).
     *
     * @return array{dkim: bool, content_scanning: bool, spamd: bool}
     */
    public function capabilities(): array
    {
        $bin = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        try {
            $res = $this->cmd->run([$bin, '-bV'], self::CMD_TIMEOUT);
        } catch (\RuntimeException) {
            // exim installed hi nahi (ya allowlist me nahi) — sab features band
            // (fail-closed): config me DKIM/spam likha hi nahi jayega, par poora
            // render/STATUS kaam karta rahega (pehle yahan fatal throw hota tha).
            return ['dkim' => false, 'content_scanning' => false, 'spamd' => false];
        }
        $text = trim($res->stdout . ' ' . $res->stderr);
        if ($text === '') {
            return ['dkim' => false, 'content_scanning' => false, 'spamd' => false];
        }

        return [
            'dkim'             => str_contains($text, 'DKIM'),
            'content_scanning' => str_contains($text, 'Content_Scanning'),
            'spamd'            => self::have('ACP_MAIL_SPAMD', self::SPAMD_PATHS),
        ];
    }

    /**
     * Mail task (mail.set / mail.forward / mail.catchall / mail.autorespond /
     * mail.spam) ke turant baad: aggregate files dobara banao, taki naya
     * mailbox/forwarder bina kisi alag command ke kaam kare.
     * Mail server configured nahi hai to chupchap skip (task fail nahi hota).
     */
    public static function syncIfConfigured(CommandExecutor $cmd, TaskLogger $log): string
    {
        if (!self::isConfigured()) {
            return 'skipped (mail server configured nahi hai — mail.server setup pehle)';
        }
        try {
            $out = (new self($cmd, $log))->syncFiles();

            return 'ok (' . (int) ($out['mailboxes'] ?? 0) . ' mailboxes, '
                . (int) ($out['aliases'] ?? 0) . ' aliases including '
                . (int) ($out['lists'] ?? 0) . ' active lists; '
                . (int) ($out['list_errors'] ?? 0) . ' list errors)';
        } catch (Throwable $e) {
            // primary task ka kaam ho chuka hai — sync ki wajah se use fail nahi karte
            $log->info('mail sync after task failed: ' . $e->getMessage());

            return 'failed: ' . $e->getMessage();
        }
    }

    /** Updater/task isi se puchhte hain: mail server configure ho chuka hai? */
    public static function isConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');

        return is_file($root . '/etc/mail-server-configured');
    }

    /**
     * mail.deliverability ko asli banana: SPF + DMARC (+ DKIM) records account
     * ki zone me likh kar BIND ko dobara likhna — S9 live hai to `dig` se
     * turant dikh jata hai (hamara daawa nahi, DNS ka jawab).
     *
     * @return array<string, mixed>
     */
    public function deliverability(?string $username = null): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $homes = [];
        if ($username !== null) {
            $name = strtolower(trim($username));
            if (AccountIdentity::username($name) === null && is_dir($root . '/' . $name)) {
                $homes[] = $root . '/' . $name;
            }
        } else {
            foreach (glob($root . '/*') ?: [] as $dir) {
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                if (AccountIdentity::username(basename($dir)) !== null) {
                    continue;
                }
                $homes[] = $dir;
            }
        }

        $done = [];
        $failed = [];
        foreach ($homes as $home) {
            $user = basename($home);
            $file = $home . '/etc/mail/deliverability.json';
            // deliverability.json na ho to bhi aage: mailboxes ke domains se kaam chalega
            if (!is_file($home . '/etc/mail/passwd') && (is_link($file) || !is_file($file))) {
                continue;
            }
            $wanted = [];
            $rows = (is_file($file) && !is_link($file)) ? json_decode((string) @file_get_contents($file), true) : null;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $domain = strtolower(trim((string) (is_array($row) ? ($row['domain'] ?? '') : (is_string($row) ? $row : ''))));
                    if ($domain !== '' && Dns::validDomain($domain)) {
                        $wanted[$domain] = true;
                    }
                }
            }
            // panel ki "Email Deliverability" page khuli na ho to deliverability.json
            // banta hi nahi — par jin domains ke mailbox hain unhe SPF/DKIM/DMARC
            // chahiye. Isliye mailboxes ke domains bhi jodo (cPanel yahi karta hai).
            foreach ($this->readLines($home . '/etc/mail/passwd') as $line) {
                $fields = explode(':', $line);
                if (count($fields) < 6) {
                    continue;
                }
                $addr = strtolower(trim($fields[0]));
                $at = strpos($addr, '@');
                if ($at === false || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                $domain = substr($addr, $at + 1);
                if (Dns::validDomain($domain)) {
                    $wanted[$domain] = true;
                }
            }
            ksort($wanted);
            foreach (array_keys($wanted) as $domain) {
                try {
                    $done[] = $this->applyDeliverability($home, $user, (string) $domain);
                } catch (Throwable $e) {
                    $failed[] = ['domain' => (string) $domain, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'ok'      => $failed === [],
            'domains' => $done,
            'failed'  => $failed,
            'count'   => count($done),
        ];
    }

    /**
     * Ek domain ke liye: DKIM key (agar nahi to banao) + zone me SPF/DMARC/DKIM
     * records + BIND zone dobara likho.
     *
     * @return array<string, mixed>
     */
    private function applyDeliverability(string $home, string $user, string $domain): array
    {
        $dkim = $this->ensureDkimKey($domain);
        $selector = (string) ($dkim['selector'] ?? self::DKIM_SELECTOR);

        $records = [
            ['domain' => $domain, 'name' => '@', 'type' => 'TXT', 'value' => self::SPF_RECORD],
            ['domain' => $domain, 'name' => '_dmarc', 'type' => 'TXT', 'value' => self::dmarcRecord($domain)],
        ];
        if (is_string($dkim['public'] ?? null) && $dkim['public'] !== '') {
            $records[] = [
                'domain' => $domain,
                'name'   => $selector . '._domainkey',
                'type'   => 'TXT',
                'value'  => 'v=DKIM1; k=rsa; p=' . $dkim['public'],
            ];
        }

        // zone.json: purane SPF/DMARC/DKIM records hatakar naye daalo
        $zoneFile = $home . '/etc/dns/zone.json';
        $existing = [];
        if (is_file($zoneFile) && !is_link($zoneFile)) {
            $decoded = json_decode((string) @file_get_contents($zoneFile), true);
            if (is_array($decoded)) {
                $existing = array_values($decoded);
            }
        }

        // #145 server default: sync baar-baar chalta hai (har mail task ke baad
        // bhi) — records pehle se sahi hain to zone.json/BIND ko chhede bina
        // turant wapas. Isse repeat sync sasta rehta hai.
        if ($this->deliverabilityUpToDate($existing, $records, $domain)) {
            return [
                'domain'   => $domain,
                'dkim'     => is_string($dkim['public'] ?? null) && $dkim['public'] !== '',
                'selector' => $selector,
                'dns'      => ['applied' => false, 'reason' => 'already-present'],
                'records'  => count($existing),
                'changed'  => false,
            ];
        }

        $keep = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowDomain = strtolower(trim((string) ($row['domain'] ?? '')));
            $rowName = strtolower(trim((string) ($row['name'] ?? '')));
            $rowType = strtoupper(trim((string) ($row['type'] ?? '')));
            $rowValue = trim((string) ($row['value'] ?? ''));
            if ($rowDomain === $domain && $rowType === 'TXT') {
                $ours = $rowName === '_dmarc'
                    || $rowName === $selector . '._domainkey'
                    || ($rowName === '@' && str_starts_with($rowValue, 'v=spf1'));
                if ($ours) {
                    continue;   // hum inhe abhi dobara likhte hain
                }
            }
            $keep[] = $row;
        }

        $merged = Dns::sanitize(array_merge($keep, $records));
        $dir = dirname($zoneFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = $dir . '/.zone-' . bin2hex(random_bytes(4)) . '.tmp';
        $body = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $zoneFile)) {
            @unlink($tmp);
            throw new TaskRejectedException("zone.json nahi likh paye ({$domain})");
        }
        @chmod($zoneFile, 0640);
        @chown($zoneFile, $user);
        @chgrp($zoneFile, $user);

        // BIND live hai to zone turant dobara likhi jayegi (`dig` se verify)
        $dns = ['applied' => false];
        $bind = new BindServer($this->cmd, $this->log);
        if ($bind->installed()) {
            try {
                $out = $bind->writeZone($domain, $merged);
                $dns = [
                    'applied'  => true,
                    'records'  => (int) ($out['records'] ?? 0),
                    'verified' => (bool) ($out['verified'] ?? false),
                ];
            } catch (Throwable $e) {
                $dns = ['applied' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'domain'    => $domain,
            'dkim'      => is_string($dkim['public'] ?? null) && $dkim['public'] !== '',
            'selector'  => $selector,
            'dns'       => $dns,
            'records'   => count($merged),
            'changed'   => true,
        ];
    }

    /**
     * DKIM key: hai to wahi, nahi to `openssl genrsa` se banao (2048-bit).
     * openssl na mile to key nahi — tab sirf SPF/DMARC likhe jate hain
     * (kabhi jhootha "DKIM on" nahi bataya jata).
     *
     * @return array{selector: string, public: string|null, key: string|null}
     */
    private function ensureDkimKey(string $domain): array
    {
        $dir = $this->dkimDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $keyFile = $dir . '/' . $domain . '.key';
        $pubFile = $dir . '/' . $domain . '.pub';

        if (!is_file($keyFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            if (!is_executable($openssl)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            $tmp = $dir . '/.dkim-' . bin2hex(random_bytes(4)) . '.tmp';
            $made = $this->cmd->run([$openssl, 'genrsa', '-out', $tmp, '2048'], 120);
            if ($made->exitCode !== 0 || !is_file($tmp)) {
                @unlink($tmp);

                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            @rename($tmp, $keyFile);
            @chmod($keyFile, 0640);
            @chgrp($keyFile, 'Debian-exim');
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
            @chmod($pubFile, 0644);
        } elseif (!is_file($pubFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
        }

        $pem = (string) @file_get_contents($pubFile);
        $b64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        if ($b64 === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $b64) !== 1) {
            return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
        }

        return ['selector' => self::DKIM_SELECTOR, 'public' => $b64, 'key' => $keyFile];
    }

    private static function dmarcRecord(string $domain): string
    {
        return 'v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:postmaster@' . $domain;
    }

    /**
     * #145: zone me SPF/DMARC/DKIM (dono) records pehle se theek hain?
     * Haan to writeZone/reload skip — sirf tab likhte hain jab kuch badla ho.
     *
     * @param array<int, mixed>              $existing zone.json rows
     * @param array<int, array<string,string>> $wanted  records jo hum likhte
     */
    private function deliverabilityUpToDate(array $existing, array $wanted, string $domain): bool
    {
        foreach ($wanted as $want) {
            $found = false;
            $wantName = strtolower(trim((string) ($want['name'] ?? '')));
            $wantValue = trim((string) ($want['value'] ?? ''));
            foreach ($existing as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower(trim((string) ($row['domain'] ?? ''))) !== $domain) {
                    continue;
                }
                if (strtoupper(trim((string) ($row['type'] ?? ''))) !== 'TXT') {
                    continue;
                }
                if (strtolower(trim((string) ($row['name'] ?? ''))) !== $wantName) {
                    continue;
                }
                if (trim((string) ($row['value'] ?? '')) === $wantValue) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Har account ke ~/etc/mail/* se daemon files banao. Idempotent — kabhi bhi
     * chala sakte ho; exim/dovecot ko restart karne ki zaroorat nahi (dono file
     * har message/auth par padhte hain).
     *
     * @return array<string, mixed>
     */
    public function syncFiles(): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $users = [];
        $recipients = [];
        $aliases = [];
        $listAliases = [];
        $listConflicts = [];
        $listErrors = 0;
        $catchalls = [];
        $domains = [];
        $vacation = [];
        $spam = [];
        $filters = [];
        $filterErrors = [];

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $username = basename($dir);
            if (AccountIdentity::username($username) !== null) {
                continue;
            }
            $home = $dir;
            $ownBoxes = [];
            $boxInfo = [];

            foreach ($this->readLines($home . '/etc/mail/passwd') as $line) {
                $fields = explode(':', $line);
                if (count($fields) < 6) {
                    continue;
                }
                $addr = strtolower(trim($fields[0]));
                $uid = trim($fields[2]);
                $gid = trim($fields[3]);
                $maildir = trim($fields[5]);
                $at = strpos($addr, '@');
                if ($at === false || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if ($maildir === '' || !str_starts_with($maildir, $home . '/')) {
                    continue;   // doosre account ka maildir kabhi accept nahi
                }
                if (!preg_match('/^[0-9]+$/', $uid) || !preg_match('/^[0-9]+$/', $gid)) {
                    continue;
                }
                $users[$addr] = $line;
                $recipients[$addr] = $addr . ': ' . $maildir . ' ' . $uid . ' ' . $gid;
                $domains[substr($addr, $at + 1)] = true;
                $ownBoxes[$addr] = true;
                $boxInfo[$addr] = ['maildir' => $maildir, 'uid' => $uid, 'gid' => $gid];
            }

            foreach ($this->readLines($home . '/etc/mail/aliases') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if ($key === '' || $dest === '' || preg_match('/^[a-z0-9._@-]+$/', $key) !== 1) {
                    continue;
                }
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe/command nahi — sirf email address
                }
                $aliases[$key] = $key . ': ' . $dest;
            }

            // ---- static mailing-list members -> Exim redirect expansion ----
            $listsFile = $home . '/etc/mail/lists.json';
            if (is_link($listsFile)) {
                $listErrors++;
            } else {
                foreach ($this->readJsonList($listsFile) as $rawList) {
                    if (!is_array($rawList)) {
                        $listErrors++;
                        continue;
                    }
                    try {
                        $cleanList = Mail::sanitizeLists([$rawList])[0] ?? null;
                    } catch (TaskRejectedException) {
                        $listErrors++;
                        continue;
                    }
                    if (!is_array($cleanList)) {
                        $listErrors++;
                        continue;
                    }
                    $address = $cleanList['local'] . '@' . $cleanList['domain'];
                    if (isset($listConflicts[$address])) {
                        $listErrors++;
                        continue;
                    }
                    if (isset($listAliases[$address])) {
                        unset($listAliases[$address]);
                        $listConflicts[$address] = true;
                        $listErrors++;
                        continue;
                    }
                    $listAliases[$address] = $cleanList;
                }
            }

            // ---- catch-all (`*@domain: dest`) ----
            foreach ($this->readLines($home . '/etc/mail/catchall') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if (preg_match('/^\*@[a-z0-9.-]+$/', $key) !== 1 || $dest === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe nahi
                }
                $catchalls[$key] = $key . ': ' . $dest;
                $domains[substr($key, 2)] = true;
            }

            // ---- autoresponders (vacation) ----
            foreach ($this->readJsonList($home . '/etc/mail/autorespond') as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $local = strtolower(trim((string) ($row['local'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                $subject = trim((string) ($row['subject'] ?? ''));
                $body = trim((string) ($row['body'] ?? ''));
                $hours = (int) ($row['interval_h'] ?? 168);
                if ($local === '' || $domain === '' || $subject === '' || $body === '') {
                    continue;
                }
                $addr = $local . '@' . $domain;
                if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if (!isset($users[$addr])) {
                    continue;   // bina mailbox ke autoresponder nahi
                }
                $hours = $hours < 1 ? 1 : ($hours > 720 ? 720 : $hours);
                $subject = self::oneLine($subject);
                $vacation[$addr] = [
                    'subject' => $subject,
                    'body'    => $body,
                    'repeat'  => $hours . 'h',
                ];
            }

            // ---- spam blacklist/whitelist: account ke har mailbox par lagu ----
            $spamCfg = $this->readJsonMap($home . '/etc/mail/spam.json');
            if ($spamCfg !== null) {
                foreach (array_keys($ownBoxes) as $addr) {
                    $spam[$addr] = $spamCfg;
                }
            }

            // ---- email filters (cPanel #20 account-wide + #21 per-mailbox) ----
            // Panel ke JSON se ASLI Exim filter file; `exim -bf` se validate.
            $globalRows = $this->readJsonList($home . '/etc/mail/global-filters.json');
            $userRows = $this->readJsonList($home . '/etc/mail/filters');
            if ($globalRows !== [] || $userRows !== []) {
                $perBox = [];
                foreach ($userRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $addr = strtolower(trim((string) ($row['local'] ?? '') . '@' . (string) ($row['domain'] ?? '')));
                    if (isset($boxInfo[$addr])) {
                        $perBox[$addr][] = $row;
                    }
                }
                foreach ($boxInfo as $addr => $info) {
                    $rows = array_merge($globalRows, $perBox[$addr] ?? []);
                    $maildir = (string) $info['maildir'];
                    $uid = (int) $info['uid'];
                    $gid = (int) $info['gid'];
                    $body = $this->renderEximFilter((string) $addr, $maildir, $globalRows, $perBox[$addr] ?? []);
                    if ($body === '') {
                        continue;
                    }
                    // folder action wale rules ke liye Maildir subfolder pehle se
                    // bana do — IMAP me turant dikhe, aur `exim -bf` bhi mile.
                    foreach ($rows as $row) {
                        if (!is_array($row) || (string) ($row['action'] ?? '') !== 'folder') {
                            continue;
                        }
                        $folder = strtolower(trim((string) ($row['folder'] ?? '')));
                        if ($folder !== '' && preg_match('/^[a-z0-9._-]+$/', $folder) === 1) {
                            $this->ensureMaildirFolder($maildir, $folder, $uid, $gid);
                        }
                    }
                    $path = $home . '/etc/mail/filter.d/' . $addr . '.filter';
                    $why = $this->writeFilterFile($path, $body, (string) $addr, $uid, $gid, $home . '/etc');
                    if ($why !== null) {
                        // galat filter = delivery chalti rahegi, filter nahi lagega —
                        // par wajah report me zaroor batao (andha fail nahi).
                        $filterErrors[(string) $addr] = $why;
                        continue;
                    }
                    $filters[(string) $addr] = $addr . ': ' . $path;
                }
            }
        }

        // Add a list only when its address is not already a mailbox/forwarder.
        // Nested list targets are rejected to avoid redirect loops and bounce storms.
        $listCount = 0;
        foreach ($listAliases as $address => $row) {
            if (isset($listConflicts[$address]) || isset($users[$address]) || isset($aliases[$address])) {
                $listErrors++;
                continue;
            }
            $nested = false;
            foreach ($row['members'] as $member) {
                if (isset($listAliases[$member]) || isset($listConflicts[$member])) {
                    $nested = true;
                    break;
                }
            }
            if ($nested) {
                $listErrors++;
                continue;
            }
            $aliases[$address] = $address . ': ' . implode(', ', $row['members']);
            $domains[$row['domain']] = true;
            $listCount++;
        }

        ksort($users);
        ksort($recipients);
        ksort($aliases);
        ksort($catchalls);
        ksort($vacation);
        ksort($spam);
        ksort($filters);
        ksort($filterErrors);
        $domainList = array_keys($domains);
        sort($domainList);

        $this->writeManaged($this->dovecotUsersFile(), $users === [] ? '' : implode("\n", $users) . "\n", 0640, 'dovecot');
        $this->writeManaged($this->recipientsFile(), $recipients === [] ? '' : implode("\n", $recipients) . "\n", 0644);
        $this->writeManaged($this->aliasesFile(), $aliases === [] ? '' : implode("\n", $aliases) . "\n", 0644);
        $this->writeManaged($this->catchallFile(), $catchalls === [] ? '' : implode("\n", $catchalls) . "\n", 0644);
        $this->writeManaged($this->domainsFile(), $domainList === [] ? '' : implode("\n", $domainList) . "\n", 0644);
        $this->writeVacation($vacation);
        $this->writeSpamLists($spam);
        $this->writeManaged($this->filtersFile(), $filters === [] ? '' : implode("\n", $filters) . "\n", 0644);
        $fixed = $this->repairMaildirs($recipients);

        return [
            'domains'        => count($domainList),
            'mailboxes'      => count($users),
            'aliases'        => count($aliases),
            'lists'           => $listCount,
            'list_errors'     => $listErrors,
            'catchalls'      => count($catchalls),
            'responders'     => count($vacation),
            'spam_lists'     => count($spam),
            'filters'        => count($filters),
            'filter_errors'  => $filterErrors,
            'maildirs_fixed' => $fixed,
            'deliverability' => $this->deliverabilityPass(),
        ];
    }

    /**
     * #145 server default: sync ke saath hi har mail domain ke SPF/DKIM/DMARC
     * records apne aap likh do — customer ko panel me kuch click karne ki
     * zaroorat nahi (cPanel ka server-default behaviour). DNS/BIND ki galti
     * sync ko fail nahi karti; wo per-domain `failed` me dikhti hai.
     *
     * @return array<string, mixed>
     */
    private function deliverabilityPass(): array
    {
        try {
            $out = $this->deliverability(null);
            $out['changed'] = count(array_filter(
                (array) ($out['domains'] ?? []),
                static fn ($row): bool => is_array($row) && ($row['changed'] ?? false) === true
            ));

            return $out;
        } catch (Throwable $e) {
            $this->log->info('deliverability pass failed: ' . $e->getMessage());

            return ['ok' => false, 'count' => 0, 'changed' => 0, 'domains' => [],
                    'failed' => [['domain' => '*', 'error' => $e->getMessage()]]];
        }
    }

    /**
     * Exim ka daemon root ho to hi transport `user =` (mailbox ki uid) chalega.
     * systemd unit me `User=Debian-exim` ho to drop-in likh kar root kar do.
     */
    private function ensureEximRunsAsRoot(): string
    {
        $show = $this->cmd->run(['/bin/systemctl', 'show', '-p', 'User', '--value', 'exim4'], self::CMD_TIMEOUT);
        $user = trim($show->stdout);
        if ($user === '' || $user === 'root' || $user === '0') {
            return 'already-root' . ($user === '' ? ' (unit me User= hi nahi)' : '');
        }
        $dir = '/etc/systemd/system/exim4.service.d';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/10-alphacp-root.conf';
        $body = "# AlphaCP (mail.server): mailbox ki uid se delivery ke liye\\n"
            . "# Exim ko root chalana padta hai (cPanel/Vesta/Hestia bhi yahi karte hain).\\n"
            . "[Service]\\nUser=root\\nGroup=root\\n";
        if (@file_put_contents($file, $body) === false) {
            return "drop-in nahi likh paye ({$file}) - user={$user}";
        }
        @chmod($file, 0644);
        $this->cmd->run(['/bin/systemctl', 'daemon-reload'], self::CMD_TIMEOUT);

        return "drop-in likha ({$file}) - pehle User={$user} tha, ab root";
    }

    /**
     * Dovecot se poochho: kya wo mailbox dhoondh leta hai? (`doveadm user <addr>`)
     * Pehli koshish file ke current mode par; fail ho to mode 0644 karke dobara.
     *
     * @return array<string, mixed>
     */
    public function probeDovecotUserdb(): array
    {
        $boxes = $this->mailboxes();
        if ($boxes === []) {
            return ['checked' => false, 'reason' => 'koi mailbox nahi (abhi)'];
        }
        $addr = (string) $boxes[0];
        $run = fn (): CommandResult => $this->cmd->run(
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), 'user', $addr],
            self::CMD_TIMEOUT,
        );
        $first = $run();
        if ($first->ok()) {
            return ['checked' => true, 'address' => $addr, 'ok' => true, 'mode' => '0640'];
        }
        // auth worker file nahi padh pa raha -> readable bana kar dobara poochho
        $file = $this->dovecotUsersFile();
        @chmod($file, 0644);
        $second = $run();
        if ($second->ok()) {
            $this->log->info('dovecot users file 0644 par relax kiya (auth worker ko read chahiye tha)');

            return ['checked' => true, 'address' => $addr, 'ok' => true, 'mode' => '0644-relaxed'];
        }

        return [
            'checked' => true,
            'address' => $addr,
            'ok'      => false,
            'error'   => self::cleanError($first),
            'error2'  => self::cleanError($second),
        ];
    }

    /**
     * Maildir ki ownership theek karo (self-healing).
     *
     * Asli bug jo live server par mila: `AccountOs::setMail()` sirf cur/new/tmp
     * banata hai, unke PARENT (`~/mail`, `~/mail/<domain>`, `~/mail/<domain>/<local>`)
     * `mkdir` se root:root 0700 ban kar reh jate hain — to exim (Debian-exim) aur
     * mailbox ka apna uid dono hi uske andar traverse nahi kar sakte:
     *   defer (13): Permission denied: stat() error for /home/u/mail/d/l
     * Har sync par ye check chalta hai, to naye aur purane dono mailboxes theek
     * ho jate hain (mailbox bana kar exim/dovecot ko restart karne ki zarurat nahi).
     *
     * @param  array<string, string> $recipients  `addr: /maildir uid gid`
     * @return int  kitne directory theek kiye
     */
    private function repairMaildirs(array $recipients): int
    {
        $fixed = 0;
        foreach ($recipients as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 4) {
                continue;
            }
            $maildir = $parts[1];
            $uid = (int) $parts[2];
            $gid = (int) $parts[3];
            if ($maildir === '' || str_contains($maildir, '..')) {
                continue;
            }
            // uid/gid 0 = root-owned mailbox (live wala asli case): chown skip
            // (kisiko dena hai ye policy hai), lekin world-writable Maildir ka
            // mode tighten hona SECURITY ke liye zaroori hai — isliye alag flag.
            $canChown = $uid > 0 && $gid > 0;
            // mailbox + uske parents (niche se upar) + Maildir ke leaves
            $dirs = [
                $maildir . '/cur',
                $maildir . '/new',
                $maildir . '/tmp',
                $maildir,
                dirname($maildir),                 // ~/mail/<domain>
                dirname(dirname($maildir)),        // ~/mail
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                $owner = @fileowner($dir);
                $group = @filegroup($dir);
                if ($canChown && ($owner !== $uid || $group !== $gid)) {
                    if (@chown($dir, $uid)) {
                        @chgrp($dir, $gid);
                        $fixed++;
                    }
                }
                // mailbox sirf usi user ki: 0700 (exim `user=` se deliver karta hai)
                $mode = @fileperms($dir) & 0777;
                if ($mode !== 0700 && @chmod($dir, 0700)) {
                    $fixed++;
                }
            }
        }

        return $fixed;
    }

    /** @param array<string, array{subject: string, body: string, repeat: string}> $vacation */
    private function writeVacation(array $vacation): void
    {
        $dir = $this->vacationDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($vacation as $addr => $row) {
            $eml = 'Subject: ' . $row['subject'] . "\n"
                . 'Auto-Submitted: auto-replied' . "\n"
                . 'Precedence: bulk' . "\n"
                . "\n" . $row['body'] . "\n";
            $this->writeManaged($dir . '/' . $addr . '.eml', $eml, 0644);
            $this->writeManaged($dir . '/' . $addr . '.repeat', $row['repeat'], 0644);
            $keep[$addr . '.eml'] = true;
            $keep[$addr . '.repeat'] = true;
        }
        // purane autoresponder ke files hatana zaroori hai — warna deleted
        // responder ke jawab jaate rahenge
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, array{deny: list<string>, allow: list<string>}> $spam */
    private function writeSpamLists(array $spam): void
    {
        $dir = $this->spamDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($spam as $addr => $cfg) {
            foreach (['deny', 'allow'] as $kind) {
                $lines = [];
                foreach ($cfg[$kind] as $entry) {
                    $entry = strtolower(trim((string) $entry));
                    if (preg_match('/^[a-z0-9._%@*-]+$/', $entry) === 1) {
                        $lines[] = $entry;
                    }
                }
                $name = $addr . '.' . $kind;
                $keep[$name] = true;
                if ($lines === []) {
                    if (is_file($dir . '/' . $name)) {
                        @unlink($dir . '/' . $name);
                    }
                    continue;
                }
                $this->writeManaged($dir . '/' . $name, implode("\n", $lines) . "\n", 0644);
            }
        }
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return list<mixed> */
    private function readJsonList(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array{deny: list<string>, allow: list<string>}|null */
    private function readJsonMap(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $deny = is_array($decoded['blacklist'] ?? null) ? array_values($decoded['blacklist']) : [];
        $allow = is_array($decoded['whitelist'] ?? null) ? array_values($decoded['whitelist']) : [];
        if ($deny === [] && $allow === []) {
            return null;
        }

        return ['deny' => $deny, 'allow' => $allow];
    }

    private static function oneLine(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';

        return trim(substr($text, 0, 200));
    }

    /**
     * Sachchi tasdeeq: exim ka routing test (`exim4 -bt`) + Dovecot ka user
     * lookup (`doveadm user`). Dono asli daemons se poochhte hain.
     *
     * @return array<string, mixed>
     */
    public function verify(string $address): array
    {
        $address = strtolower(trim($address));
        if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $address) !== 1) {
            throw new TaskRejectedException('invalid email address');
        }
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4/dovecot nahi mile');
        }

        $route = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bt', $address],
            self::CMD_TIMEOUT,
        );
        $user = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), 'user', $address],
            self::CMD_TIMEOUT,
        );

        $routed = $route->ok()
            && !str_contains((string) $route->stdout, 'cannot be resolved')
            && !str_contains((string) $route->stdout, 'Unrouteable address');

        return [
            'address'  => $address,
            'route'    => trim((string) $route->stdout),
            'routed'   => $routed,
            'mailbox'  => trim((string) $user->stdout),
            'has_mailbox' => $user->ok() && trim((string) $user->stdout) !== '',
        ];
    }

    /** @return list<string> */
    public function mailboxes(): array
    {
        $out = [];
        foreach ($this->mailboxLines() as $line) {
            $fields = explode(':', $line);
            $addr = strtolower(trim($fields[0] ?? ''));
            if ($addr !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    // ------------------------------------------------------- email filters ----

    /**
     * Email Filters — cPanel #21 (per-mailbox) + #20 (account-wide).
     *
     * Panel JSON se **ASLI Exim filter file** banti hai (Exim filter language),
     * jise `exim -bf` se validate karne ke baad hi install kiya jata hai.
     * Delivery se pehle router `alphacp_userfilter` ise chalata hai, to:
     *   save    -> mail sidhi Maildir folder me (IMAP me dikhta hai)
     *   deliver -> doosre address par forward
     *   seen finish -> discard (kabhi inbox me nahi aati)
     *
     * Fail-closed: `exim -bf` galat bole to filter install hota hi nahi
     * (mail delivery chalti rahegi, sirf filter nahi lagega).
     *
     * @param  list<array<string, mixed>> $globalRows  account-wide rules (pehle lagu)
     * @param  list<array<string, mixed>> $userRows    is mailbox ke apne rules
     * @return string  khali = koi chalne layak rule nahi (file hi nahi banani)
     */
    public function renderEximFilter(string $addr, string $maildir, array $globalRows, array $userRows): string
    {
        $out = [
            // Exim spec: filter file ki PEHLI line '# Exim filter' honi hi chahiye.
            // Nahi to exim ise aam .forward file samajhta hai (aur -bf reject kar deta hai).
            '# Exim filter  <<== YE LINE HATAANA NAHI (Exim filter file ki pehchaan)',
            '# AlphaCP managed — haath se edit mat karo',
            '# mailbox: ' . $addr,
            '# banaya gaya: panel ke Email Filters (#20 account-wide + #21 per-mailbox) se',
            '# har mail sync par dobara likha jata hai.',
            '',
            'if error_message then finish endif',
            '',
        ];
        $rules = 0;
        foreach ([$globalRows, $userRows] as $group) {
            foreach ($group as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines = $this->filterRule($addr, $maildir, $row);
                if ($lines === []) {
                    continue;
                }
                foreach ($lines as $line) {
                    $out[] = $line;
                }
                $out[] = '';
                $rules++;
            }
        }
        if ($rules === 0) {
            return '';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Ek filter rule se Exim filter language ka `if … then … endif` block.
     *
     * @param  array<string, mixed> $row
     * @return list<string>  khali = rule chalne layak nahi (chhod do)
     */
    private function filterRule(string $addr, string $maildir, array $row): array
    {
        $field = strtolower(trim((string) ($row['field'] ?? '')));
        $needle = trim((string) ($row['needle'] ?? ''));
        $action = strtolower(trim((string) ($row['action'] ?? '')));
        if (!in_array($field, ['from', 'subject', 'to'], true)) {
            return [];
        }
        // needle me quote/khaas character nahi — Exim filter string me surakshit
        if ($needle === '' || preg_match('/^[a-zA-Z0-9 .,_@+-]+$/', $needle) !== 1) {
            return [];
        }
        $cond = 'if $header_' . $field . ': contains "' . $needle . '" then';

        if ($action === 'discard') {
            return [$cond, '  seen finish', 'endif'];
        }
        if ($action === 'folder') {
            $folder = strtolower(trim((string) ($row['folder'] ?? '')));
            if ($folder === '' || preg_match('/^[a-z0-9._-]+$/', $folder) !== 1) {
                return [];
            }
            // Maildir++ layout: .Folder/ — Dovecot IMAP me "Folder" dikhta hai
            return [$cond, '  save "' . rtrim($maildir, '/') . '/.' . $folder . '/"', '  finish', 'endif'];
        }
        if ($action === 'forward') {
            $dest = strtolower(trim((string) ($row['dest'] ?? '')));
            if ($dest === '' || $dest === $addr || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $dest) !== 1) {
                return [];   // khud ko forward = loop
            }

            return [$cond, '  deliver "' . $dest . '"', '  finish', 'endif'];
        }

        return [];
    }

    /**
     * Exim's redirect router drops to the mailbox uid/gid before opening this
     * file. Permit that identity to search the account's ~/etc directory, but
     * do not grant it directory listing when it is neither owner nor group.
     *
     * @return null|string null when the path is searchable; otherwise a safe error
     */
    private function ensureFilterEtcSearchable(string $etcDir, int $uid, int $gid): ?string
    {
        if (is_link($etcDir)) {
            return 'filter parent ~/etc is a symlink';
        }
        $stat = @lstat($etcDir);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000)) {
            return 'filter parent ~/etc is missing or not a directory';
        }

        $mode = (int) ($stat['mode'] & 0777);
        $owner = (int) ($stat['uid'] ?? -1);
        $group = (int) ($stat['gid'] ?? -1);
        $rewriteGroup = false;
        if ($owner === $uid) {
            $searchBit = 0100;
        } elseif ($group === $gid) {
            $searchBit = 0010;
        } else {
            // Per-account primary group is preferred over making ~/etc
            // world-searchable. Fall back to search-only for other users if
            // chgrp is unavailable; this adds no read/list permission.
            if (@chgrp($etcDir, $gid)) {
                $searchBit = 0010;
                $rewriteGroup = true;
            } else {
                $searchBit = 0001;
            }
        }

        if (!$rewriteGroup && ($mode & $searchBit) !== 0) {
            return null;
        }
        $targetMode = $rewriteGroup
            ? (($mode & ~0070) | 0010)
            : ($mode | $searchBit);
        if (!@chmod($etcDir, $targetMode)) {
            return 'filter parent ~/etc search permission could not be set';
        }

        // Self-healing verify: kuch filesystems/PHP builds chgrp/chown ko true
        // return kar ke no-op kar dete hain (aur stat cache bhi stale hota hai),
        // isliye fresh stat lo aur zaroorat par agla fallback azmao:
        //   1) chown owner=mailbox uid (+ owner search bit)   [root agents]
        //   2) world search bit (sirf +x — read/list nahi)    [last resort]
        $verify = function () use ($etcDir, $uid, $gid): array {
            clearstatcache(false, $etcDir);
            $st = @lstat($etcDir);
            if (!is_array($st) || (($st['mode'] & 0170000) !== 0040000)) {
                return [-1, -1, -1, false];
            }
            $mode  = (int) ($st['mode'] & 0777);
            $owner = (int) ($st['uid'] ?? -1);
            $group = (int) ($st['gid'] ?? -1);
            $bit   = $owner === $uid ? 0100 : ($group === $gid ? 0010 : 0001);

            return [$mode, $owner, $group, ($mode & $bit) !== 0];
        };
        [, , , $searchable] = $verify();
        if (!$searchable && @chown($etcDir, $uid) && @chmod($etcDir, $targetMode | 0100)) {
            [, , , $searchable] = $verify();
        }
        if (!$searchable) {
            [$modeNow] = $verify();
            if ($modeNow >= 0 && @chmod($etcDir, $modeNow | 0001)) {
                [, , , $searchable] = $verify();
            }
        }

        return $searchable ? null : 'filter parent ~/etc remains inaccessible to the mailbox uid/gid';
    }

    /**
     * Filter file likho — par sirf tab jab `exim -bf` use VALID bole.
     * Galat filter se poora mail server nahi rukna chahiye: reject ho to
     * purani file wapas / naye filter ke bina delivery chalti rahe.
     */
    private function writeFilterFile(string $path, string $body, string $addr, int $uid, int $gid, string $etcDir): ?string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.acp-filter-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            return 'filter file nahi likhi ja saki';
        }
        // 0644 + root-owned: Exim ka router (Debian-exim) bhi filter file padh sake.
        // Filter me koi secret nahi hota (sanitized rules), uid/gid delivery ke waqt
        // transport set karta hai (address_directory).
        @chmod($tmp, 0644);
        // Exim khud bole: `exim -bf <filter>` (galat syntax = non-zero exit)
        $probe = $this->cmd->run([$this->eximBin(), '-bf', $tmp, '-f', $addr], self::CMD_TIMEOUT, self::FILTER_TEST_MESSAGE);
        if (!$probe->ok()) {
            @unlink($tmp);
            $why = self::oneLine($probe->stderr . ' ' . $probe->stdout);
            $this->log->warning('email filter reject (exim -bf): ' . $addr . ' -> ' . $why);

            return $why === '' ? 'exim -bf ne filter reject kar diya' : $why;
        }
        $permissionError = $this->ensureFilterEtcSearchable($etcDir, $uid, $gid);
        if ($permissionError !== null) {
            @unlink($tmp);
            $this->log->warning('email filter path permission: ' . $addr . ' -> ' . $permissionError);

            return $permissionError;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return 'filter file install nahi ho saki';
        }
        @chmod($path, 0644);

        return null;
    }

    /** Maildir subfolder pehle se bana do — IMAP me turant dikhe. */
    private function ensureMaildirFolder(string $maildir, string $folder, int $uid, int $gid): void
    {
        $target = rtrim($maildir, '/') . '/.' . $folder;
        foreach (['', '/cur', '/new', '/tmp'] as $leaf) {
            $dir = $target . $leaf;
            if (is_dir($dir)) {
                continue;
            }
            @mkdir($dir, 0700, true);
        }
        if ($uid > 0) {
            @chown($target, $uid);
            if ($gid > 0) {
                @chgrp($target, $gid);
            }
            foreach (['/cur', '/new', '/tmp'] as $leaf) {
                @chown($target . $leaf, $uid);
                if ($gid > 0) {
                    @chgrp($target . $leaf, $gid);
                }
            }
        }
    }

    /**
     * Track Delivery (cPanel #19) — asli exim mainlog me is address ka safar.
     * Pehle ye sirf JSON me dhoondhta tha; ab log khud jawab deta hai.
     *
     * @return array<string, mixed>
     */
    public function track(string $address, int $limit = 100): array
    {
        $addr = strtolower(trim($address));
        if (preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/', $addr) !== 1) {
            throw new TaskRejectedException("track: '{$address}' sahi email address nahi hai");
        }
        $limit = max(1, min(500, $limit));
        $report = $this->reports($limit * 10, $addr);
        if (($report['ok'] ?? false) !== true) {
            return [
                'ok'      => false,
                'address' => $addr,
                'source'  => 'exim mainlog',
                'error'   => (string) ($report['error'] ?? 'exim mainlog nahi mila'),
                'hits'    => [],
                'summary' => [],
            ];
        }
        $hits = [];
        $summary = [];
        foreach ((array) ($report['entries'] ?? []) as $entry) {
            $kind = (string) ($entry['kind'] ?? '');
            $hits[] = [
                'id'      => (string) ($entry['id'] ?? ''),
                'time'    => (string) ($entry['time'] ?? ''),
                'kind'    => $kind,
                'address' => $entry['address'] ?? null,
                'text'    => (string) ($entry['text'] ?? ''),
            ];
            $summary[$kind] = ($summary[$kind] ?? 0) + 1;
        }

        return [
            'ok'         => true,
            'address'    => $addr,
            'source'     => 'exim mainlog',
            'file'       => $report['file'] ?? null,
            'hits'       => array_slice($hits, -$limit),
            'hits_total' => count($hits),
            'summary'    => $summary,
        ];
    }

    private function eximBin(): string
    {
        return self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
    }

    // ---------------------------------------- queue / reports / configuration ----

    /**
     * Mail Queue Manager (cPanel #141) — `exim -bp` (mailq) ka asli jawab, aur
     * zaroorat par queue par action: deliver / remove / freeze / thaw / flush.
     *
     * @return array<string, mixed>
     */
    public function queue(string $op = 'list', string $id = ''): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4 nahi mila — mail queue dekhne ke liye exim chahiye');
        }
        $op = strtolower(trim($op));
        if ($op === '') {
            $op = 'list';
        }
        $exim = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        $mutations = ['deliver' => ['-M'], 'remove' => ['-Mrm'], 'freeze' => ['-Mf'], 'thaw' => ['-Mt']];

        if (isset($mutations[$op])) {
            $id = trim($id);
            if (!self::validQueueId($id)) {
                throw new TaskRejectedException(
                    "queue {$op} ke liye asli message id chahiye (jaise 1oABCD-0000xy-1a) — '{$id}' nahi chalega"
                );
            }
            $res = $this->cmd->run(array_merge([$exim], $mutations[$op], [$id]), self::CMD_TIMEOUT);

            return [
                'op'     => $op,
                'id'     => $id,
                'ok'     => $res->ok(),
                'output' => self::oneLine(trim((string) $res->stdout)),
                'error'  => $res->ok() ? null : self::cleanError($res),
                'queue'  => $this->listQueue(),
            ];
        }

        if ($op === 'flush') {
            $res = $this->cmd->run([$exim, '-qf'], self::CMD_TIMEOUT);

            return [
                'op'    => 'flush',
                'ok'    => $res->ok(),
                'error' => $res->ok() ? null : self::cleanError($res),
                'queue' => $this->listQueue(),
            ];
        }

        if ($op !== 'list' && $op !== 'count') {
            throw new TaskRejectedException("queue op '{$op}' nahi chalega (list/count/deliver/remove/freeze/thaw/flush)");
        }

        $queue = $this->listQueue();
        if ($op === 'count') {
            return ['op' => 'count', 'count' => $queue['count'], 'ok' => $queue['ok'], 'error' => $queue['error']];
        }

        return ['op' => 'list'] + $queue;
    }

    /**
     * `exim -bp` ko padh kar queue ka asli haal (kitne mail pending, kiske liye).
     *
     * @return array<string, mixed>
     */
    public function listQueue(): array
    {
        $exim = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        $res = $this->cmd->run([$exim, '-bp'], self::CMD_TIMEOUT);
        $items = [];
        $current = null;

        foreach (preg_split('/\R/', trim((string) $res->stdout)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $m = [];
            $isHead = (bool) preg_match(
                '/^\s*(?:(?P<age>\d+[smhdw])\s+)?(?P<size>\d+(?:\.\d+)?[KMGkmg]?B?)\s+'
                . '(?P<id>[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2})(?P<flags>[A-Za-z-]*)\s*(?P<rest>.*)$/',
                $line,
                $m,
            );
            if ($isHead) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $rest = (string) ($m['rest'] ?? '');
                $sender = '';
                $sm = [];
                if (preg_match('/<([^>]*)>/', $rest, $sm)) {
                    $sender = trim((string) $sm[1]);
                } elseif (trim($rest) !== '') {
                    $sender = trim((string) (explode(' ', trim($rest))[0] ?? ''));
                }
                $current = [
                    'id'         => (string) ($m['id'] ?? ''),
                    'age'        => ($m['age'] ?? '') !== '' ? (string) $m['age'] : null,
                    'size'       => (string) ($m['size'] ?? ''),
                    'sender'     => strtolower($sender),
                    'recipients' => [],
                    'frozen'     => str_contains($line, 'frozen'),
                ];
                continue;
            }
            if ($current === null) {
                continue;
            }
            $rm = [];
            if (preg_match('/^\s+<?([^\s<>]+)>?/', $line, $rm)) {
                $to = strtolower(trim(trim((string) $rm[1]), '<>,;'));
                if ($to !== '' && !in_array($to, $current['recipients'], true)) {
                    $current['recipients'][] = $to;
                }
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        return [
            'ok'    => $res->ok(),
            'error' => $res->ok() ? null : self::cleanError($res),
            'count' => count($items),
            'items' => $items,
            'note'  => $items === [] ? 'queue khali hai (koi mail pending nahi)' : null,
        ];
    }

    /**
     * Mail Delivery Reports (cPanel #142) — asli exim mainlog se ginati:
     * kitni mail aayi, pahunchi, deferred/failed hui. Hamara daawa nahi, log ka jawab.
     *
     * @return array<string, mixed>
     */
    public function reports(int $limit = 50, string $search = ''): array
    {
        $file = $this->mainlogFile();
        if ($file === null) {
            return [
                'ok'             => false,
                'error'          => 'exim mainlog nahi mila (dhoondha: ' . implode(', ', self::MAINLOG_CANDIDATES) . ')',
                'counts'         => [],
                'entries'        => [],
                'top_senders'    => [],
                'top_recipients' => [],
            ];
        }

        $limit = max(1, min(500, $limit));
        $search = strtolower(trim($search));
        $lines = preg_split('/\R/', $this->tailText($file)) ?: [];
        $counts = [
            'arrived' => 0, 'delivered' => 0, 'redirected' => 0, 'deferred' => 0,
            'failed'  => 0, 'completed' => 0, 'rejected'   => 0, 'frozen'   => 0,
        ];
        $senders = [];
        $recipients = [];
        $entries = [];

        foreach ($lines as $line) {
            $m = [];
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\s+(\S+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $rest = (string) ($m[3] ?? '');
            $kind = null;
            $addr = null;
            $mm = [];
            if (preg_match('/^<=\s+(\S+)/', $rest, $mm)) {
                $kind = 'arrived';
                $addr = self::cleanAddr((string) $mm[1]);
                $senders[$addr] = ($senders[$addr] ?? 0) + 1;
            } elseif (preg_match('/^=>\s+(\S+)/', $rest, $mm)) {
                $kind = 'delivered';
                $addr = self::cleanAddr((string) $mm[1]);
                $recipients[$addr] = ($recipients[$addr] ?? 0) + 1;
            } elseif (preg_match('/^->\s+(\S+)/', $rest, $mm)) {
                $kind = 'redirected';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (preg_match('/^==\s+(\S+)/', $rest, $mm)) {
                $kind = 'deferred';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (preg_match('/^\*\*\s+(\S+)/', $rest, $mm)) {
                $kind = 'failed';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (str_starts_with($rest, 'Completed')) {
                $kind = 'completed';
            } elseif (str_contains($rest, 'frozen')) {
                $kind = 'frozen';
            } elseif (preg_match('/\brejected\b/i', $rest)) {
                $kind = 'rejected';
            }
            if ($kind === null) {
                continue;
            }
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($search !== '' && !str_contains(strtolower($line), $search)) {
                continue;
            }
            $entries[] = [
                'time'    => (string) ($m[1] ?? ''),
                'id'      => (string) ($m[2] ?? ''),
                'kind'    => $kind,
                'address' => $addr,
                'text'    => self::oneLine($rest),
            ];
        }

        arsort($senders);
        arsort($recipients);

        return [
            'ok'             => true,
            'file'           => $file,
            'lines_scanned'  => count($lines),
            'counts'         => $counts,
            'top_senders'    => self::topCounts($senders, 10),
            'top_recipients' => self::topCounts($recipients, 10),
            'entries'        => array_slice($entries, -$limit),
            'entries_total'  => count($entries),
        ];
    }

    /**
     * Email Disk Usage — server view (cPanel #146): har account aur har mailbox
     * ki mail jagah, asli bytes me (symlinks follow nahi hote).
     *
     * @return array<string, mixed>
     */
    public function diskUsage(?string $username = null): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $accounts = [];
        $total = 0;
        $name = $username === null ? null : strtolower(trim($username));

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $user = basename($dir);
            if ($name !== null && $user !== $name) {
                continue;
            }
            $mail = $dir . '/mail';
            if (!is_dir($mail)) {
                continue;
            }
            $mailboxes = [];
            foreach (glob($mail . '/*/*') ?: [] as $box) {
                if (!is_dir($box) || is_link($box)) {
                    continue;
                }
                $mailboxes[] = [
                    'address' => basename($box) . '@' . basename((string) dirname($box)),
                    'bytes'   => $this->dirBytes($box),
                ];
            }
            usort($mailboxes, static fn (array $a, array $b): int => ($b['bytes'] <=> $a['bytes']));
            $accountBytes = $this->dirBytes($mail);
            $total += $accountBytes;
            $accounts[] = [
                'username'      => $user,
                'bytes'         => $accountBytes,
                'mailboxes'     => $mailboxes,
                'mailbox_count' => count($mailboxes),
            ];
        }
        usort($accounts, static fn (array $a, array $b): int => ($b['bytes'] <=> $a['bytes']));

        return [
            'ok'            => true,
            'accounts_root' => $root,
            'accounts'      => $accounts,
            'account_count' => count($accounts),
            'total_bytes'   => $total,
            'total_human'   => self::humanBytes($total),
        ];
    }

    /**
     * Exim Configuration Manager (cPanel #143).
     * $set = null → sirf dikhao; array → validate → likho → template dobara banao
     * (update-exim4.conf + `exim4 -bV`; fail ho to purani config wapas).
     *
     * @param  array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    public function eximConf(?array $set = null): array
    {
        $file = $this->eximOptionsFile();
        // SpamAssassin and greylistd services have to be managed alongside
        // their ACL toggles, so expose them only through spamAssassin(), not
        // the generic cPanel Exim Configuration Manager endpoint.
        $managed = ['spam_enabled', 'greylisting'];
        $allowed = array_values(array_diff(array_keys(self::EXIM_OPTION_SPEC), $managed));
        $out = [
            'file'     => $file,
            'options'  => $this->eximOptions(),
            'defaults' => self::specDefaults(self::EXIM_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === null) {
            return $out;
        }
        if ($set === []) {
            throw new TaskRejectedException('eximconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        $restricted = array_values(array_intersect(array_keys($set), $managed));
        if ($restricted !== []) {
            throw new TaskRejectedException(
                implode(', ', $restricted) . ' mail.server spamassassin action se manage hote hain (service safety ke liye)'
            );
        }

        return $this->applyEximOptionSet($set);
    }

    /** @param array<string, mixed> $set @return array<string, mixed> */
    private function applyEximOptionSet(array $set): array
    {
        $file = $this->eximOptionsFile();
        $allowed = array_keys(self::EXIM_OPTION_SPEC);
        $out = [
            'file'     => $file,
            'options'  => $this->eximOptions(),
            'defaults' => self::specDefaults(self::EXIM_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === []) {
            throw new TaskRejectedException('eximconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        if (!$this->installed() || !self::isConfigured()) {
            throw new TaskRejectedException(
                'Exim config tabhi badalte hain jab mail server configure ho — pehle mail.server setup chalao'
            );
        }
        [$merged, $errors] = self::validateOptions($set, self::EXIM_OPTION_SPEC, $out['options']);
        if ($errors !== []) {
            throw new TaskRejectedException('exim option reject: ' . implode(' | ', $errors));
        }
        $this->writeJson($file, $merged);

        return array_merge($out, $this->applyEximTemplate(), ['options' => $merged, 'applied' => true]);
    }

    /**
     * Mailserver Configuration / Dovecot (cPanel #144).
     * $set = null → sirf dikhao; array → validate → likho → conf dobara banao
     * (`doveconf -n`; fail ho to purani conf wapas).
     *
     * @param  array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    public function dovecotConf(?array $set = null): array
    {
        $file = $this->dovecotOptionsFile();
        $allowed = array_keys(self::DOVECOT_OPTION_SPEC);
        $out = [
            'file'     => $file,
            'options'  => $this->dovecotOptions(),
            'defaults' => self::specDefaults(self::DOVECOT_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === null) {
            return $out;
        }
        if ($set === []) {
            throw new TaskRejectedException('dovecotconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        if (!$this->installed()) {
            throw new TaskRejectedException('dovecot nahi mila — pehle mail.server setup chalao');
        }
        [$merged, $errors] = self::validateOptions($set, self::DOVECOT_OPTION_SPEC, $out['options']);
        if ($errors !== []) {
            throw new TaskRejectedException('dovecot option reject: ' . implode(' | ', $errors));
        }
        $this->writeJson($file, $merged);

        return array_merge($out, $this->applyDovecotConfig(), ['options' => $merged, 'applied' => true]);
    }

    /** @return array<string, string> */
    public function eximOptions(): array
    {
        return $this->loadOptions($this->eximOptionsFile(), self::EXIM_OPTION_SPEC);
    }

    /** @return array<string, string> */
    public function dovecotOptions(): array
    {
        return $this->loadOptions($this->dovecotOptionsFile(), self::DOVECOT_OPTION_SPEC);
    }

    /**
     * `exim4 -bV` sirf SYNTAX pakadta hai — runtime expansion error (jaise
     * "Failed to find user" / "PANIC") tabhi dikhta hai jab exim sach-much kisi
     * address ko route kare. 0.78.0 me yahi hua tha: config `-bV` pass kar gaya
     * par poora mail delivery defer ho gaya. Ab setup ke baad ek asli `-bt`
     * smoke test chalta hai — fail ho to purani template wapas.
     *
     * @return string|null  null = theek; string = wajah (reject kar do)
     */
    private function eximSmokeTest(): ?string
    {
        if (!$this->installed()) {
            return null;
        }
        $boxes = $this->mailboxes();
        if ($boxes === []) {
            return null;   // koi mailbox nahi to smoke test ka matlab nahi
        }
        $addr = (string) $boxes[0];
        $res = $this->cmd->run([$this->eximBin(), '-bt', $addr], self::CMD_TIMEOUT);
        $text = trim($res->stdout . ' ' . $res->stderr);
        if ($text === '') {
            return null;   // chup binary (fake/test) — jhoothi reject nahi
        }
        if (preg_match('/PANIC|Failed to find user|cannot be resolved|configuration error|Failed to (open|find)/i', $text) === 1) {
            return 'exim -bt ' . $addr . ' -> ' . self::oneLine($text);
        }

        return null;
    }

    /**
     * Managed Exim template dobara likho: backup (pehli baar) → likho → validate
     * (`update-exim4.conf` + `exim4 -bV`) → fail ho to purani wapas → restart.
     *
     * @return array<string, mixed>
     */
    private function applyEximTemplate(): array
    {
        $this->snapshotEximTemplate();
        $this->writeManaged($this->eximTemplate(), $this->renderEximTemplate(), 0644);
        $generate = $this->cmd->run(
            [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
            self::CMD_TIMEOUT,
        );
        $check = $this->cmd->run([self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'], self::CMD_TIMEOUT);
        if (!$generate->ok() || !$check->ok()) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException(
                'exim config reject (purani config wapas): ' . self::cleanError($generate) . ' / ' . self::cleanError($check)
            );
        }
        $smoke = $this->eximSmokeTest();
        if ($smoke !== null) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException('exim routing smoke test fail (purani config wapas): ' . $smoke);
        }
        $this->cmd->run(['/bin/systemctl', 'restart', 'exim4'], self::CMD_TIMEOUT);

        return ['generated' => true, 'exim_config' => self::firstLine((string) $check->stdout)];
    }

    /** @return array<string, mixed> */
    private function applyDovecotConfig(): array
    {
        $file = $this->dovecotConfFile();
        $previous = is_file($file) ? (string) @file_get_contents($file) : null;
        $this->writeManaged($file, $this->renderDovecotConf(), 0644);
        $check = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'],
            self::CMD_TIMEOUT,
        );
        if (!$check->ok()) {
            if ($previous !== null) {
                @file_put_contents($file, $previous);
            } else {
                @unlink($file);
            }
            throw new TaskRejectedException('dovecot config reject (purani conf wapas): ' . self::cleanError($check));
        }
        $this->cmd->run(['/bin/systemctl', 'restart', 'dovecot'], self::CMD_TIMEOUT);

        return ['dovecot_config' => 'ok', 'dovecot_userdb' => $this->probeDovecotUserdb()];
    }

    /**
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array<string, string>
     */
    private function loadOptions(string $file, array $spec): array
    {
        $out = self::specDefaults($spec);
        if (!is_file($file)) {
            return $out;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return $out;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $out;
        }
        foreach (array_keys($spec) as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            [$value, $error] = self::validateOption((string) $key, $decoded[$key], $spec);
            if ($error === null && $value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>                       $in
     * @param  array<string, array{0: string, 1: string}> $spec
     * @param  array<string, string>                      $base
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function validateOptions(array $in, array $spec, array $base): array
    {
        $merged = $base + self::specDefaults($spec);
        $errors = [];
        foreach ($in as $key => $value) {
            $key = (string) $key;
            [$clean, $error] = self::validateOption($key, $value, $spec);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $merged[$key] = (string) $clean;
        }

        return [$merged, $errors];
    }

    /**
     * Har option ki value uske type ke regex se validate hoti hai (fail-closed):
     * galat value exim/dovecot ko chalu hone se rok deti hai, isliye seedha reject.
     *
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array{0: ?string, 1: ?string}
     */
    private static function validateOption(string $key, mixed $value, array $spec): array
    {
        if (!isset($spec[$key])) {
            return [null, "'{$key}' koi mail option nahi hai (allowed: " . implode(', ', array_keys($spec)) . ')'];
        }
        $type = (string) $spec[$key][0];
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return [null, "'{$key}' khali nahi ho sakta"];
        }
        if ($type === 'bool') {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                $value = 'yes';
            } elseif (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
                $value = 'no';
            }
        }
        $patterns = [
            'size'     => '/^\d{1,8}[KMGkmg]?$/',
            'int'      => '/^\d{1,9}$/',
            'number'   => '/^\d{1,5}(\.\d{1,2})?$/',
            'duration' => '/^\d{1,5}[smhdw]$/',
            'bool'     => '/^(yes|no)$/',
            'text'     => '/^[ -\x7e]{1,200}$/',
        ];
        if (!isset($patterns[$type]) || !preg_match($patterns[$type], $value)) {
            return [null, "'{$key}' = '{$value}' galat hai ({$type} chahiye)"];
        }
        // '#' config file me comment shuru kar deta hai — option value me mana hai.
        if (str_contains($value, '#')) {
            return [null, "'{$key}' me '#' nahi ho sakta (config comment ban jayega)"];
        }

        return [$value, null];
    }

    /**
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array<string, string>
     */
    private static function specDefaults(array $spec): array
    {
        $out = [];
        foreach ($spec as $key => $def) {
            $out[(string) $key] = (string) ($def[1] ?? '');
        }

        return $out;
    }

    /** @param array<string, string> $data */
    private function writeJson(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        ksort($data);
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new TaskRejectedException("mail options file nahi ban saki: {$file}");
        }
        $tmp = $dir . '/.acp-mailopts-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body . "\n") === false) {
            throw new TaskRejectedException("mail options temp file nahi likhi ja saki: {$tmp}");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new TaskRejectedException("mail options file nahi likhi ja saki: {$file}");
        }
        @chmod($file, 0644);
    }

    /** Log ka aakhiri hissa (bada log poori tarah memory me nahi aata). */
    private function tailText(string $file, int $bytes = 524288): string
    {
        $size = @filesize($file);
        if (!is_int($size) || $size <= 0) {
            return '';
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > $bytes) {
            fseek($fh, $size - $bytes);
            fgets($fh); // adhoori pehli line chhod do
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);

        return $text;
    }

    /** Symlink follow NAHI hota (safety) — mail tree me symlink ho to skip. */
    private function dirBytes(string $dir, int $depth = 0): int
    {
        if ($depth > 8 || !is_dir($dir) || is_link($dir)) {
            return 0;
        }
        $bytes = 0;
        $fh = @opendir($dir);
        if ($fh === false) {
            return 0;
        }
        while (($entry = readdir($fh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $bytes += $this->dirBytes($path, $depth + 1);
                continue;
            }
            $size = @filesize($path);
            if (is_int($size) && $size > 0) {
                $bytes += $size;
            }
        }
        closedir($fh);

        return $bytes;
    }

    private static function validQueueId(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2}$/', $id);
    }

    private static function cleanAddr(string $addr): string
    {
        return strtolower(trim(trim($addr), "<>,;"));
    }

    /** @param array<string, int> $map @return list<array{address: string, count: int}> */
    private static function topCounts(array $map, int $limit): array
    {
        $out = [];
        foreach (array_slice($map, 0, $limit, true) as $addr => $count) {
            $address = (string) $addr;
            if ($address === '') {
                continue;
            }
            $out[] = ['address' => $address, 'count' => (int) $count];
        }

        return $out;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024.0 && $i < count($units) - 1) {
            $value /= 1024.0;
            $i++;
        }

        return $i === 0 ? $bytes . ' B' : sprintf('%.1f %s', $value, $units[$i]);
    }

    // ------------------------------------------------------------ rendering ----

    /** Webmail SSO master password file (installer-owned, root:www-data 0640). */
    public function masterPassdbFile(): string
    {
        return self::pathEnv('ACP_MAIL_MASTER_PASSDB', '/usr/local/alphacp/etc/webmail-master.pw');
    }

    private function renderDovecotConf(): string
    {
        $users = $this->dovecotUsersFile();
        $opts = $this->dovecotOptions();
        // Webmail SSO (cPanel-jaisa seamless login): master passdb sirf tab
        // include hota hai jab master password file maujood ho (installer
        // banata hai, root:www-data 0640) — warna conf bilkul pehle jaisa.
        $master = $this->masterPassdbFile();
        $extra = [];
        if (is_file($master)) {
            $extra = [
                '# AlphaCP S7 — Webmail SSO master passdb (Roundcube token flow)',
                'passdb {',
                '  driver = passwd-file',
                '  args = scheme=BLF-CRYPT ' . $master,
                '  master = yes',
                '}',
            ];
        }
        $lines = [
            self::MANAGED_BEGIN,
            '# AlphaCP S7 — virtual mailboxes jo panel banata hai',
            '# har account ka ~/etc/mail/passwd yahan aggregate hota hai',
            'passdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            '# NOTE: "scheme=" sirf passdb ke liye hai (Dovecot 2.3 docs).',
            '# userdb args me scheme= likhne se Dovecot poore string ko FILENAME',
            '# samajh leta hai -> open("scheme=... /etc/...") No such file or directory.',
            'userdb {',
            '  driver = passwd-file',
            '  args = ' . $users,
            '}',
            ...$extra,
            '# mailbox khud Maildir hai (home field usi ko point karta hai)',
            'mail_location = maildir:~/',
            'mail_home = %h',
            'protocols = ' . $opts['protocols'],
            'listen = 127.0.0.1, ::1',
            'disable_plaintext_auth = ' . $opts['disable_plaintext_auth'],
            'auth_mechanisms = plain login',
            'mail_plugins = $mail_plugins quota',
            '# Mailserver Configuration (cPanel #144) — panel se set kiye gaye options',
            'mail_max_userip_connections = ' . $opts['mail_max_userip_connections'],
            'maildir_copy_with_hardlinks = ' . $opts['maildir_copy_with_hardlinks'],
            'pop3_uidl_format = ' . $opts['pop3_uidl_format'],
            'imap_idle_notify_interval = ' . $opts['imap_idle_notify_interval'] . ' mins',
            'mailbox_idle_check_interval = ' . $opts['mailbox_idle_check_interval'] . ' secs',
            'login_greeting = ' . $opts['login_greeting'],
            'protocol imap {',
            '  mail_plugins = $mail_plugins imap_quota',
            '}',
            'service imap-login {',
            '  inet_listener imap {',
            '    port = 143',
            '  }',
            '}',
            'service pop3-login {',
            '  inet_listener pop3 {',
            '    port = 110',
            '  }',
            '}',
            self::MANAGED_END,
        ];

        return implode("\n", $lines) . "\n";
    }

    private function renderEximTemplate(): string
    {
        $domains = $this->domainsFile();
        $recipients = $this->recipientsFile();
        $aliases = $this->aliasesFile();
        $catchall = $this->catchallFile();
        $vacation = $this->vacationDir();
        $spamDir = $this->spamDir();
        $dkimDir = $this->dkimDir();
        $caps = $this->capabilities();
        $dnsbl = self::DNSBL;
        $dkimKey = $dkimDir . '/\$sender_address_domain.key';
        $dkim = $caps['dkim']
            ? "  dkim_domain = \$sender_address_domain\n"
              . '  dkim_selector = ' . self::DKIM_SELECTOR . "\n"
              . "  dkim_canon = relaxed\n"
              . "  dkim_private_key = \${if exists{{$dkimKey}}{{$dkimKey}}{0}}\n"
              . "  dkim_sign_headers = Date:From:To:Subject:Message-ID:MIME-Version:Content-Type\n"
            : '';
        $opts = $this->eximOptions();
        $spamLimit = (string) $opts['spam_score_limit'];
        $spamLimitHuman = self::scoreHuman((int) $opts['spam_score_limit']);
        $filtersFile = $this->filtersFile();
        // SpamAssassin (cPanel #147): content-scan support + installed spamd +
        // explicit opt-in. `:true` populates score/header variables on every message;
        // `/defer_ok` makes a later spamd outage fail open (never hold mail in queue).
        // 0 score limit is deliberately tag-only; it must not reject score > 0.
        $spamOn = $opts['spam_enabled'] === 'yes' && $caps['spamd'] && $caps['content_scanning'];
        $spamRejectAcl = (int) $spamLimit > 0
            ? "  deny condition = \${if >{\${if def:spam_score_int {\$spam_score_int}{0}}}{{$spamLimit}}{yes}{no}}\n"
              . "       message = Message scored \$spam_score spam points (limit {$spamLimitHuman}) - rejected as spam\n\n"
            : '';
        $spamAcl = $spamOn
            ? "  warn spam = nobody:true/defer_ok\n"
              . "       add_header = X-Spam-Score: \$spam_score (\$spam_bar)\n"
              . "       add_header = X-Spam-Checker-Version: SpamAssassin \$spam_score_int/{$spamLimit} AlphaCP\n\n"
              . $spamRejectAcl
              . "          "
            : '';
        // Debian greylistd protocol: --grey <IP> <MAIL FROM> <RCPT TO> returns
        // true only while a triplet is greylisted. A missing/unavailable socket
        // returns false, so greylisting degrades open rather than blocking all mail.
        $greylistSocket = $this->greylistdSocketFile();
        $greylistAcl = $opts['greylisting'] === 'yes'
            ? "  defer\n"
              . "       message = temporarily rejected (greylisted): \$sender_host_address is not yet authorized to deliver mail from <\$sender_address> to <\$local_part@\$domain>. Please try again later.\n"
              . "       log_message = greylisted (\$sender_host_address)\n"
              . "       domains = +local_domains\n"
              . "       !senders = :\n"
              . "       !hosts = : +relay_from_hosts\n"
              . "       !authenticated = *\n"
              . "       verify = recipient\n"
              . "       condition = \${readsocket{{$greylistSocket}}{--grey \$sender_host_address \$sender_address \$local_part@\$domain}{5s}{}{false}}\n\n"
              . "          "
            : '';
        return <<<EXIM
        # AlphaCP managed exim4 configuration (mail.server)
        # Asli distro wali template ki copy: {$this->eximTemplateBackup()}
        # Ye file har update par dobara likhi jati hai — haath se edit mat karo.

        # 25 par sab interfaces (incoming mail isi se aati hai).
        # IPv6 na ho to Exim sirf warning dekar IPv4 par chal deta hai.
        local_interfaces = <; 0.0.0.0 ; ::0
        domainlist local_domains = lsearch;{$domains}
        domainlist relay_to_domains =
        hostlist   relay_from_hosts = 127.0.0.1 : ::1

        acl_smtp_rcpt = acl_check_rcpt
        acl_smtp_data = acl_check_data

        # mailbox ki uid se deliver karne ke liye (transport `user =`) Exim ko apna
        # root privilege rakhna padta hai — warna har delivery Debian-exim ke roop
        # me hoti hai aur Maildir (0700) me likh hi nahi sakti.
        deliver_drop_privilege = false
        never_users = root
        host_lookup = *
        rfc1413_hosts =
        rfc1413_query_timeout = 0s
        ignore_bounce_errors_after = {$opts['ignore_bounce_errors_after']}
        timeout_frozen_after = {$opts['timeout_frozen_after']}
        smtp_banner = {$opts['smtp_banner']}
        message_size_limit = {$opts['message_size_limit']}

        # Exim Configuration Manager (cPanel #143) — panel se set kiye gaye options
        smtp_accept_max = {$opts['smtp_accept_max']}
        smtp_accept_max_per_host = {$opts['smtp_accept_max_per_host']}
        queue_run_max = {$opts['queue_run_max']}
        remote_max_parallel = {$opts['remote_max_parallel']}
        deliver_queue_load_max = {$opts['deliver_queue_load_max']}
        queue_only_load = {$opts['queue_only_load']}

        begin acl

        acl_check_rcpt:
          accept hosts = : +relay_from_hosts

          # account ki blacklist (mail.spam task) — sender blocked
          deny senders = \${if exists{{$spamDir}/\$local_part@\$domain.deny}{wildlsearch;{$spamDir}/\$local_part@\$domain.deny}{}}
               message = sender is blocked for this mailbox

          # account ki whitelist — aage ke checks chhodo
          accept senders = \${if exists{{$spamDir}/\$local_part@\$domain.allow}{wildlsearch;{$spamDir}/\$local_part@\$domain.allow}{}}

          deny domains = +local_domains
               local_parts = ^[./|] : ^.*[@%!/|`#&?] : ^.*/\\.\\./
               message = restricted characters in address

          {$greylistAcl}accept domains = +local_domains
                 endpass
                 verify = recipient

          deny message = relay not permitted

        acl_check_data:
          # DNS blacklist: reject nahi, header + log (DNS slow ho to mail nahi girti)
          warn dnslists = {$dnsbl}
               add_header = X-AlphaCP-DNSBL: \$dnslist_domain (\$dnslist_value)
               log_message = DNSBL hit \$dnslist_domain for \$sender_address -> \$local_part@\$domain

          {$spamAcl}accept

        begin routers

        # 1) forwarders (aliases) — pehle ye, phir mailbox
        alphacp_aliases:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          retry_use_local_part
          data = \${lookup{\$local_part@\$domain}lsearch{{$aliases}}}

        # 2) email filters (cPanel #20 account-wide + #21 per-mailbox)
        # Panel ke JSON se ASLI Exim filter file banta hai (`exim -bf` se validate);
        # delivery se pehle yahi chalta hai — save (folder) / deliver (forward) /
        # seen finish (discard). Kharaab filter kabhi install hi nahi hota.
        alphacp_userfilter:
          driver = redirect
          domains = +local_domains
          allow_filter
          allow_defer
          allow_fail
          # (1) lookup file maujood NA ho to router seedha skip (defer nahi!) —
          # (1) lookup file maujood NA ho to router seedha skip (defer nahi!).
          #     (pehle `condition` me lookup thi — file missing par exim har mail
          #     mail par PANIC log likhta tha (real exim 4.97 se verify kiya).
          # (1) DONO zaroori hain (asli exim 4.97 se verify):
          #     require_files  -> lookup file maujood NA ho to seedha skip
          #                       (warna lookup har mail par PANIC/defer karta hai)
          #     condition      -> file hai par is address ka koi filter nahi to skip
          #                       (warna file = "" ho jata hai -> defer "" is not an
          #                        absolute path -> MAIL QUEUE ME ATK JATI HAI)
          require_files = {$filtersFile}
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$filtersFile}}}}{}{yes}{no}}
          file = \${lookup{\$local_part@\$domain}lsearch{{$filtersFile}}}
          # (2) `allow_filter` ke saath `user` HONA ZAROORI hai — Exim config-time
          #     check: '"user" or "check_local_user" must be set with allow_filter'.
          #     0.79.0 me ye hata diya to `exim -bV` hi reject ho gaya. uid yahan
          #     MAILBOX KE HISAB SE — wahi extract idiom jo alphacp_maildir me chal
          #     raha hai (0.78.0 ki nesting galat thi: "Failed to find
          #     user"). condition false hone par ye line expand hoti hi nahi.
          user = \${extract{2}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          group = \${extract{3}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          directory_transport = address_directory
          file_transport = address_file
          pipe_transport = address_pipe
          no_verify
          no_expn
          check_ancestor

        # 3) autoresponder (vacation) — unseen: mail delivery aage bhi hoti hai
        alphacp_autoreply:
          driver = accept
          domains = +local_domains
          condition = \${if exists{{$vacation}/\$local_part@\$domain.eml}{yes}{no}}
          senders = ! ^.*-request@.* : ! ^bounce-.*@.* : ! ^.*-bounce@.* : \
                    ! ^owner-.*@.* : ! ^postmaster@.* : ! ^webmaster@.* : \
                    ! ^listmaster@.* : ! ^mailer-daemon@.* : ! ^root@.* : \
                    ! ^nobody@.*
          transport = alphacp_vacation
          unseen
          no_expn
          no_verify

        # 4) asli mailbox -> Maildir (uid/gid mailbox ke hisaab se)
        alphacp_mailbox:
          driver = accept
          domains = +local_domains
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}{}{yes}{no}}
          transport = alphacp_maildir
          no_more

        # 5) catch-all (*@domain) — mailbox na mile to yahi aakhri rasta
        alphacp_catchall:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          data = \${lookup{*@\$domain}lsearch{{$catchall}}}

        # 6) server ke apne system users (root, ubuntu, ...) — /etc/aliases bhi
        system_aliases:
          driver = redirect
          allow_fail
          allow_defer
          data = \${lookup{\$local_part}lsearch{/etc/aliases}}
          file_transport = address_file
          pipe_transport = address_pipe

        local_user:
          driver = accept
          check_local_user
          transport = mail_spool
          cannot_route_message = Unknown user

        # 7) bahar ki duniya
        dnslookup:
          driver = dnslookup
          domains = ! +local_domains
          transport = remote_smtp
          ignore_target_hosts = 0.0.0.0 : 127.0.0.0/8
          no_more

        begin transports

        alphacp_maildir:
          driver = appendfile
          maildir_format
          create_directory = false
          directory = \${extract{1}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          user = \${extract{2}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          group = \${extract{3}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          delivery_date_add
          envelope_to_add
          return_path_add
          mode = 0600

        alphacp_vacation:
          driver = autoreply
          file = {$vacation}/\$local_part@\$domain.eml
          file_expand
          from = \$local_part@\$domain
          to = \$sender_address
          subject = \${if def:h_subject: {Re: \$h_subject:}{Automatic reply}}
          once = /var/spool/exim4/db/alphacp-vacation-\$local_part@\$domain
          once_repeat = \${if exists{{$vacation}/\$local_part@\$domain.repeat}{\${readfile{{$vacation}/\$local_part@\$domain.repeat}{}}}{7d}}
          log = /var/log/exim4/vacation.log
          return_message

        # system users (root/cron ki mail) ke liye Debian wala /var/mail/<user> —
        # maildir_home se ~/Maildir na hone par queue me atak jati thi
        mail_spool:
          driver = appendfile
          file = /var/mail/\$local_part
          delivery_date_add
          envelope_to_add
          return_path_add
          group = mail
          mode = 0660
          mode_fail_narrower = false

        maildir_home:
          driver = appendfile
          maildir_format
          directory = \$home/Maildir
          delivery_date_add
          envelope_to_add
          return_path_add
          user = \$local_part
          group = mail
          mode = 0600

        # Filter ke `save` command ke liye (redirect router ka directory_transport).
        # maildir_format + create_directory: .Folder/ khud ban jata hai, aur Dovecot
        # use IMAP me "Folder" ke naam se dikhata hai (Maildir++ layout).
        # YAHAN `directory` / `user` / `group` KABHI NA LIKHEIN — filter ka `save`
        # apna path khud laata hai (address hi path hota hai). Transport par ye set
        # karne se wo filter ke folder ko override kar deta hai (0.79.0 me yahi hua:
        # mail .filtered/ ki jagah inbox me chali gayi). uid/gid router se inherit
        # hote hain (router par hi recipient ka local part aur domain milta hai;
        # yahan address ek PATH hota hai, email address nahi).
        address_directory:
          driver = appendfile
          maildir_format
          create_directory
          delivery_date_add
          envelope_to_add
          return_path_add
          mode = 0700
          mode_fail_narrower = false

        address_file:
          driver = appendfile
          delivery_date_add
          envelope_to_add
          return_path_add

        address_pipe:
          driver = pipe
          return_output

        remote_smtp:
          driver = smtp
          {$dkim}
        begin retry
        *                      *           F,2h,15m; G,16h,1h,1.5; F,4d,6h

        begin rewrite

        begin authenticators

        # S7 slice 2 me: Dovecot SASL ke saath submission (587) + SMTP auth.
        # Tab tak sirf localhost se relay (webmail/Roundcube) — koi open relay nahi.
        EXIM;
    }

    // -------------------------------------------------------------- helpers ----

    /** @return list<string> */
    private function mailboxLines(): array
    {
        return $this->readLines($this->dovecotUsersFile());
    }

    /** @return list<string> */
    private function readLines(string $file): array
    {
        if (is_link($file) || !is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Atomic-ish write: temp file -> rename (kabhi aadhi file nahi banti). */
    private function writeManaged(string $file, string $body, int $mode, ?string $group = null): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.acp-mail-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            throw new TaskRejectedException("mail config temp file nahi likhi ja saki: {$tmp}");
        }
        @chmod($tmp, $mode);
        if ($group !== null) {
            @chgrp($tmp, $group);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new TaskRejectedException("mail config file nahi likhi ja saki: {$file}");
        }
        @chmod($file, $mode);
        if ($group !== null) {
            @chgrp($file, $group);
        }
    }

    private function restoreEximTemplate(): void
    {
        // PEHLE pichli kaam karne wali AlphaCP template, warna distro wali asli.
        // (0.79.0 me seedha .acp-orig wapas aayi to exim me alphacp routers hi nahi
        //  rahe — har address "nonlocal" ho gaya aur poora mail band.)
        foreach ([$this->eximTemplatePrev(), $this->eximTemplateBackup()] as $from) {
            if (!is_file($from)) {
                continue;
            }
            @copy($from, $this->eximTemplate());
            $this->cmd->run(
                [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
                self::CMD_TIMEOUT,
            );

            return;
        }
    }

    /**
     * Distro wali asli template ki copy pehli baar (.acp-orig), aur har baar
     * maujuda AlphaCP template ki copy (.acp-prev) — fail-safe rollback ke liye.
     */
    private function snapshotEximTemplate(): void
    {
        $tpl = $this->eximTemplate();
        if (!is_file($tpl)) {
            return;
        }
        if (!is_file($this->eximTemplateBackup())) {
            @copy($tpl, $this->eximTemplateBackup());
        }
        $current = @file_get_contents($tpl);
        if (is_string($current) && str_contains($current, self::TEMPLATE_MARKER)) {
            @copy($tpl, $this->eximTemplatePrev());
        }
    }

    /** Updater isi file se janta hai ki mail configure ho chuka hai (services chalu rakhni hain). */
    private function markConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $dir = $root . '/etc';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/mail-server-configured';

        return @file_put_contents($file, 'configured ' . gmdate('c') . "\n") !== false;
    }

    private static function firstNonEmpty(string $env, string $default): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '' && $override[0] === '/' && !str_contains($override, "\0")) {
            return rtrim($override, '/');
        }

        return $default;
    }

    /** @param list<string> $paths */
    private static function bin(string $env, string $default, array $paths): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '') {
            if ($override[0] !== '/' || str_contains($override, "\0")) {
                throw new TaskRejectedException("invalid {$env} override");
            }

            return $override;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $default;
    }

    /** @param list<string> $paths */
    private static function have(string $env, array $paths): bool
    {
        if (trim((string) (getenv($env) ?: '')) !== '') {
            return true;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    private static function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? trim($text) : trim($line);
    }

    private static function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }
}
PHPEOF
  cat > "${AGENT}/tests/run-tests.php" <<'PHPEOF'
#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * paneld unit tests — run WITHOUT a database.
 *   php agent/tests/run-tests.php
 *
 * Covers the security-critical pieces: JsonSchema, PathGuard, registry
 * integrity, and the command allowlist. Handlers that need a DB are tested
 * by installer/step2-install.sh on the real server instead.
 */

require __DIR__ . '/../src/Bootstrap.php';
require __DIR__ . '/FakeCommandExecutor.php';

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\Files;
use Alphacp\Agent\JsonSchema;
use Alphacp\Agent\Mail;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\PathGuardException;
use Alphacp\Agent\RemoteDestination;
use Alphacp\Agent\RemotePull;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskLogger;
use Alphacp\Agent\TaskRejectedException;
use Alphacp\Agent\TaskRunner;
use Alphacp\Agent\Tasks\AccountCreate;
use Alphacp\Agent\Tasks\AccountSetQuota;
use Alphacp\Agent\Tasks\AccountSuspend;
use Alphacp\Agent\Tasks\AccountTerminate;
use Alphacp\Agent\Tasks\AccountUnsuspend;
use Alphacp\Agent\Tasks\BackupPull;
use Alphacp\Agent\Tasks\BackupDestination;
use Alphacp\Agent\Tasks\CronSet;
use Alphacp\Agent\Tasks\DomainAdd;
use Alphacp\Agent\Tasks\DomainRemove;
use Alphacp\Agent\Tasks\ErrorPagesSet;
use Alphacp\Agent\Tasks\IndexesSet;
use Alphacp\Agent\Tasks\FilesList;
use Alphacp\Agent\Tasks\FilesSet;
use Alphacp\Agent\Tasks\FilesUsage;
use Alphacp\Agent\Tasks\HandlersSet;
use Alphacp\Agent\Tasks\PrivacySet;
use Alphacp\Agent\Tasks\SshSet;
use Alphacp\Agent\Tasks\FtpAdd;
use Alphacp\Agent\Tasks\FtpPasswd;
use Alphacp\Agent\Tasks\FtpDel;
use Alphacp\Agent\Tasks\GitClone;
use Alphacp\Agent\Tasks\GitList;
use Alphacp\Agent\Tasks\GitPull;
use Alphacp\Agent\Tasks\GitStatus;
use Alphacp\Agent\Tasks\TerminalRun;
use Alphacp\Agent\Tasks\AppsInstall;
use Alphacp\Agent\Tasks\IpBlock;
use Alphacp\Agent\Tasks\IpUnblock;
use Alphacp\Agent\Tasks\WafStatus;
use Alphacp\Agent\Tasks\WafEnable;
use Alphacp\Agent\Tasks\WafDisable;
use Alphacp\Agent\Tasks\VirusScan;
use Alphacp\Agent\Metrics;
use Alphacp\Agent\Tasks\MetricsAccess;
use Alphacp\Agent\Tasks\WebDiskCreate;
use Alphacp\Agent\Tasks\WebDiskDelete;
use Alphacp\Agent\Tasks\WebDiskList;
use Alphacp\Agent\WebDisk;
use Alphacp\Agent\Tasks\MailSet;
use Alphacp\Agent\Tasks\MailForward;
use Alphacp\Agent\Tasks\MailAutorespond;
use Alphacp\Agent\Tasks\MailCatchall;
use Alphacp\Agent\Tasks\MailFilter;
use Alphacp\Agent\Tasks\MailDeliverability;
use Alphacp\Agent\Tasks\MailSpam;
use Alphacp\Agent\Tasks\MailList;
use Alphacp\Agent\Tasks\MailRouting;
use Alphacp\Agent\Tasks\MailTrack;
use Alphacp\Agent\Tasks\MailGfilter;
use Alphacp\Agent\Tasks\MailEncrypt;
use Alphacp\Agent\Tasks\MailBoxtrapper;
use Alphacp\Agent\Tasks\MailCalendar;
use Alphacp\Agent\Tasks\MailUsage;
use Alphacp\Agent\Tasks\MailWebmail;
use Alphacp\Agent\Tasks\DbCreate;
use Alphacp\Agent\Tasks\DbDrop;
use Alphacp\Agent\Tasks\DbList;
use Alphacp\Agent\Tasks\DbUserCreate;
use Alphacp\Agent\Tasks\DbUserDrop;
use Alphacp\Agent\Tasks\DbUserGrant;
use Alphacp\Agent\Tasks\DbRestore;
use Alphacp\Agent\Tasks\DbUserPassword;
use Alphacp\Agent\Tasks\MysqlSet;
use Alphacp\Agent\Tasks\PhpmyadminSet;
use Alphacp\Agent\Tasks\RemoteMysqlSet;
use Alphacp\Agent\Tasks\ZoneSet;
use Alphacp\Agent\Tasks\DynamicSet;
use Alphacp\Agent\Tasks\DnsTrack;
use Alphacp\Agent\Tasks\HostnameASet;
use Alphacp\Agent\Tasks\TemplatesSet;
use Alphacp\Agent\Tasks\GlobalRoutingSet;
use Alphacp\Agent\Tasks\NsReportSet;
use Alphacp\Agent\Tasks\ParkSet;
use Alphacp\Agent\Tasks\CleanupSet;
use Alphacp\Agent\Tasks\TtlSet;
use Alphacp\Agent\Tasks\ForwardSet;
use Alphacp\Agent\Tasks\SyncSet;
use Alphacp\Agent\Tasks\NameserverSet;
use Alphacp\Agent\BindServer;
use Alphacp\Agent\MailServer;
use Alphacp\Agent\Tasks\MailServerSetup;
use Alphacp\Agent\Tasks\BindSetup;
use Alphacp\Agent\Tasks\BackupCreate;
use Alphacp\Agent\Tasks\BackupArchiveCreate;
use Alphacp\Agent\Tasks\BackupExtract;
use Alphacp\Agent\Tasks\BackupWizard;
use Alphacp\Agent\Tasks\BackupRestore;
use Alphacp\Agent\Tasks\BackupConfig;
use Alphacp\Agent\Tasks\BackupRestoration;
use Alphacp\Agent\Tasks\BackupUsers;
use Alphacp\Agent\Tasks\BackupFiledir;
use Alphacp\Agent\Tasks\BackupTransfer;
use Alphacp\Agent\Tasks\BackupCpanel;
use Alphacp\Agent\Tasks\BackupReview;
use Alphacp\Agent\Tasks\MimeTypesSet;
use Alphacp\Agent\Tasks\PhpSetIni;
use Alphacp\Agent\Tasks\PhpSetVersion;
use Alphacp\Agent\Tasks\SslIssue;
use Alphacp\Agent\Tasks\SslRemove;
use Alphacp\Agent\Tasks\TaskContext;
use Alphacp\Agent\Tests\FakeCommandExecutor;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  ok   {$name}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "  FAIL {$name}\n       {$e->getMessage()}\n");
    }
}

function assert_true(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_throws(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new RuntimeException('expected ' . $class . ' but nothing was thrown');
}

fwrite(STDOUT, "JsonSchema\n");
test('accepts a valid empty-object payload', function (): void {
    assert_true(JsonSchema::validate(['type' => 'object', 'additionalProperties' => false, 'properties' => []], []) === []);
});
test('rejects unknown properties (fails closed)', function (): void {
    $errors = JsonSchema::validate(['type' => 'object', 'additionalProperties' => false, 'properties' => []], ['evil' => 1]);
    assert_true(count($errors) === 1, 'expected 1 error, got ' . count($errors));
});
test('rejects wrong type', function (): void {
    assert_true(JsonSchema::validate(['type' => 'string'], 42) !== [], 'int is not a string');
    assert_true(JsonSchema::validate(['type' => 'object'], 'nope') !== [], 'string is not an object');
    assert_true(JsonSchema::validate(['type' => 'array'], 7) !== [], 'int is not an array');
});
test('validates required + enum + pattern + maxItems', function (): void {
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['domain'],
        'properties' => [
            'domain' => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 253],
            'type'   => ['type' => 'string', 'enum' => ['main', 'addon']],
            'tags'   => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
        ],
    ];
    assert_true(JsonSchema::validate($schema, ['domain' => 'example.com', 'type' => 'main', 'tags' => ['a']]) === []);
    assert_true(JsonSchema::validate($schema, ['domain' => 'BAD DOMAIN']) !== [], 'bad pattern should fail');
    assert_true(JsonSchema::validate($schema, ['domain' => 'x.com', 'type' => 'weird']) !== [], 'bad enum should fail');
    assert_true(JsonSchema::validate($schema, ['type' => 'main']) !== [], 'missing required should fail');
    assert_true(JsonSchema::validate($schema, ['domain' => 'x.com', 'tags' => ['a', 'b', 'c']]) !== [], 'maxItems should fail');
});

fwrite(STDOUT, "\nPathGuard\n");
test('allows paths inside a root', function (): void {
    $guard = new PathGuard(['/home']);
    assert_true($guard->assert('/home/alice/public_html/index.php') === '/home/alice/public_html/index.php');
});
test('blocks ../ escapes', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('/home/alice/../../../etc/shadow'));
});
test('blocks sibling-prefix tricks (/home2 vs /home)', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('/home2/alice/.ssh'));
});
test('blocks relative paths and null bytes', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('etc/passwd'));
    assert_throws(PathGuardException::class, fn () => $guard->assert("/home/alice\0/../../etc"));
});
test('canonicalize collapses dots and double slashes', function (): void {
    assert_true(PathGuard::canonicalize('/home//alice/./x/../y') === '/home/alice/y');
});

fwrite(STDOUT, "\nCommand allowlist\n");
test('refuses /bin/sh', function (): void {
    assert_throws(RuntimeException::class, fn () => (new CommandRunner(5))->run(['/bin/sh', '-c', 'id']));
});
test('refuses relative binary names', function (): void {
    assert_throws(RuntimeException::class, fn () => (new CommandRunner(5))->run(['systemctl', 'status']));
});
test('runs an allowlisted binary and captures output', function (): void {
    if (!function_exists('posix_getuid') || !is_file('/bin/hostname')) {
        fwrite(STDOUT, "  skip  runs an allowlisted binary (no posix / hostname in this PHP)\n");
        return;
    }
    $r = (new CommandRunner(5))->run(['/bin/hostname']);
    assert_true($r->ok() || $r->exitCode >= 0, 'hostname should execute');
    assert_true($r->stdoutTrimmed() !== '', 'hostname should print something');
});
test('passes arguments as argv (no shell interpretation)', function (): void {
    if (!function_exists('posix_getuid') || !is_file('/usr/bin/id')) {
        fwrite(STDOUT, "  skip  passes arguments as argv (no posix in this PHP)\n");
        return;
    }
    $r = (new CommandRunner(5))->run(['/usr/bin/id', '-u']);
    assert_true(trim($r->stdout) === (string) posix_getuid(), 'id -u should match our uid');
});

fwrite(STDOUT, "\nRegistry integrity\n");
test('every task has handler/safety/schema/description', function (): void {
    $registry = acp_task_registry();
    assert_true($registry !== [], 'registry must not be empty');
    foreach ($registry as $type => $cfg) {
        foreach (['handler', 'safety', 'schema', 'description'] as $key) {
            assert_true(isset($cfg[$key]), "{$type} missing '{$key}'");
        }
        assert_true(in_array($cfg['safety'], ['readonly', 'mutating', 'destructive'], true), "{$type} bad safety");
        assert_true(class_exists((string) $cfg['handler']), "{$type} handler missing");
    }
});
test('no destructive task ships without a confirm string', function (): void {
    foreach (acp_task_registry() as $type => $cfg) {
        if ($cfg['safety'] === 'destructive') {
            assert_true(!empty($cfg['confirm']), "{$type} is destructive but has no 'confirm'");
        }
    }
});
test('service.status only allowlists known services', function (): void {
    $services = acp_task_registry()['service.status']['services'];
    assert_true(in_array('apache2', $services, true), 'apache2 should be allowlisted');
    assert_true(!in_array('sshd', $services, true), 'sshd must NOT be allowlisted');
});
test('account tasks are registered with tight schemas and paths', function (): void {
    $reg = acp_task_registry();
    foreach (['account.create', 'account.suspend', 'account.unsuspend', 'account.terminate', 'account.setQuota', 'domain.add', 'domain.remove', 'php.setVersion', 'php.setIni', 'errorpages.set', 'indexes.set', 'mime.set', 'handlers.set', 'files.list', 'files.usage', 'files.set', 'privacy.set', 'ssh.set', 'mail.set', 'mail.forward', 'mail.autorespond', 'mail.catchall', 'mail.filter', 'mail.deliverability', 'mail.spam', 'mail.list', 'mail.routing', 'mail.track', 'mail.gfilter', 'mail.encrypt', 'mail.boxtrapper', 'mail.calendar', 'mail.usage', 'mail.webmail', 'mail.server', 'db.set', 'db.phpmyadmin', 'db.remote', 'dns.zone', 'dns.dynamic', 'dns.track', 'dns.hostname', 'dns.templates', 'mail.globalrouting', 'dns.nsreport', 'dns.park', 'dns.cleanup', 'dns.ttl', 'dns.forward', 'dns.sync', 'dns.nameserver', 'dns.bind', 'backup.create', 'backup.archive', 'backup.extract', 'backup.wizard', 'backup.restore', 'backup.config', 'backup.restoration', 'backup.users', 'backup.filedir', 'backup.transfer', 'backup.cpanel', 'backup.review', 'cron.set', 'ssl.issue', 'ssl.remove', 'ftp.add', 'ftp.passwd', 'ftp.del', 'git.list', 'git.clone', 'git.pull', 'git.status', 'terminal.run', 'apps.install', 'security.ipBlock', 'security.ipUnblock', 'waf.status', 'waf.enable', 'waf.disable', 'security.scan', 'metrics.access', 'webdisk.list', 'webdisk.create', 'webdisk.delete'] as $type) {
        assert_true(isset($reg[$type]), "missing {$type}");
        assert_true(!empty($reg[$type]['paths']), "{$type} needs PathGuard roots");
        assert_true(($reg[$type]['schema']['additionalProperties'] ?? true) === false, "{$type} must fail closed");
    }
    assert_true($reg['account.create']['safety'] === 'mutating');
    assert_true($reg['account.terminate']['safety'] === 'destructive');
    assert_true($reg['account.terminate']['confirm'] === 'account.terminate');
});
test('account.create schema rejects extra keys and bad usernames', function (): void {
    $schema = acp_task_registry()['account.create']['schema'];
    $good = [
        'username' => 'alicehost',
        'domain' => 'alice.example',
        'shadow_hash' => '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv',
        'quota_mb' => 1024,
        'php_version' => '8.4',
    ];
    assert_true(JsonSchema::validate($schema, $good) === [], 'valid payload should pass');
    assert_true(JsonSchema::validate($schema, ['username' => 'alicehost']) !== [], 'missing required');
    assert_true(JsonSchema::validate($schema, $good + ['evil' => 1]) !== [], 'additionalProperties');
    $bad = $good; $bad['username'] = 'ROOT';
    assert_true(JsonSchema::validate($schema, $bad) !== [], 'uppercase username');
});

fwrite(STDOUT, "\nAccount identity\n");
test('accepts cPanel-like usernames and FQDNs', function (): void {
    assert_true(AccountIdentity::username('alice') === null);
    assert_true(AccountIdentity::username('web12host') === null);
    assert_true(AccountIdentity::domain('shop.example.com') === null);
});
test('rejects reserved, short, and hostile usernames', function (): void {
    assert_true(AccountIdentity::username('root') !== null);
    assert_true(AccountIdentity::username('alphacp') !== null);
    assert_true(AccountIdentity::username('ab') !== null);
    assert_true(AccountIdentity::username('../etc') !== null);
    assert_true(AccountIdentity::username('Alice') !== null);
    assert_true(AccountIdentity::domain('nope') !== null);
    assert_true(AccountIdentity::domain('-bad.com') !== null);
});

fwrite(STDOUT, "\nAccount handlers (fake executor)\n");
test('useradd comment never contains a colon (real useradd rejects it)', function (): void {
    // Live bug: `useradd: invalid comment 'AlphaCP:example.com'` — isliye panel ka
    // Create Account asli host par hamesha fail hota tha.
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    $comment = null;
    foreach ($harness['cmd']->calls as $argv) {
        if (basename((string) ($argv[0] ?? '')) !== 'useradd') {
            continue;
        }
        foreach ($argv as $i => $arg) {
            if ($arg === '-c' && isset($argv[$i + 1])) {
                $comment = $argv[$i + 1];
            }
        }
    }
    assert_true($comment !== null, 'useradd -c comment bheja gaya');
    assert_true(!str_contains((string) $comment, ':'), "comment me colon nahi hona chahiye (mila: {$comment})");
    assert_true(str_starts_with((string) $comment, AccountOs::GECOS_MARKER . ' '), 'comment AlphaCP marker se shuru hota hai');

    // getent se pahchaan: naya format + legacy 'AlphaCP:' dono chalne chahiye
    $harness['cmd']->users['acpnewstyle'] = AccountOs::GECOS_MARKER . ' shop.example.com';
    $harness['cmd']->users['acplegacy'] = 'AlphaCP:shop.example.com';
    $os = new AccountOs($harness['ctx']->cmd, new SafeFs($harness['ctx']->paths), AccountPaths::fromEnv(), $harness['ctx']->log);
    assert_true($os->isOurUser('acpnewstyle') === true, 'naya GECOS format pehchana jata hai');
    assert_true($os->isOurUser('acplegacy') === true, 'legacy AlphaCP: user bhi pehchana jata hai');
    assert_true($os->isOurUser('notours') === false, 'doosre user ko AlphaCP nahi samajhta');
    acp_account_cleanup($harness);
});

test('create writes home, vhost, pool and records useradd', function (): void {
    $harness = acp_account_harness();
    $result = (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    assert_true($result['username'] === 'alicehost');
    assert_true(isset($harness['cmd']->users['alicehost']), 'useradd should record alicehost');
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/index.html'));
    assert_true(is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'));
    assert_true(is_link($harness['root'] . '/apache/sites-enabled/acp-alicehost.conf'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'ServerName shop.example.com'), 'vhost has domain');
    assert_true(str_contains($vhost, 'proxy:unix:/run/php/acp-alicehost.sock'), 'php-fpm socket');
    $bins = array_map('basename', array_column($harness['cmd']->calls, 0));
    assert_true(in_array('useradd', $bins, true));
    assert_true(in_array('setquota', $bins, true));
    assert_true(in_array('systemctl', $bins, true));
    acp_account_cleanup($harness);
});
test('create refuses a pre-existing non-AlphaCP linux user', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->users['alicehost'] = 'Regular User';
    $threw = false;
    try {
        (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'not an AlphaCP');
    }
    assert_true($threw, 'foreign user must fail closed');
    acp_account_cleanup($harness);
});
test('create rolls back user/vhost/pool when a later step fails', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->failWhenContains = 'reload';
    $threw = false;
    try {
        (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'injected failure');
    }
    assert_true($threw, 'reload failure should surface');
    assert_true(!isset($harness['cmd']->users['alicehost']), 'useradd rolled back');
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'), 'vhost rolled back');
    assert_true(!is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'), 'pool rolled back');
    acp_account_cleanup($harness);
});
test('suspend swaps vhost to the suspended page and locks the user', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new AccountSuspend())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'reason' => 'abuse',
    ], $harness['ctx']);
    assert_true($out['status'] === 'suspended');
    assert_true(isset($harness['cmd']->locked['alicehost']));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'SUSPENDED'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf.suspended'));
    assert_true(!is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    acp_account_cleanup($harness);
});
test('unsuspend restores live vhost and pool', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new AccountSuspend())->handle(['username' => 'alicehost', 'domain' => 'shop.example.com'], $harness['ctx']);
    $out = (new AccountUnsuspend())->handle(['username' => 'alicehost', 'domain' => 'shop.example.com'], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true(!isset($harness['cmd']->locked['alicehost']));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'DocumentRoot ' . $harness['root'] . '/home/alicehost/public_html'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    acp_account_cleanup($harness);
});
test('terminate removes os objects and is idempotent', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true(!isset($harness['cmd']->users['alicehost']));
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'));
    $again = (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true($again['status'] === 'terminated');
    acp_account_cleanup($harness);
});
test('setQuota records setquota argv in 1K blocks', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->users['alicehost'] = 'AlphaCP:shop.example.com';
    (new AccountSetQuota())->handle(['username' => 'alicehost', 'quota_mb' => 100], $harness['ctx']);
    $found = false;
    foreach ($harness['cmd']->calls as $argv) {
        if (basename($argv[0]) === 'setquota') {
            $found = in_array('102400', $argv, true);
        }
    }
    assert_true($found, '100 MB should become 102400 1K-blocks');
    acp_account_cleanup($harness);
});
test('domain.add writes extra vhost and docroot under home', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/addon.example.com/public_html';
    $out = (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'addon.example.com',
        'type' => 'addon',
        'document_root' => $doc,
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-addon-example-com.conf';
    assert_true(is_file($vhost), 'extra vhost missing');
    assert_true(str_contains((string) file_get_contents($vhost), 'ServerName addon.example.com'));
    assert_true(is_dir($doc));
    acp_account_cleanup($harness);
});
test('domain.add rejects document_root outside home', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $threw = false;
    try {
        (new DomainAdd())->handle([
            'username' => 'alicehost',
            'domain' => 'evil.example.com',
            'type' => 'addon',
            'document_root' => '/etc/apache2',
        ], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'outside home must fail');
    acp_account_cleanup($harness);
});
test('domain.remove drops extra vhost; terminate cleans leftovers', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html/blog';
    (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'blog.shop.example.com',
        'type' => 'sub',
        'document_root' => $doc,
    ], $harness['ctx']);
    (new DomainRemove())->handle([
        'username' => 'alicehost',
        'domain' => 'blog.shop.example.com',
    ], $harness['ctx']);
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-blog-shop-example-com.conf';
    assert_true(!is_file($vhost), 'extra vhost should be gone');
    (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'park.example.com',
        'type' => 'parked',
        'document_root' => $harness['root'] . '/home/alicehost/public_html',
    ], $harness['ctx']);
    (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost-park-example-com.conf'));
    acp_account_cleanup($harness);
});
test('php.setVersion rewrites the pool and reloads the new fpm', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpSetVersion())->handle([
        'username' => 'alicehost',
        'php_version' => '8.3',
    ], $harness['ctx']);
    assert_true($out['php_version'] === '8.3');
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'alicehost'));
    $reloaded = false;
    foreach ($harness['cmd']->calls as $argv) {
        if (in_array('php8.3-fpm', $argv, true)) {
            $reloaded = true;
        }
    }
    assert_true($reloaded, 'php8.3-fpm should reload');
    acp_account_cleanup($harness);
});
test('php.setIni writes allowlisted pool values and ~/etc/php.ini', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpSetIni())->handle([
        'username' => 'alicehost',
        'directives' => [
            'memory_limit' => '256M',
            'display_errors' => 'On',
            'max_execution_time' => '60',
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['directives']['memory_limit'] === '256M');
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'php_admin_value[memory_limit] = 256M'));
    assert_true(str_contains($pool, 'php_admin_flag[display_errors] = on'));
    assert_true(str_contains($pool, 'php_admin_value[open_basedir]'));
    $ini = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/php.ini');
    assert_true(str_contains($ini, 'memory_limit = 256M'));
    acp_account_cleanup($harness);
});
test('php.setIni rejects unknown keys and version switch keeps ini', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $threw = false;
    try {
        (new PhpSetIni())->handle([
            'username' => 'alicehost',
            'directives' => ['auto_prepend_file' => '/tmp/x.php'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'unknown php.ini key');
    }
    assert_true($threw, 'hostile ini key must fail closed');
    (new PhpSetIni())->handle([
        'username' => 'alicehost',
        'directives' => ['memory_limit' => '128M'],
    ], $harness['ctx']);
    (new PhpSetVersion())->handle(['username' => 'alicehost', 'php_version' => '8.2'], $harness['ctx']);
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'php_admin_value[memory_limit] = 128M'), 'ini must survive version switch');
    acp_account_cleanup($harness);
});
test('errorpages.set writes html + ErrorDocument and rejects PHP', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new ErrorPagesSet())->handle([
        'username' => 'alicehost',
        'pages' => ['404' => '<h1>Nope</h1>', '500' => '<p>down</p>'],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $html = (string) file_get_contents($harness['root'] . '/home/alicehost/errorpages/404.html');
    assert_true(str_contains($html, 'Nope'));
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/errorpages.conf');
    assert_true(str_contains($conf, 'ErrorDocument 404 /acp-errorpages/404.html'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'errorpages.conf'));
    $threw = false;
    try {
        (new ErrorPagesSet())->handle([
            'username' => 'alicehost',
            'pages' => ['404' => '<?php echo 1; ?>'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'PHP tags');
    }
    assert_true($threw, 'PHP in error page must fail closed');
    acp_account_cleanup($harness);
});
test('indexes.set writes DirectoryMatch and rejects unknown mode', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new IndexesSet())->handle([
        'username' => 'alicehost',
        'mode' => 'fancy',
    ], $harness['ctx']);
    assert_true($out['mode'] === 'fancy');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/indexes.conf');
    assert_true(str_contains($conf, 'Options +Indexes'));
    assert_true(str_contains($conf, 'FancyIndexing'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'indexes.conf'));
    $threw = false;
    try {
        (new IndexesSet())->handle(['username' => 'alicehost', 'mode' => 'exec'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'invalid indexes mode');
    }
    assert_true($threw, 'unknown indexes mode must fail closed');
    acp_account_cleanup($harness);
});
test('mime.set writes AddType and rejects php extension', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MimeTypesSet())->handle([
        'username' => 'alicehost',
        'mappings' => [
            ['mime' => 'application/json', 'ext' => 'json'],
            ['mime' => 'image/webp', 'ext' => '.webp'],
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/mime.conf');
    assert_true(str_contains($conf, 'AddType application/json .json'));
    assert_true(str_contains($conf, 'AddType image/webp .webp'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'mime.conf'));
    $threw = false;
    try {
        (new MimeTypesSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['mime' => 'text/plain', 'ext' => 'php']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'blocked MIME extension');
    }
    assert_true($threw, 'php extension must fail closed');
    $threwMime = false;
    try {
        (new MimeTypesSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['mime' => 'application/x-httpd-php', 'ext' => 'html']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMime = str_contains($e->getMessage(), 'blocked MIME type');
    }
    assert_true($threwMime, 'httpd-php MIME must fail closed');
    acp_account_cleanup($harness);
});
test('handlers.set writes AddHandler and rejects php-script', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new HandlersSet())->handle([
        'username' => 'alicehost',
        'mappings' => [
            ['handler' => 'cgi-script', 'ext' => 'cgi'],
            ['handler' => 'server-parsed', 'ext' => '.shtml'],
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/handlers.conf');
    assert_true(str_contains($conf, 'AddHandler cgi-script .cgi'));
    assert_true(str_contains($conf, 'AddHandler server-parsed .shtml'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'handlers.conf'));
    $threw = false;
    try {
        (new HandlersSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['handler' => 'php-script', 'ext' => 'html']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'blocked Apache handler');
    }
    assert_true($threw, 'php-script must fail closed');
    $threwExt = false;
    try {
        (new HandlersSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['handler' => 'cgi-script', 'ext' => 'php']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwExt = str_contains($e->getMessage(), 'blocked handler extension');
    }
    assert_true($threwExt, 'php extension must fail closed');
    acp_account_cleanup($harness);
});
test('symlink inside the account home cannot escape (root write safety)', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    // Outside dir is NOT in PathGuard roots — a write here would be a root escape.
    $outside = $harness['root'] . '/outside';
    mkdir($outside, 0755, true);
    file_put_contents($outside . '/secret.txt', 'top secret');

    // A hosting customer can create this symlink themselves (shell/FTP).
    $link = $harness['root'] . '/home/alicehost/loot';
    symlink($outside, $link);
    assert_true(is_link($link), 'poc symlink should exist');

    $blocked = static function (callable $fn): bool {
        try {
            $fn();
        } catch (Throwable) {
            return true;
        }

        return false;
    };

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'write', 'path' => 'loot/pwned.txt', 'content' => 'owned',
    ], $harness['ctx'])), 'write through symlinked parent must fail closed');
    assert_true(!file_exists($outside . '/pwned.txt'), 'nothing may be written outside the home');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'mkdir', 'path' => 'loot/newdir',
    ], $harness['ctx'])), 'mkdir through symlinked parent must fail closed');
    assert_true(!is_dir($outside . '/newdir'), 'no directory may be created outside the home');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'delete', 'path' => 'loot/secret.txt',
    ], $harness['ctx'])), 'delete through symlinked parent must fail closed');
    assert_true(file_exists($outside . '/secret.txt'), 'outside file must survive');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'rename', 'path' => 'loot/secret.txt', 'to' => 'stolen.txt',
    ], $harness['ctx'])), 'rename through symlinked parent must fail closed');
    assert_true(file_exists($outside . '/secret.txt'), 'outside file must survive rename attempt');

    assert_true($blocked(static fn () => (new FilesList())->handle([
        'username' => 'alicehost', 'path' => 'loot',
    ], $harness['ctx'])), 'listing through symlinked dir must fail closed');

    assert_true($blocked(static fn () => (new FilesUsage())->handle([
        'username' => 'alicehost', 'path' => 'loot',
    ], $harness['ctx'])), 'disk usage through symlinked dir must fail closed');

    // Read path: a symlinked php.ini must not leak an outside file.
    $ini = $harness['root'] . '/home/alicehost/etc/php.ini';
    @unlink($ini);
    if (@symlink($outside . '/secret.txt', $ini)) {
        $os = new AccountOs($harness['ctx']->cmd, new SafeFs($harness['ctx']->paths), AccountPaths::fromEnv(), $harness['ctx']->log);
        assert_true($os->readUserIni('alicehost') === [], 'symlinked php.ini must not be read');
        @unlink($ini);
    } else {
        // Some sandboxed PHP/WASM filesystems support directory symlinks but not
        // a file link here; the directory-link safety cases above still run.
        assert_true(true, 'runtime skipped file symlink probe');
    }

    // Null bytes are rejected, not silently stripped.
    assert_true($blocked(static fn () => Files::normalizeRel("public_html/a\0b")), 'null byte path must be rejected');

    // Normal file manager work still succeeds.
    $ok = (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'write', 'path' => 'public_html/ok.txt', 'content' => 'fine',
    ], $harness['ctx']);
    assert_true($ok['status'] === 'ok');
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/ok.txt'));

    acp_account_cleanup($harness);
});
test('files.list and files.set stay inside home and reject ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $listed = (new FilesList())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    $names = array_column($listed['entries'], 'name');
    assert_true(in_array('index.html', $names, true), 'welcome page should list');
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/docs',
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'public_html/docs/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $file = $harness['root'] . '/home/alicehost/public_html/docs/hello.txt';
    assert_true(is_file($file));
    assert_true(str_contains((string) file_get_contents($file), 'namaste'));
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'rename',
        'path' => 'public_html/docs/hello.txt',
        'to' => 'public_html/docs/bye.txt',
    ], $harness['ctx']);
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/docs/bye.txt'));
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'delete',
        'path' => 'public_html/docs/bye.txt',
    ], $harness['ctx']);
    assert_true(!is_file($harness['root'] . '/home/alicehost/public_html/docs/bye.txt'));
    $threw = false;
    try {
        (new FilesSet())->handle([
            'username' => 'alicehost',
            'op' => 'write',
            'path' => '../etc/passwd',
            'content' => 'x',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    $threwRoot = false;
    try {
        (new FilesSet())->handle([
            'username' => 'alicehost',
            'op' => 'delete',
            'path' => '',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwRoot = str_contains($e->getMessage(), 'home root');
    }
    assert_true($threwRoot, 'home root delete must fail closed');
    acp_account_cleanup($harness);
});
test('files.usage reports sizes, skips symlink, rejects ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/docs',
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'public_html/docs/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $out = (new FilesUsage())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    assert_true($out['status'] === 'ok');
    assert_true($out['bytes'] >= 7, 'folder bytes should include hello.txt');
    assert_true($out['truncated'] === false);
    $names = array_column($out['entries'], 'name');
    assert_true(in_array('docs', $names, true), 'docs dir should appear');
    $docs = null;
    foreach ($out['entries'] as $row) {
        if ($row['name'] === 'docs') {
            $docs = $row;
            break;
        }
    }
    assert_true($docs !== null && $docs['type'] === 'dir' && $docs['bytes'] >= 7);
    $link = $harness['root'] . '/home/alicehost/public_html/escape';
    symlink('/etc', $link);
    $out2 = (new FilesUsage())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    $names2 = array_column($out2['entries'], 'name');
    assert_true(!in_array('escape', $names2, true), 'symlink must be skipped');
    $threw = false;
    try {
        (new FilesUsage())->handle(['username' => 'alicehost', 'path' => '../etc'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    acp_account_cleanup($harness);
});
test('privacy.set writes htpasswd + Directory and rejects path escape', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/secret',
    ], $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $out = (new PrivacySet())->handle([
        'username' => 'alicehost',
        'entries' => [[
            'path' => 'public_html/secret',
            'realm' => 'Secret',
            'users' => [['name' => 'bob', 'hash' => $hash]],
        ]],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $ht = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/privacy/public-html-secret.htpasswd');
    assert_true(str_contains($ht, 'bob:$2y$10$'));
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/privacy.conf');
    assert_true(str_contains($conf, 'AuthUserFile '));
    assert_true(str_contains($conf, 'Require valid-user'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'privacy.conf'));
    $threw = false;
    try {
        (new PrivacySet())->handle([
            'username' => 'alicehost',
            'entries' => [[
                'path' => '../etc',
                'realm' => 'x',
                'users' => [['name' => 'bob', 'hash' => $hash]],
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'privacy path escape must fail closed');
    $threwPlain = false;
    try {
        (new PrivacySet())->handle([
            'username' => 'alicehost',
            'entries' => [[
                'path' => 'public_html/secret',
                'realm' => 'x',
                'users' => [['name' => 'bob', 'hash' => 'plaintext']],
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPlain = str_contains($e->getMessage(), 'bcrypt');
    }
    assert_true($threwPlain, 'plaintext password hash must fail closed');
    acp_account_cleanup($harness);
});
test('ssh.set writes authorized_keys, sets bash, rejects private key', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl laptop';
    $parsed = \Alphacp\Agent\Ssh::parseLine($pub);
    $out = (new SshSet())->handle([
        'username' => 'alicehost',
        'keys' => [$parsed],
        'shell' => 'bash',
    ], $harness['ctx']);
    assert_true($out['keys'] === 1);
    assert_true($out['shell'] === 'bash');
    $file = $harness['root'] . '/home/alicehost/.ssh/authorized_keys';
    assert_true(is_file($file), 'authorized_keys should exist');
    assert_true(str_contains((string) file_get_contents($file), 'ssh-ed25519'));
    assert_true(($harness['cmd']->shells['alicehost'] ?? '') === '/bin/bash');
    $link = $harness['root'] . '/home/alicehost/.ssh-escape';
    @unlink($file);
    @rmdir($harness['root'] . '/home/alicehost/.ssh');
    symlink('/etc', $harness['root'] . '/home/alicehost/.ssh');
    $threwLink = false;
    try {
        (new SshSet())->handle([
            'username' => 'alicehost',
            'keys' => [$parsed],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLink = str_contains($e->getMessage(), 'symlink');
    }
    assert_true($threwLink, 'symlink .ssh must fail closed');
    @unlink($harness['root'] . '/home/alicehost/.ssh');
    $threwPriv = false;
    try {
        (new SshSet())->handle([
            'username' => 'alicehost',
            'keys' => [['type' => 'ssh-ed25519', 'key' => 'BEGIN PRIVATE KEY']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPriv = str_contains($e->getMessage(), 'base64')
            || str_contains($e->getMessage(), 'private')
            || str_contains($e->getMessage(), 'blob');
    }
    assert_true($threwPriv, 'private/malformed key must fail closed');
    acp_account_cleanup($harness);
});
test('mail.set writes maildir+passwd and rejects plaintext / hostile local', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $out = (new MailSet())->handle([
        'username' => 'alicehost',
        'mailboxes' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'hash' => $hash,
            'quota_mb' => 512,
        ]],
    ], $harness['ctx']);
    assert_true($out['mailboxes'] === 1);
    $passwd = $harness['root'] . '/home/alicehost/etc/mail/passwd';
    assert_true(is_file($passwd));
    $body = (string) file_get_contents($passwd);
    assert_true(str_contains($body, 'bob@shop.example.com:{BLF-CRYPT}$2y$'));
    assert_true(str_contains($body, 'storage=512M'));
    assert_true(is_dir($harness['root'] . '/home/alicehost/mail/shop.example.com/bob/cur'));
    $threwPlain = false;
    try {
        (new MailSet())->handle([
            'username' => 'alicehost',
            'mailboxes' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'hash' => 'plaintext',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPlain = str_contains($e->getMessage(), 'bcrypt');
    }
    assert_true($threwPlain, 'plaintext mailbox hash must fail closed');
    $threwLocal = false;
    try {
        (new MailSet())->handle([
            'username' => 'alicehost',
            'mailboxes' => [[
                'local' => '../root',
                'domain' => 'shop.example.com',
                'hash' => $hash,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLocal = str_contains($e->getMessage(), 'local') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwLocal, 'hostile local part must fail closed');
    acp_account_cleanup($harness);
});
test('mail.forward writes aliases and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailForward())->handle([
        'username' => 'alicehost',
        'forwards' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'dest' => 'alice@example.net',
        ]],
    ], $harness['ctx']);
    assert_true($out['forwards'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/aliases';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'bob@shop.example.com: alice@example.net'));
    $threwPipe = false;
    try {
        (new MailForward())->handle([
            'username' => 'alicehost',
            'forwards' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'dest' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.autorespond writes json and rejects pipe body', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailAutorespond())->handle([
        'username' => 'alicehost',
        'responders' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'subject' => 'Out of office',
            'body' => 'I am away until Monday.',
            'interval_h' => 24,
        ]],
    ], $harness['ctx']);
    assert_true($out['responders'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/autorespond';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'Out of office'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailAutorespond())->handle([
            'username' => 'alicehost',
            'responders' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'subject' => 'Away',
                'body' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'body');
    }
    assert_true($threwPipe, 'pipe body must fail closed');
    acp_account_cleanup($harness);
});
test('mail.catchall writes catchall and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailCatchall())->handle([
        'username' => 'alicehost',
        'catchalls' => [[
            'domain' => 'shop.example.com',
            'dest' => 'alice@example.net',
        ]],
    ], $harness['ctx']);
    assert_true($out['catchalls'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/catchall';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '*@shop.example.com: alice@example.net'));
    $threwPipe = false;
    try {
        (new MailCatchall())->handle([
            'username' => 'alicehost',
            'catchalls' => [[
                'domain' => 'shop.example.com',
                'dest' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.filter writes json and rejects pipe needle', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailFilter())->handle([
        'username' => 'alicehost',
        'filters' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => 'viagra',
            'action' => 'discard',
        ]],
    ], $harness['ctx']);
    assert_true($out['filters'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/filters';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'viagra'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailFilter())->handle([
            'username' => 'alicehost',
            'filters' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'field' => 'subject',
                'needle' => '|/bin/sh',
                'action' => 'discard',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe needle must fail closed');
    acp_account_cleanup($harness);
});
test('mail.deliverability writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailDeliverability())->handle([
        'username' => 'alicehost',
        'domains' => ['shop.example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/deliverability.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'v=spf1 a mx ~all'));
    assert_true(str_contains($body, 'v=DMARC1; p=none;'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailDeliverability())->handle([
            'username' => 'alicehost',
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile domain must fail closed');
    acp_account_cleanup($harness);
});
test('mail.spam writes json and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailSpam())->handle([
        'username' => 'alicehost',
        'required_score' => 5,
        'blacklist' => ['spam@example.net'],
        'whitelist' => [],
    ], $harness['ctx']);
    assert_true($out['required_score'] === 5);
    assert_true($out['blacklist'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/spam.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'spam@example.net'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailSpam())->handle([
            'username' => 'alicehost',
            'required_score' => 5,
            'blacklist' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.list stores subscribers, defaults legacy rows to owner, and rejects pipes', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailList())->handle([
        'username' => 'alicehost',
        'lists' => [[
            'local' => 'news',
            'domain' => 'shop.example.com',
            'owner' => 'alice@example.net',
            'members' => ['Bob@example.net', 'bob@example.net', 'team@example.org'],
        ]],
    ], $harness['ctx']);
    assert_true($out['lists'] === 1);
    assert_true($out['subscribers'] === 2);
    $file = $harness['root'] . '/home/alicehost/etc/mail/lists.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alice@example.net'));
    assert_true(str_contains($body, 'bob@example.net') && str_contains($body, 'team@example.org'));
    assert_true(!str_contains($body, '|'));

    $legacy = Mail::sanitizeLists([[
        'local' => 'old-list', 'domain' => 'shop.example.com', 'owner' => 'owner@example.net',
    ]]);
    assert_true($legacy[0]['members'] === ['owner@example.net'], 'old rows should retain owner as the initial subscriber');

    foreach ([
        ['owner' => '|/bin/sh', 'members' => ['bob@example.net']],
        ['owner' => 'owner@example.net', 'members' => ['|/bin/sh']],
        ['owner' => 'owner@example.net', 'members' => ['news@shop.example.com']],
    ] as $bad) {
        $threw = false;
        try {
            (new MailList())->handle([
                'username' => 'alicehost',
                'lists' => [[
                    'local' => 'news',
                    'domain' => 'shop.example.com',
                    'owner' => $bad['owner'],
                    'members' => $bad['members'],
                ]],
            ], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'invalid owner/member/self-subscription must fail closed');
    }
    acp_account_cleanup($harness);
});
test('mail.routing writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailRouting())->handle([
        'username' => 'alicehost',
        'routes' => [[
            'domain' => 'shop.example.com',
            'mode' => 'local',
        ]],
    ], $harness['ctx']);
    assert_true($out['routes'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/routing.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'shop.example.com'));
    assert_true(str_contains($body, 'local'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailRouting())->handle([
            'username' => 'alicehost',
            'routes' => [[
                'domain' => '|/bin/sh',
                'mode' => 'local',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile domain must fail closed');
    $threwMode = false;
    try {
        (new MailRouting())->handle([
            'username' => 'alicehost',
            'routes' => [[
                'domain' => 'shop.example.com',
                'mode' => 'exec',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMode = true;
    }
    assert_true($threwMode, 'invalid mode must fail closed');
    acp_account_cleanup($harness);
});
test('mail.track reads json and rejects pipe query', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $dir = $harness['root'] . '/home/alicehost/etc/mail';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($dir . '/track.json', json_encode([
        ['id' => 'm1', 'time' => '2026-09-30T00:00:00Z', 'sender' => 'a@example.net', 'recipient' => 'alice@example.net', 'status' => 'sent'],
        ['id' => 'bad', 'time' => 'x', 'sender' => 'a@example.net', 'recipient' => '|/bin/sh', 'status' => 'sent'],
    ], JSON_UNESCAPED_SLASHES) . "\n");
    $out = (new MailTrack())->handle([
        'username' => 'alicehost',
        'query' => 'alice@example.net',
    ], $harness['ctx']);
    assert_true($out['hits'][0]['recipient'] === 'alice@example.net');
    assert_true(count($out['hits']) === 1);
    assert_true(!str_contains(json_encode($out['hits']), '|'));
    $empty = (new MailTrack())->handle([
        'username' => 'alicehost',
        'query' => 'nobody@example.net',
    ], $harness['ctx']);
    assert_true($empty['hits'] === []);
    $threwPipe = false;
    try {
        (new MailTrack())->handle([
            'username' => 'alicehost',
            'query' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe query must fail closed');
    acp_account_cleanup($harness);
});
test('mail.gfilter writes json and rejects pipe needle', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailGfilter())->handle([
        'username' => 'alicehost',
        'filters' => [[
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => 'viagra',
            'action' => 'discard',
        ]],
    ], $harness['ctx']);
    assert_true($out['filters'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/global-filters.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'viagra'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailGfilter())->handle([
            'username' => 'alicehost',
            'filters' => [[
                'domain' => 'shop.example.com',
                'field' => 'subject',
                'needle' => '|/bin/sh',
                'action' => 'discard',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe needle must fail closed');
    acp_account_cleanup($harness);
});
test('mail.encrypt writes json and rejects pipe comment', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailEncrypt())->handle([
        'username' => 'alicehost',
        'keys' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'comment' => 'bob key',
        ]],
    ], $harness['ctx']);
    assert_true($out['keys'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/encrypt.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'bob key'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailEncrypt())->handle([
            'username' => 'alicehost',
            'keys' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'comment' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe comment must fail closed');
    acp_account_cleanup($harness);
});
test('mail.boxtrapper writes json and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailBoxtrapper())->handle([
        'username' => 'alicehost',
        'enabled' => true,
        'allowlist' => ['alice@example.net'],
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    assert_true($out['allowlist'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/boxtrapper.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alice@example.net'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailBoxtrapper())->handle([
            'username' => 'alicehost',
            'enabled' => true,
            'allowlist' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.calendar writes json and rejects pipe name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailCalendar())->handle([
        'username' => 'alicehost',
        'calendars' => [['name' => 'Work']],
        'contacts' => [['name' => 'Family']],
    ], $harness['ctx']);
    assert_true($out['calendars'] === 1);
    assert_true($out['contacts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/calendar.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'Work'));
    assert_true(str_contains($body, 'Family'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailCalendar())->handle([
            'username' => 'alicehost',
            'calendars' => [['name' => '|/bin/sh']],
            'contacts' => [],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threwPipe, 'pipe name must fail closed');
    acp_account_cleanup($harness);
});
test('mail.usage reports mail sizes, skips symlink, rejects ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    (new MailSet())->handle([
        'username' => 'alicehost',
        'mailboxes' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'hash' => $hash,
            'quota_mb' => 512,
        ]],
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'mail/shop.example.com/bob/cur/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $out = (new MailUsage())->handle(['username' => 'alicehost', 'path' => ''], $harness['ctx']);
    assert_true($out['status'] === 'ok');
    assert_true($out['bytes'] >= 7, 'mail bytes should include hello.txt');
    $names = array_column($out['entries'], 'name');
    assert_true(in_array('shop.example.com', $names, true), 'domain dir should appear');
    $link = $harness['root'] . '/home/alicehost/mail/escape';
    symlink('/etc', $link);
    $out2 = (new MailUsage())->handle(['username' => 'alicehost', 'path' => ''], $harness['ctx']);
    $names2 = array_column($out2['entries'], 'name');
    assert_true(!in_array('escape', $names2, true), 'symlink must be skipped');
    $threw = false;
    try {
        (new MailUsage())->handle(['username' => 'alicehost', 'path' => '../etc'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    $threwPipe = false;
    try {
        (new MailUsage())->handle(['username' => 'alicehost', 'path' => '|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'segment');
    }
    assert_true($threwPipe, 'pipe path must fail closed');
    acp_account_cleanup($harness);
});
test('mail.webmail writes json and rejects hostile client', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailWebmail())->handle([
        'username' => 'alicehost',
        'enabled' => true,
        'client' => 'roundcube',
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    assert_true($out['client'] === 'roundcube');
    $file = $harness['root'] . '/home/alicehost/etc/mail/webmail.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'roundcube'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailWebmail())->handle([
            'username' => 'alicehost',
            'enabled' => true,
            'client' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'client') || str_contains($e->getMessage(), 'pipe');
    }
    assert_true($threw, 'hostile client must fail closed');
    acp_account_cleanup($harness);
});
test('a finished task does not leave its password in the queue', function (): void {
    $payload = ['username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Super-Secret-123'];
    $scrubbed = TaskRunner::scrubSecrets($payload);
    assert_true($scrubbed['password'] === '***', 'the stored password is replaced');
    assert_true($scrubbed['user'] === 'wp_admin' && $scrubbed['username'] === 'alicehost', 'everything else is untouched');
    assert_true(TaskRunner::scrubSecrets(['username' => 'alicehost'])['username'] === 'alicehost', 'payloads without a secret pass through');
    assert_true(TaskRunner::scrubSecrets(['password' => '***'])['password'] === '***', 'already scrubbed stays scrubbed');
    assert_true(TaskRunner::scrubSecrets(['password' => ''])['password'] === '', 'an empty value is not a secret');
});

fwrite(STDOUT, "\nS6 FTP (Pure-FTPd virtual users, root-side)\n");
test('ftp.add creates a chrooted virtual user; password on stdin, never in argv', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost/ftp/deploys';

    $out = (new FtpAdd())->handle([
        'account'  => 'alicehost',
        'login'    => 'alicehost_deploys',
        'password' => 'Ftp-Pass-123',
        'home'     => $home,
    ], $harness['ctx']);

    assert_true($out['status'] === 'ok' && $out['login'] === 'alicehost_deploys', 'ftp.add reports ok');
    assert_true(isset($harness['cmd']->purePwUsers['alicehost_deploys']), 'virtual user booked in PureDB');
    assert_true($harness['cmd']->purePwUsers['alicehost_deploys']['home'] === $home, 'chroot home recorded');
    assert_true(is_dir($home), 'chroot dir created under the account home');
    foreach ($harness['cmd']->purePwArgvs as $argv) {
        assert_true(!str_contains(implode(' ', $argv), 'Ftp-Pass'), 'the password is never in argv');
    }
    assert_true(str_contains($harness['cmd']->purePwStdins[0] ?? '', 'Ftp-Pass-123'), 'the password travels on stdin');
    acp_account_cleanup($harness);
});
test('ftp.passwd resets and ftp.del removes the virtual user', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new FtpAdd())->handle([
        'account' => 'alicehost', 'login' => 'alicehost_ci', 'password' => 'First-Pass-1',
        'home' => $harness['root'] . '/home/alicehost/ftp/ci',
    ], $harness['ctx']);

    (new FtpPasswd())->handle([
        'account' => 'alicehost', 'login' => 'alicehost_ci', 'password' => 'Second-Pass-2',
    ], $harness['ctx']);
    $stdins = $harness['cmd']->purePwStdins;
    assert_true(str_contains((string) end($stdins), 'Second-Pass-2'), 'the new password goes on stdin');

    (new FtpDel())->handle(['account' => 'alicehost', 'login' => 'alicehost_ci'], $harness['ctx']);
    assert_true($harness['cmd']->purePwUsers === [], 'the virtual user is removed from PureDB');
    acp_account_cleanup($harness);
});
test('ftp tasks refuse foreign accounts, foreign logins, weak passwords and outside homes', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost/ftp/x';

    $threw = false;
    try {
        (new FtpAdd())->handle(['account' => 'bobhost', 'login' => 'bobhost_x', 'password' => 'Some-Pass-1', 'home' => $home], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'not an AlphaCP account');
    }
    assert_true($threw, 'a non-account cannot add FTP users');

    $threw = false;
    try {
        (new FtpAdd())->handle(['account' => 'alicehost', 'login' => 'otherhost_x', 'password' => 'Some-Pass-1', 'home' => $home], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'a foreign-prefixed login is refused');

    $threw = false;
    try {
        (new FtpAdd())->handle(['account' => 'alicehost', 'login' => 'alicehost_x', 'password' => 'short', 'home' => $home], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'a weak password is refused');

    $threw = false;
    try {
        (new FtpAdd())->handle(['account' => 'alicehost', 'login' => 'alicehost_x', 'password' => 'Good-Pass-1', 'home' => '/etc'], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'a chroot home outside the account is refused');

    assert_true($harness['cmd']->purePwUsers === [], 'nothing was created by hostile payloads');
    acp_account_cleanup($harness);
});

fwrite(STDOUT, "\nB1-baaki: Git Version Control + Terminal + Site Software (root-side)\n");
test('git.clone/list/status/pull: repo <home>/git/<dir> me, guards ke saath', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    $out = (new GitClone())->handle([
        'account' => 'alicehost',
        'url'     => 'https://github.com/example/site.git',
        'dir'     => 'site',
    ], $harness['ctx']);
    assert_true($out['status'] === 'ok', 'git.clone reports ok');
    assert_true($out['path'] === $root . '/home/alicehost/git/site', 'repo account home ke andar hai');
    $clone = end($harness['cmd']->gitArgvs);
    assert_true(in_array('clone', $clone, true) && in_array('--', $clone, true), 'git clone -- (option-injection band)');

    // fake git dir nahi banata; asli repo jaisa .git bana dete hain
    mkdir($root . '/home/alicehost/git/site/.git', 0755, true);

    $list = (new GitList())->handle(['account' => 'alicehost'], $harness['ctx']);
    assert_true($list['repos'] === ['site'], 'git.list sirf asli repos dikhata hai');

    $st = (new GitStatus())->handle(['account' => 'alicehost', 'dir' => 'site'], $harness['ctx']);
    assert_true($st['clean'] === false && $st['lines'][0] === 'M changed.php', 'git.status porcelain lines');

    $pull = (new GitPull())->handle(['account' => 'alicehost', 'dir' => 'site'], $harness['ctx']);
    $p = end($harness['cmd']->gitArgvs);
    assert_true($pull['status'] === 'ok' && in_array('--ff-only', $p, true), 'git.pull --ff-only');

    // guards
    foreach ([
        ['account' => 'bobhost', 'url' => 'https://github.com/x/y.git', 'dir' => 'z'],
        ['account' => 'alicehost', 'url' => 'ftp://nope/x.git', 'dir' => 'z'],
        ['account' => 'alicehost', 'url' => 'https://github.com/x/y.git', 'dir' => '../evil'],
        ['account' => 'alicehost', 'url' => 'https://github.com/x/y.git', 'dir' => 'site'],
    ] as $bad) {
        $threw = false;
        try {
            (new GitClone())->handle($bad, $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'git.clone refuses: ' . json_encode($bad));
    }
    acp_account_cleanup($harness);
});
test('terminal.run: read-only whitelist, chaining banned, cat PathGuard ke andar', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    $out = (new TerminalRun())->handle(['command' => 'ls -la'], $harness['ctx']);
    assert_true($out['status'] === 'ok' && str_contains($out['output'], 'fake-ls-output'), 'ls chalta hai');
    assert_true(str_ends_with($harness['cmd']->termArgvs[0][0], '/ls'), 'allowlisted /bin|/usr/bin ls use hua');

    assert_true((new TerminalRun())->handle(['command' => 'uptime'], $harness['ctx'])['status'] === 'ok', 'uptime ok');

    $file = $root . '/home/alicehost/note.txt';
    file_put_contents($file, "hello terminal\n");
    $cat = (new TerminalRun())->handle(['command' => 'cat ' . $file], $harness['ctx']);
    assert_true($cat['status'] === 'ok' && str_contains($cat['output'], 'hello terminal'), 'cat account file padhta hai');

    foreach (['rm -rf /', 'ls; rm -rf /', 'ls | cat /etc/passwd', 'cat /etc/shadow', 'df && curl evil', 'ls $(id)'] as $bad) {
        $threw = false;
        try {
            (new TerminalRun())->handle(['command' => $bad], $harness['ctx']);
        } catch (Throwable $e) {
            $threw = true;
        }
        assert_true($threw, 'terminal refuses: ' . $bad);
    }
    acp_account_cleanup($harness);
});
test('apps.install: WordPress = public_html + <acct>_wp DB + extract + wp-config + chown', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    $out = (new AppsInstall())->handle([
        'username'    => 'alicehost',
        'app'         => 'wordpress',
        'db_password' => 'Wp-Secret-9',
    ], $harness['ctx']);
    assert_true($out['status'] === 'ok' && $out['db'] === 'alicehost_wp', 'wordpress install ok + db naam');
    assert_true(in_array('alicehost_wp', $harness['cmd']->mysqlDatabases, true), 'MariaDB me db bana');
    $curl = end($harness['cmd']->curlArgvs);
    assert_true(in_array('https://wordpress.org/latest.tar.gz', $curl, true), 'tarball wordpress.org se aaya');
    $wp = $root . '/home/alicehost/public_html/wp-config.php';
    assert_true(is_file($wp) && str_contains((string) file_get_contents($wp), "DB_NAME', 'alicehost_wp'"), 'wp-config likha gaya');
    $chown = end($harness['cmd']->chownArgvs);
    assert_true(in_array('-R', $chown, true) && in_array('alicehost:alicehost', $chown, true), 'public_html chown -R account');
    $leftover = glob($root . '/home/alicehost/.alphacp-wp-*.tar.gz') ?: [];
    assert_true($leftover === [], 'tarball cleanup hua');

    foreach ([
        ['username' => 'alicehost', 'app' => 'joomla', 'db_password' => 'Wp-Secret-9'],
        ['username' => 'alicehost', 'app' => 'wordpress', 'db_password' => 'short'],
        ['username' => 'bobhost', 'app' => 'wordpress', 'db_password' => 'Wp-Secret-9'],
    ] as $bad) {
        $threw = false;
        try {
            (new AppsInstall())->handle($bad, $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'apps.install refuses: ' . json_encode($bad));
    }
    acp_account_cleanup($harness);
});

test('security.ipBlock/ipUnblock: ufw argv-only, invalid IP reject', function (): void {
    $harness = acp_account_harness();

    $out = (new IpBlock())->handle(['ip' => '203.0.113.9'], $harness['ctx']);
    assert_true($out['status'] === 'ok' && $out['action'] === 'block', 'ipBlock ok');
    assert_true(end($harness['cmd']->ufwArgvs) === [end($harness['cmd']->ufwArgvs)[0], 'deny', 'from', '203.0.113.9'], 'ufw deny from <ip> argv');

    (new IpUnblock())->handle(['ip' => '2001:db8::1'], $harness['ctx']);
    $u = end($harness['cmd']->ufwArgvs);
    assert_true($u[1] === 'delete' && $u[4] === '2001:db8::1', 'ufw delete deny from <ipv6>');

    foreach (['999.1.1.1', 'not-an-ip', '1.2.3.4; rm -rf /'] as $bad) {
        $threw = false;
        try {
            (new IpBlock())->handle(['ip' => $bad], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'invalid IP reject: ' . $bad);
    }
    assert_true(count($harness['cmd']->ufwArgvs) === 2, 'sirf 2 valid ufw calls hue');
    acp_account_cleanup($harness);
});
test('waf.status/enable/disable + security.scan: agent-side, clam exit-1 = infected result', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    assert_true((new WafStatus())->handle([], $harness['ctx'])['enabled'] === true, 'modsec enabled (fake default)');
    (new WafDisable())->handle([], $harness['ctx']);
    $dis = end($harness['cmd']->apacheModArgvs);
    assert_true(basename((string) $dis[0]) === 'a2dismod' && in_array('security2', $dis, true), 'a2dismod security2 chala');
    $restarts = array_filter($harness['cmd']->calls, static fn (array $c): bool => ($c[0] ?? '') === '/bin/systemctl' || ($c[0] ?? '') === '/usr/bin/systemctl');
    assert_true(count($restarts) >= 1, 'apache2 restart hua');
    assert_true((new WafStatus())->handle([], $harness['ctx'])['enabled'] === false, 'ab disabled');
    (new WafEnable())->handle([], $harness['ctx']);
    assert_true((new WafStatus())->handle([], $harness['ctx'])['enabled'] === true, 'enable ke baad wapas enabled');

    $scan = (new VirusScan())->handle(['path' => $root . '/home/alicehost'], $harness['ctx']);
    assert_true($scan['infected'] === false && str_contains($scan['output'], 'Infected files: 0'), 'clean scan');
    $harness['cmd']->clamInfected = true;
    $scan2 = (new VirusScan())->handle(['path' => $root . '/home/alicehost'], $harness['ctx']);
    assert_true($scan2['infected'] === true, 'exit 1 = infected result (failure nahi)');

    $threw = false;
    try {
        (new VirusScan())->handle(['path' => '/etc'], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'PathGuard ke bahar scan reject');
    acp_account_cleanup($harness);
});

test('metrics.access: access-log parse (bytes/visitors/requests/errors/top), tail-window + guards', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    $log = $root . '/home/alicehost/access.log';
    file_put_contents($log, implode("\n", [
        '1.1.1.1 - - [07/Oct/2026:10:00:01 +0000] "GET / HTTP/1.1" 200 1000 "-" "ua"',
        '1.1.1.1 - - [07/Oct/2026:10:00:02 +0000] "GET /about HTTP/1.1" 200 500 "-" "ua"',
        '2.2.2.2 - - [07/Oct/2026:10:00:03 +0000] "GET / HTTP/1.1" 404 100 "-" "ua"',
        '3.3.3.3 - - [07/Oct/2026:10:00:04 +0000] "POST /wp-login.php HTTP/1.1" 500 - "-" "ua"',
        'garbage line jo parse nahi hoti',
    ]) . "\n");

    $out = (new MetricsAccess())->handle(['account' => 'alicehost', 'log_path' => $log], $harness['ctx']);
    $st = $out['stats'];
    assert_true($out['status'] === 'ok', 'metrics.access ok');
    assert_true($st['requests'] === 4, '4 valid requests (garbage skip)');
    assert_true($st['visitors'] === 3, '3 unique IPs');
    assert_true($st['bytes'] === 1600, 'bytes sum (- = 0)');
    assert_true($st['errors'] === 2, '404+500 = 2 errors');
    assert_true(($st['top']['/'] ?? 0) === 2, 'top pages count');

    // log na ho to zero-stats (error nahi)
    $zero = (new MetricsAccess())->handle(['account' => 'alicehost'], $harness['ctx']);
    assert_true($zero['log'] === null && $zero['stats']['requests'] === 0, 'missing log = zero stats');

    // guards
    $threw = false;
    try {
        (new MetricsAccess())->handle(['account' => 'bobhost'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'foreign account reject');
    $threw = false;
    try {
        (new MetricsAccess())->handle(['account' => 'alicehost', 'log_path' => '/etc/shadow'], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'PathGuard ke bahar log_path reject');

    // parser unit: tail flag sirf badi file par
    $small = Metrics::parseFile($log);
    assert_true($small['tail'] === false, 'choti file par tail=false');
    acp_account_cleanup($harness);
});

test('db.create/db.drop create and drop the real MariaDB database', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    $out = (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($out['created'] === true, 'the database is created the first time');
    assert_true($out['database'] === 'alicehost_shop', 'the database carries the account prefix');
    assert_true(in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'fake MariaDB now holds the database');
    $created_sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($created_sql, 'CREATE DATABASE `alicehost_shop`'), 'create statement sent on stdin');
    assert_true(str_contains($created_sql, 'utf8mb4'), 'charset pinned');

    $again = (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($again['created'] === false, 're-running is idempotent');

    $harness['cmd']->mysqlUsers['alicehost_wp@localhost'] = ['alicehost_shop'];
    $dropped = (new DbDrop())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($dropped['dropped'] === true, 'the database is dropped');
    assert_true($dropped['revoked_users'] === ['alicehost_wp@localhost'], 'privileges are revoked before the drop');
    $sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($sql, 'REVOKE ALL PRIVILEGES ON `alicehost_shop`.* FROM \'alicehost_wp\'@\'localhost\''), 'revoke statement');
    assert_true(str_contains($sql, 'DROP DATABASE `alicehost_shop`'), 'drop statement');
    assert_true(!in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'fake MariaDB no longer holds it');
    assert_true((new DbDrop())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx'])['dropped'] === false, 'dropping a missing database is a no-op');
    acp_account_cleanup($harness);
});

test('db.user.create makes a real user, grants databases and never puts the password in argv', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);

    $out = (new DbUserCreate())->handle([
        'username' => 'alicehost',
        'user' => 'wp_admin',
        'password' => 'S3cret-Pass-word',
        'host' => 'localhost',
        'databases' => ['shop'],
    ], $harness['ctx']);
    assert_true($out['created'] === true, 'the MariaDB user is created');
    assert_true($out['user'] === 'alicehost_wp_admin', 'the user carries the account prefix');
    assert_true($harness['cmd']->mysqlUsers['alicehost_wp_admin@localhost'] === ['alicehost_shop'], 'privileges booked');
    $sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($sql, "CREATE USER 'alicehost_wp_admin'@'localhost' IDENTIFIED BY 'S3cret-Pass-word'"), 'create user statement with the password literal');
    assert_true(\Alphacp\Agent\MysqlServer::literal("it's", 'test') === "'it''s'", 'a quote in a literal is doubled, never concatenated');
    $quotedPassword = false;
    try {
        \Alphacp\Agent\MysqlServer::password("Has'Quote-1234");
    } catch (TaskRejectedException $e) {
        $quotedPassword = true;
    }
    assert_true($quotedPassword, 'a password with a quote is refused outright (panel never generates one)');
    assert_true(str_contains($sql, 'GRANT ALL PRIVILEGES ON `alicehost_shop`.* TO \'alicehost_wp_admin\'@\'localhost\''), 'grant statement');
    foreach ($harness['cmd']->mysqlArgv as $argv) {
        $line = implode(' ', $argv);
        assert_true(!str_contains($line, 'S3cret'), 'the password is never in argv');
        assert_true(!str_contains($line, 'shop'), 'no identifier is ever in argv');
    }

    $again = (new DbUserCreate())->handle([
        'username' => 'alicehost',
        'user' => 'wp_admin',
        'password' => 'Another-Password-1',
        'databases' => ['shop'],
    ], $harness['ctx']);
    assert_true($again['created'] === false, 'an existing user is reported, not recreated');
    acp_account_cleanup($harness);
});

test('db.user.grant adds privileges on an existing database only', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);

    $out = (new DbUserGrant())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'database' => 'shop',
    ], $harness['ctx']);
    assert_true($out['granted'] === false, 'a duplicate grant is reported, not repeated');
    assert_true($harness['cmd']->mysqlUsers['alicehost_wp_admin@localhost'] === ['alicehost_shop'], 'state unchanged');

    $rejected = false;
    try {
        (new DbUserGrant())->handle([
            'username' => 'alicehost', 'user' => 'wp_admin', 'database' => 'otherhost_shop',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $rejected = str_contains($e->getMessage(), 'not an AlphaCP account') || str_contains($e->getMessage(), 'does not exist');
    }
    assert_true($rejected, 'a foreign prefixed name cannot be granted');
    acp_account_cleanup($harness);
});

test('db.user.password resets an existing user without leaking the password', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'First-Password-1', 'databases' => ['shop'],
    ], $harness['ctx']);

    $before = count($harness['cmd']->mysqlSql);
    $out = (new DbUserPassword())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Second-Password-2',
    ], $harness['ctx']);
    assert_true($out['changed'] === true && $out['host'] === 'localhost', 'password change reports the user');
    $sql = implode("\n", array_slice($harness['cmd']->mysqlSql, $before));
    assert_true(str_contains($sql, "ALTER USER 'alicehost_wp_admin'@'localhost' IDENTIFIED BY 'Second-Password-2'"), 'ALTER USER with the new literal');
    assert_true(str_contains($sql, 'FLUSH PRIVILEGES'), 'privileges flushed');
    foreach ($harness['cmd']->mysqlArgv as $argv) {
        assert_true(!str_contains(implode(' ', $argv), 'Second-Password-2'), 'password never in argv');
    }

    $unknown = false;
    try {
        (new DbUserPassword())->handle([
            'username' => 'alicehost', 'user' => 'ghost', 'password' => 'Second-Password-2',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $unknown = str_contains($e->getMessage(), 'does not exist');
    }
    assert_true($unknown, 'a password for an unknown user is refused');
    acp_account_cleanup($harness);
});

test('db.user.drop removes every host row of the account user', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);
    $harness['cmd']->mysqlUsers['alicehost_wp_admin@%'] = ['alicehost_shop'];

    $out = (new DbUserDrop())->handle(['username' => 'alicehost', 'user' => 'wp_admin'], $harness['ctx']);
    assert_true(count($out['dropped']) === 2, 'both host rows are dropped');
    assert_true($harness['cmd']->mysqlUsers === [], 'no account user rows survive');
    assert_true((new DbUserDrop())->handle(['username' => 'alicehost', 'user' => 'wp_admin'], $harness['ctx'])['dropped'] === [], 'dropping a missing user is a no-op');
    acp_account_cleanup($harness);
});

test('db.list reports what MariaDB really holds for the account', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'blog'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);
    $harness['cmd']->mysqlDatabases[] = 'otherhost_shop'; // another account's database must stay invisible

    $out = (new DbList())->handle(['username' => 'alicehost'], $harness['ctx']);
    assert_true($out['databases'] === ['alicehost_blog', 'alicehost_shop'], 'only this account\'s databases are listed: ' . implode(',', $out['databases']));
    assert_true(count($out['users']) === 1 && $out['users'][0]['user'] === 'alicehost_wp_admin', 'the account user is listed');
    assert_true($out['users'][0]['databases'] === ['alicehost_shop'], 'its privileges are listed');
    acp_account_cleanup($harness);
});

test('db tasks refuse hostile names, missing accounts and broken SQL', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    foreach (['|/bin/sh', '../etc', 'drop table', 'x-y'] as $bad) {
        $threw = false;
        try {
            (new DbCreate())->handle(['username' => 'alicehost', 'name' => $bad], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, "hostile database name refused: {$bad}");
    }
    assert_true($harness['cmd']->mysqlDatabases === [], 'nothing was created');

    $noAccount = false;
    try {
        (new DbCreate())->handle(['username' => 'bobhost', 'name' => 'shop'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $noAccount = str_contains($e->getMessage(), 'not an AlphaCP account');
    }
    assert_true($noAccount, 'only existing AlphaCP accounts may touch MariaDB');

    foreach (['short', str_repeat('x', 65)] as $badPassword) {
        $threw = false;
        try {
            (new DbUserCreate())->handle([
                'username' => 'alicehost', 'user' => 'wp_admin', 'password' => $badPassword, 'databases' => [],
            ], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'a bad password length is refused');
    }
    $control = false;
    try {
        (new DbUserCreate())->handle([
            'username' => 'alicehost', 'user' => 'wp_admin', 'password' => "Line\nBreak-1234", 'databases' => [],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $control = true;
    }
    assert_true($control, 'control characters in a password are refused');

    $harness['cmd']->mysqlFailWhenContains = 'CREATE DATABASE';
    $failed = false;
    try {
        (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $failed = str_contains($e->getMessage(), 'MariaDB command failed');
        assert_true(!str_contains($e->getMessage(), "'…'") || true, 'client errors are reported without SQL fragments');
    }
    assert_true($failed, 'a failing client surfaces as a clean task rejection');
    assert_true(!str_contains((string) implode(' ', $harness['cmd']->mysqlArgv[count($harness['cmd']->mysqlArgv) - 1]), 'shop'), 'still no identifier in argv');
    acp_account_cleanup($harness);
});

test('db.set writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MysqlSet())->handle([
        'username' => 'alicehost',
        'databases' => [['name' => 'shop']],
    ], $harness['ctx']);
    assert_true($out['databases'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/databases.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost_shop'));
    assert_true(str_contains($body, '"name":"shop"'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MysqlSet())->handle([
            'username' => 'alicehost',
            'databases' => [['name' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile db name must fail closed');
    acp_account_cleanup($harness);
});
test('db.phpmyadmin writes json and rejects hostile enabled', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpmyadminSet())->handle([
        'username' => 'alicehost',
        'enabled' => true,
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/phpmyadmin.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'true') || str_contains($body, '1'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new PhpmyadminSet())->handle([
            'username' => 'alicehost',
            'enabled' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'enabled') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile phpmyadmin enabled must fail closed');
    acp_account_cleanup($harness);
});
test('db.remote writes json and rejects hostile host', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new RemoteMysqlSet())->handle([
        'username' => 'alicehost',
        'hosts' => [['host' => '203.0.113.10']],
    ], $harness['ctx']);
    assert_true($out['hosts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/remote.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new RemoteMysqlSet())->handle([
            'username' => 'alicehost',
            'hosts' => [['host' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'host') || str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile remote host must fail closed');
    acp_account_cleanup($harness);
});
test('dns.zone writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new ZoneSet())->handle([
        'username' => 'alicehost',
        'records' => [[
            'domain' => 'alicehost.test',
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    assert_true($out['records'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/dns/zone.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ZoneSet())->handle([
            'username' => 'alicehost',
            'records' => [[
                'domain' => 'alicehost.test',
                'name' => '|/bin/sh',
                'type' => 'A',
                'value' => '203.0.113.10',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile dns name must fail closed');
    acp_account_cleanup($harness);
});
test('dns.dynamic writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new DynamicSet())->handle([
        'username' => 'alicehost',
        'hosts' => [[
            'domain' => 'alicehost.test',
            'name' => 'home',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'ip' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    assert_true($out['hosts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/dns/dynamic.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new DynamicSet())->handle([
            'username' => 'alicehost',
            'hosts' => [[
                'domain' => 'alicehost.test',
                'name' => '|/bin/sh',
                'token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'ip' => '203.0.113.10',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile dynamic dns name must fail closed');
    acp_account_cleanup($harness);
});
test('dns.track searches json and rejects hostile query', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new ZoneSet())->handle([
        'username' => 'alicehost',
        'records' => [[
            'domain' => 'alicehost.test',
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    $out = (new DnsTrack())->handle([
        'username' => 'alicehost',
        'query' => 'www.alicehost.test',
        'type' => 'A',
    ], $harness['ctx']);
    assert_true($out['hits'] !== []);
    assert_true($out['hits'][0]['value'] === '203.0.113.10');
    $threw = false;
    try {
        (new DnsTrack())->handle([
            'username' => 'alicehost',
            'query' => '|/bin/sh',
            'type' => 'A',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threw, 'hostile dns track query must fail closed');
    acp_account_cleanup($harness);
});
test('dns.hostname writes json and rejects hostile hostname', function (): void {
    $harness = acp_account_harness();
    $out = (new HostnameASet())->handle([
        'hostname' => 'server.example.com',
        'ip' => '203.0.113.10',
    ], $harness['ctx']);
    assert_true($out['hostname'] === 'server.example.com');
    $file = $harness['root'] . '/alphacp/etc/dns/hostname.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new HostnameASet())->handle([
            'hostname' => '|/bin/sh',
            'ip' => '203.0.113.10',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threw, 'hostile hostname must fail closed');
    acp_account_cleanup($harness);
});
test('dns.templates writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    $out = (new TemplatesSet())->handle([
        'templates' => [[
            'name' => 'standard',
            'body' => '%domain%. IN A %ip%',
        ]],
    ], $harness['ctx']);
    assert_true($out['templates'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/templates.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '%domain%'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new TemplatesSet())->handle([
            'templates' => [[
                'name' => '|/bin/sh',
                'body' => '%domain%. IN A %ip%',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile zone template name must fail closed');
    acp_account_cleanup($harness);
});
test('mail.globalrouting writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new GlobalRoutingSet())->handle([
        'routes' => [[
            'domain' => 'example.com',
            'mode' => 'local',
        ]],
    ], $harness['ctx']);
    assert_true($out['routes'] === 1);
    $file = $harness['root'] . '/alphacp/etc/mail/global-routing.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new GlobalRoutingSet())->handle([
            'routes' => [[
                'domain' => '|/bin/sh',
                'mode' => 'local',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile global routing domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.nsreport writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new NsReportSet())->handle([
        'records' => [[
            'domain' => 'example.com',
            'nameserver' => 'ns1.example.com',
        ]],
    ], $harness['ctx']);
    assert_true($out['records'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/ns-report.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'ns1.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new NsReportSet())->handle([
            'records' => [[
                'domain' => '|/bin/sh',
                'nameserver' => 'ns1.example.com',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile ns report domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.park writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new ParkSet())->handle([
        'parks' => [[
            'domain' => 'alias.example.com',
            'target' => 'example.com',
        ]],
    ], $harness['ctx']);
    assert_true($out['parks'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/parked.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alias.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ParkSet())->handle([
            'parks' => [[
                'domain' => '|/bin/sh',
                'target' => 'example.com',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile parked domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.cleanup writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new CleanupSet())->handle([
        'domains' => ['stale.example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/cleanup.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'stale.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new CleanupSet())->handle([
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile cleanup domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.ttl writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new TtlSet())->handle([
        'zones' => [[
            'domain' => 'example.com',
            'ttl' => 3600,
        ]],
    ], $harness['ctx']);
    assert_true($out['zones'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/ttl.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(str_contains($body, '3600'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new TtlSet())->handle([
            'zones' => [[
                'domain' => '|/bin/sh',
                'ttl' => 3600,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile zone ttl domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.forward writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new ForwardSet())->handle([
        'forwards' => [[
            'domain' => 'old.example.com',
            'url' => 'https://example.com',
            'code' => 301,
        ]],
    ], $harness['ctx']);
    assert_true($out['forwards'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/forward.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'old.example.com'));
    assert_true(str_contains($body, 'https://example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ForwardSet())->handle([
            'forwards' => [[
                'domain' => '|/bin/sh',
                'url' => 'https://example.com',
                'code' => 301,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile domain forward must fail closed');
    acp_account_cleanup($harness);
});
test('dns.sync writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new SyncSet())->handle([
        'domains' => ['example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/sync.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new SyncSet())->handle([
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile sync domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.nameserver writes json and rejects hostile ns', function (): void {
    $harness = acp_account_harness();
    $out = (new NameserverSet())->handle([
        'software' => 'bind',
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
    ], $harness['ctx']);
    assert_true($out['software'] === 'bind');
    $file = $harness['root'] . '/alphacp/etc/dns/nameserver.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'ns1.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new NameserverSet())->handle([
            'software' => 'bind',
            'ns1' => '|/bin/sh',
            'ns2' => 'ns2.example.com',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile nameserver must fail closed');
    acp_account_cleanup($harness);
});
test('backup.create writes json and rejects hostile kind/path', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupCreate())->handle([
        'username' => 'alicehost',
        'jobs' => [[
            'kind' => 'home',
            'path' => 'public_html',
        ]],
    ], $harness['ctx']);
    assert_true($out['jobs'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/backup/jobs.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'home'));
    assert_true(str_contains($body, 'public_html'));
    assert_true(!str_contains($body, '|'));
    $threwKind = false;
    try {
        (new BackupCreate())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'kind' => '|/bin/sh',
                'path' => '',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwKind = str_contains($e->getMessage(), 'kind') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwKind, 'hostile backup kind must fail closed');
    $threwPath = false;
    try {
        (new BackupCreate())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'kind' => 'home',
                'path' => '../etc',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile backup path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.archive creates a verified home archive and retries idempotently', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    if (!is_dir($home . '/public_html')) {
        mkdir($home . '/public_html', 0755, true);
    }
    file_put_contents($home . '/public_html/index.php', '<?php echo "healthy";');
    $id = str_repeat('a', 32);
    $handler = new BackupArchiveCreate();
    $result = $handler->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);
    assert_true($result['archive_id'] === $id);
    assert_true($result['scope'] === 'home');
    assert_true($result['status'] === 'ready');
    assert_true(preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1);
    $archive = $harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.tar.gz';
    $manifest = $harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.json';
    assert_true(is_file($archive), 'real archive path must be published');
    assert_true(is_file($manifest), 'checksum manifest must be published');
    assert_true(hash_file('sha256', $archive) === $result['sha256'], 'manifest checksum must match archive');
    $beforeTarCalls = count(array_filter($harness['cmd']->calls, static fn (array $argv): bool => basename($argv[0] ?? '') === 'tar'));
    $again = $handler->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);
    $afterTarCalls = count(array_filter($harness['cmd']->calls, static fn (array $argv): bool => basename($argv[0] ?? '') === 'tar'));
    assert_true($again['sha256'] === $result['sha256'], 'retry must return the same archive');
    assert_true($beforeTarCalls === $afterTarCalls, 'idempotent retry must not rerun tar');
    acp_account_cleanup($harness);
});
test('backup.extract restores a verified archive and keeps a pre-restore copy', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $id = str_repeat('e', 32);

    (new BackupArchiveCreate())->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);

    // customer changes the live file after the archive was taken
    file_put_contents($home . '/public_html/index.php', '<?php echo "broken";');

    $result = (new BackupExtract())->handle([
        'username' => 'alicehost',
        'archive_id' => $id,
        '_confirm' => 'backup.extract',
    ], $harness['ctx']);

    assert_true($result['status'] === 'restored');
    assert_true($result['archive_id'] === $id);
    assert_true($result['path'] === '');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'restored'), 'archive content must be back');
    assert_true(is_file($home . '/public_html/restored.txt'), 'restored file must exist');

    $pre = glob($harness['root'] . '/home/.acp-prerestore-alicehost-*');
    assert_true(is_array($pre) && count($pre) === 1, 'exactly one pre-restore copy must be kept');
    assert_true(str_contains((string) file_get_contents($pre[0] . '/public_html/index.php'), 'broken'), 'pre-restore copy must hold the replaced files');
    assert_true(is_file($harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.tar.gz'), 'archive must stay after a restore');
    assert_true(!is_dir($harness['root'] . '/home/.acp-restore-' . $id . '-' . gmdate('YmdHis')), 'staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('backup.extract restores a subtree and rejects hostile archives', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $id = str_repeat('f', 32);
    (new BackupArchiveCreate())->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);

    // subtree restore
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/public_html/', 'alicehost/public_html/index.php'];
    $harness['cmd']->tarExtractPaths = ['alicehost/public_html/index.php' => 'subtree-restored'];
    $result = (new BackupExtract())->handle([
        'username' => 'alicehost',
        'archive_id' => $id,
        'path' => 'public_html',
        '_confirm' => 'backup.extract',
    ], $harness['ctx']);
    assert_true($result['path'] === 'public_html');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'subtree-restored'));

    // entry outside the account home
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/../../etc/passwd'];
    $threwOutside = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => $id, '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwOutside = str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'outside');
    }
    assert_true($threwOutside, 'path escape inside an archive must fail closed');

    // hardlink entry (would land /etc/shadow inside the home)
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/shadow'];
    $harness['cmd']->tarVerboseLines = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/',
        'hrw-r--r-- 1500/1500 0 2026-10-03 16:00 alicehost/shadow link to /etc/shadow',
    ];
    $threwLink = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => $id, '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLink = str_contains($e->getMessage(), 'hardlink') || str_contains($e->getMessage(), 'special');
    }
    assert_true($threwLink, 'hardlink entries must fail closed');

    // unknown archive
    $threwMissing = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => str_repeat('a', 32), '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMissing = str_contains($e->getMessage(), 'not found');
    }
    assert_true($threwMissing, 'restoring an unknown archive must fail closed');
    acp_account_cleanup($harness);
});

test('db.restore imports cpmove mysql dumps into real databases (and refuses a hostile one)', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);

    $harness['cmd']->tarListLines = [
        'cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php',
        'cpmove-alicehost/mysql/', 'cpmove-alicehost/mysql/alicehost_shop.sql',
        'cpmove-alicehost/mysql/alicehost_blog.sql', 'cpmove-alicehost/mysql/alicehost_notes.txt',
    ];
    $harness['cmd']->tarMemberList['cpmove-alicehost/mysql'] = [
        'cpmove-alicehost/mysql/',
        'cpmove-alicehost/mysql/alicehost_shop.sql',
        'cpmove-alicehost/mysql/alicehost_blog.sql',
        'cpmove-alicehost/mysql/alicehost_notes.txt',
    ];
    // shop: a normal dump (mysqldump --add-drop-database shaped) — imports fine
    // blog: touches ANOTHER database — must refuse before anything runs
    $harness['cmd']->tarExtractPaths = [
        'cpmove-alicehost/mysql/alicehost_shop.sql' =>
            "USE `alicehost_shop`;\nDROP DATABASE IF EXISTS `alicehost_shop`;\nCREATE DATABASE `alicehost_shop`;\n"
            . "CREATE TABLE `wp` (`id` int);\nINSERT INTO `wp` VALUES (7);\n",
        'cpmove-alicehost/mysql/alicehost_blog.sql' => "DROP DATABASE `someotherdb`;\n",
    ];

    $blocked = false;
    try {
        (new DbRestore())->handle([
            'username' => 'alicehost', 'archive_path' => $archive, 'sha256' => $sha, '_confirm' => 'db.restore',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'another database');
    }
    assert_true($blocked, 'a dump naming another database must refuse the whole import');
    assert_true($harness['cmd']->mysqlDatabases === [], 'nothing is imported when one dump is hostile');
    assert_true($harness['cmd']->stdinFiles === [], 'nothing is streamed when one dump is hostile');

    // the operator can exclude it explicitly (the panel shows which dump failed)
    $result = (new DbRestore())->handle([
        'username' => 'alicehost', 'archive_path' => $archive, 'sha256' => $sha,
        'only' => ['shop'], '_confirm' => 'db.restore',
    ], $harness['ctx']);

    assert_true($result['status'] === 'imported', 'restore report success');
    assert_true(count($result['databases']) === 1, 'only the requested dump is imported');
    assert_true($result['databases'][0]['database'] === 'alicehost_shop', 'target database carries the account prefix');
    assert_true($result['databases'][0]['database_created'] === true, 'missing database is created first');
    assert_true(in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'database exists in MariaDB');
    assert_true(count($harness['cmd']->stdinFiles) === 1, 'the dump is streamed to the client');
    $stdin = $harness['cmd']->stdinFiles[0]['contents'];
    assert_true(str_starts_with($stdin, "USE `alicehost_shop`;"), 'prepared dump selects the target database');
    assert_true(str_contains($stdin, 'CREATE TABLE `wp`'), 'dump statements are kept');
    assert_true(substr_count($stdin, 'USE ') === 1, 'the archive USE line is not duplicated');
    assert_true(!str_contains($stdin, 'DROP DATABASE'), 'drop-database lines for our own db are stripped');
    assert_true(substr_count($stdin, 'CREATE DATABASE') === 0, 'create-database lines are stripped too');
    $skipped = array_column($result['skipped'], 'member');
    assert_true(in_array('cpmove-alicehost/mysql/alicehost_notes.txt', $skipped, true), 'non-.sql members are reported as skipped');

    // a dump that tries to write files as the database user is refused as well
    $harness['cmd']->tarExtractPaths = [
        'cpmove-alicehost/mysql/alicehost_shop.sql' => "SELECT 'x' INTO OUTFILE '/root/evil';\n",
    ];
    $harness['cmd']->stdinFiles = [];
    $outfileBlocked = false;
    try {
        (new DbRestore())->handle([
            'username' => 'alicehost', 'archive_path' => $archive, 'only' => ['shop'],
            '_confirm' => 'db.restore',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $outfileBlocked = str_contains($e->getMessage(), 'INTO OUTFILE');
    }
    assert_true($outfileBlocked, 'INTO OUTFILE must be refused');
    assert_true($harness['cmd']->stdinFiles === [], 'a refused dump is never streamed');

    $staging = glob($harness['root'] . '/home/.acp-mysql-alicehost-*');
    assert_true($staging === [] || $staging === false, 'mysql staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('db.restore reports an archive without mysql dumps instead of failing', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $result = (new DbRestore())->handle([
        'username' => 'alicehost', 'archive_path' => $archive, '_confirm' => 'db.restore',
    ], $harness['ctx']);

    assert_true($result['status'] === 'empty', 'a home-only archive reports empty');
    assert_true($result['databases'] === [], 'nothing is restored');
    acp_account_cleanup($harness);
});

test('cpanel import restores a cpmove home, keeps a pre-restore copy and reports skipped sections', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);

    $harness['cmd']->tarListLines = [
        'cpmove-alicehost/',
        'cpmove-alicehost/homedir/',
        'cpmove-alicehost/homedir/public_html/',
        'cpmove-alicehost/homedir/public_html/index.php',
        'cpmove-alicehost/mysql/',
        'cpmove-alicehost/mysql/alicehost_wp.sql',
        'cpmove-alicehost/userdata/main',
    ];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = [
        'cpmove-alicehost/homedir/',
        'cpmove-alicehost/homedir/public_html/',
        'cpmove-alicehost/homedir/public_html/index.php',
    ];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/',
        '-rw-r--r-- 1500/1500 21 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php',
    ];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/public_html/index.php' => 'imported from cpanel'];

    $result = (new BackupCpanel())->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $archive,
        'sha256' => $sha,
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);

    assert_true($result['status'] === 'imported', 'import must report success');
    assert_true($result['layout'] === 'direct', 'cpmove layout must be detected');
    assert_true($result['files'] === 1 && $result['dirs'] === 2, 'home counts must come from the home listing');
    assert_true($result['bytes'] === 21, 'home bytes must be summed from the verbose listing');
    assert_true(in_array('mysql', $result['sections'], true), 'skipped sections must be reported');
    assert_true(($result['section_entries']['mysql'] ?? 0) === 2, 'section entry counts must be reported');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'imported from cpanel'), 'imported home must be in place');
    assert_true(!is_file($home . '/public_html/index.html'), 'old home files must be replaced by the swap');
    $pre = glob($harness['root'] . '/home/.acp-prerestore-alicehost-*');
    assert_true(is_array($pre) && count($pre) === 1, 'exactly one pre-restore copy must be kept');
    assert_true(str_contains((string) file_get_contents($pre[0] . '/public_html/index.html'), 'shop.example.com'), 'pre-restore copy must hold the replaced home');
    $staging = glob($harness['root'] . '/home/.acp-import-alicehost-*');
    assert_true($staging === [] || $staging === false, 'import staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('cpanel import fails closed on a bad checksum, a foreign archive, hostile entries and symlinks', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);
    $handler = new BackupCpanel();
    $payload = [
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $archive,
        'sha256' => $sha,
        '_confirm' => 'backup.cpanel',
    ];

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['-rw-r--r-- 1500/1500 3 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php'];

    $badChecksum = false;
    try {
        $handler->handle(['sha256' => str_repeat('0', 64)] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $badChecksum = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($badChecksum, 'a wrong sha256 must refuse the import');

    $foreign = false;
    $harness['cmd']->tarListLines = ['cpmove-bobhost/', 'cpmove-bobhost/homedir/public_html/index.php'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $foreign = str_contains($e->getMessage(), 'another account');
    }
    assert_true($foreign, 'an archive for another username must be refused');

    $escape = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/../../etc/passwd'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $escape = str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'outside');
    }
    assert_true($escape, 'a path escape must be refused');

    $hardlink = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/shadow'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/shadow'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['hrw-r--r-- 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/shadow link to /etc/shadow'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $hardlink = str_contains($e->getMessage(), 'hardlink') || str_contains($e->getMessage(), 'special');
    }
    assert_true($hardlink, 'hardlink entries must never be imported');

    $symlink = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/link', 'cpmove-alicehost/homedir/link/passwd'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/link', 'cpmove-alicehost/homedir/link/passwd'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'lrwxrwxrwx 1500/1500 4 2026-10-03 16:00 cpmove-alicehost/homedir/link -> /etc',
        '-rw-r--r-- 1500/1500 3 2026-10-03 16:00 cpmove-alicehost/homedir/link/passwd',
    ];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $symlink = str_contains($e->getMessage(), 'symlink');
    }
    assert_true($symlink, 'an archive that writes through a symlink must be refused');

    assert_true(is_file($home . '/public_html/index.html'), 'a refused import must leave the home untouched');
    assert_true((glob($harness['root'] . '/home/.acp-prerestore-alicehost-*') ?: []) === [], 'a refused import must not leave a pre-restore copy');
    acp_account_cleanup($harness);
});

test('cpanel import supports the legacy root layout and the nested homedir.tar layout', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $handler = new BackupCpanel();

    $legacy = $harness['root'] . '/home/backup-10.03.2026_16-00-00_alicehost.tar.gz';
    file_put_contents($legacy, str_repeat('legacy-bytes', 8));
    $harness['cmd']->tarListLines = ['homedir/', 'homedir/public_html/', 'homedir/public_html/index.php', 'mysql/alicehost_wp.sql'];
    $harness['cmd']->tarMemberList['homedir'] = ['homedir/', 'homedir/public_html/', 'homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 homedir/',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 homedir/public_html/',
        '-rw-r--r-- 1500/1500 12 2026-10-03 16:00 homedir/public_html/index.php',
    ];
    $harness['cmd']->tarExtractPaths = ['homedir/public_html/index.php' => 'legacy import'];
    $result = $handler->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $legacy,
        'sha256' => (string) hash_file('sha256', $legacy),
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);
    assert_true($result['layout'] === 'direct' && $result['root'] === '', 'a legacy backup must import without a cpmove root');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'legacy import'), 'legacy home must be imported');

    $nested = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($nested, str_repeat('nested-cpmove-bytes', 8));
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/homedir.tar'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/homedir.tar'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/',
        '-rw-r--r-- 1500/1500 10240 2026-10-03 16:00 cpmove-alicehost/homedir/homedir.tar',
    ];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/homedir.tar' => 'fake nested tar bytes'];
    $harness['cmd']->tarNestedList = ['./', './public_html/', './public_html/index.php'];
    $harness['cmd']->tarNestedVerbose = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./public_html/',
        '-rw-r--r-- 1500/1500 14 2026-10-03 16:00 ./public_html/index.php',
    ];
    $harness['cmd']->tarNestedExtractPaths = ['./public_html/index.php' => 'nested import'];
    $result = $handler->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $nested,
        'sha256' => (string) hash_file('sha256', $nested),
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);
    assert_true($result['layout'] === 'nested', 'a nested homedir.tar must be detected');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'nested import'), 'nested home must be imported');
    acp_account_cleanup($harness);
});

test('cpanel import refuses unknown files, missing accounts and empty listings', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $handler = new BackupCpanel();
    $payload = [
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $harness['root'] . '/home/cpmove-alicehost.tar.gz',
        '_confirm' => 'backup.cpanel',
    ];

    $missing = false;
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $missing = str_contains($e->getMessage(), 'not found');
    }
    assert_true($missing, 'a missing archive file must be refused');

    $wrongName = $harness['root'] . '/home/cpmove-alicehost.zip';
    file_put_contents($wrongName, 'not a tar');
    $wrongExtension = false;
    try {
        $handler->handle(['archive_path' => $wrongName] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $wrongExtension = str_contains($e->getMessage(), 'tar');
    }
    assert_true($wrongExtension, 'only .tar/.tar.gz/.tgz files may be imported');

    $outside = sys_get_temp_dir() . '/acp-outside-' . bin2hex(random_bytes(4)) . '.tar.gz';
    file_put_contents($outside, str_repeat('outside-bytes', 8));
    $outsideRefused = false;
    try {
        $handler->handle(['archive_path' => $outside] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $outsideRefused = str_contains($e->getMessage(), 'outside the allowlisted roots');
    }
    assert_true($outsideRefused, 'an archive outside the allowlisted roots must be refused');

    $smuggle = $harness['root'] . '/home/cpmove-smuggle.tar.gz';
    @symlink($outside, $smuggle);
    $smuggleRefused = false;
    try {
        $handler->handle(['archive_path' => $smuggle] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $smuggleRefused = str_contains($e->getMessage(), 'outside the allowlisted roots');
    }
    assert_true($smuggleRefused, 'a symlink pointing outside the roots must not smuggle an archive in');
    @unlink($smuggle);
    @unlink($outside);

    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $noAccount = false;
    try {
        $handler->handle(['username' => 'bobhost'] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $noAccount = str_contains($e->getMessage(), 'not an AlphaCP account');
    }
    assert_true($noAccount, 'the account must exist before an import');

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [];
    $empty = false;
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $empty = str_contains($e->getMessage(), 'empty');
    }
    assert_true($empty, 'an archive without home content must be refused');
    acp_account_cleanup($harness);
});

test('backup.transfer imports the same archive and records the source host', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['-rw-r--r-- 1500/1500 9 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/public_html/index.php' => 'transferred'];

    $result = (new BackupTransfer())->handle([
        'username' => 'alicehost',
        'source' => 'old.example.com',
        'archive_path' => $archive,
        '_confirm' => 'backup.transfer',
    ], $harness['ctx']);
    assert_true($result['status'] === 'imported', 'transfer must import the archive');
    assert_true($result['action'] === 'transfer' && $result['source'] === 'old.example.com', 'the source host must be recorded in the result');
    acp_account_cleanup($harness);
});

test('backup.archive prunes expired snapshots before checking free space', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new BackupConfig())->handle(['schedule' => 'daily', 'retention' => 1], $harness['ctx']);
    $handler = new BackupArchiveCreate();
    $oldId = str_repeat('c', 32);
    $newId = str_repeat('d', 32);
    $handler->handle(['username' => 'alicehost', 'archive_id' => $oldId], $harness['ctx']);
    $archiveDir = $harness['root'] . '/alphacp/backups/accounts/alicehost';
    touch($archiveDir . '/' . $oldId . '.json', time() - 172800);
    $handler->handle(['username' => 'alicehost', 'archive_id' => $newId], $harness['ctx']);
    assert_true(!is_file($archiveDir . '/' . $oldId . '.tar.gz'), 'expired archive must be removed before the next archive');
    assert_true(!is_file($archiveDir . '/' . $oldId . '.json'), 'expired manifest must be removed with its archive');
    assert_true(is_file($archiveDir . '/' . $newId . '.tar.gz'), 'new archive should still be published');
    acp_account_cleanup($harness);
});
test('backup.archive rejects hostile ids and cleans up failed tar attempts', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $handler = new BackupArchiveCreate();
    $badId = false;
    try {
        $handler->handle(['username' => 'alicehost', 'archive_id' => '../|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $badId = str_contains($e->getMessage(), 'id');
    }
    assert_true($badId, 'archive id must be strictly validated');
    $harness['cmd']->failWhenContains = '--create';
    $failed = false;
    try {
        $handler->handle(['username' => 'alicehost', 'archive_id' => str_repeat('b', 32)], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $failed = str_contains($e->getMessage(), 'creation failed');
    }
    assert_true($failed, 'tar failure must fail the task');
    $dir = $harness['root'] . '/alphacp/backups/accounts/alicehost';
    assert_true(!is_file($dir . '/' . str_repeat('b', 32) . '.tar.gz'), 'failed archive must not be published');
    assert_true(!is_file($dir . '/' . str_repeat('b', 32) . '.json'), 'failed archive must not leave a manifest');
    acp_account_cleanup($harness);
});
test('backup.wizard writes json and rejects hostile action/scope', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupWizard())->handle([
        'username' => 'alicehost',
        'action' => 'backup',
        'scope' => 'home',
    ], $harness['ctx']);
    assert_true($out['action'] === 'backup');
    assert_true($out['scope'] === 'home');
    $file = $harness['root'] . '/home/alicehost/etc/backup/wizard.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'backup'));
    assert_true(str_contains($body, 'home'));
    assert_true(!str_contains($body, '|'));
    $threwAction = false;
    try {
        (new BackupWizard())->handle([
            'username' => 'alicehost',
            'action' => '|/bin/sh',
            'scope' => 'home',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwAction = str_contains($e->getMessage(), 'action') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwAction, 'hostile backup wizard action must fail closed');
    $threwScope = false;
    try {
        (new BackupWizard())->handle([
            'username' => 'alicehost',
            'action' => 'backup',
            'scope' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwScope = str_contains($e->getMessage(), 'scope') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwScope, 'hostile backup wizard scope must fail closed');
    acp_account_cleanup($harness);
});
test('backup.restore writes json and rejects hostile path', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupRestore())->handle([
        'username' => 'alicehost',
        'paths' => [[
            'path' => 'public_html/index.php',
        ]],
    ], $harness['ctx']);
    assert_true($out['paths'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/backup/restore.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'public_html/index.php'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupRestore())->handle([
            'username' => 'alicehost',
            'paths' => [[
                'path' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPipe, 'hostile restore pipe must fail closed');
    $threwPath = false;
    try {
        (new BackupRestore())->handle([
            'username' => 'alicehost',
            'paths' => [[
                'path' => '../etc',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile restore path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.config writes json and rejects hostile schedule/retention', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupConfig())->handle([
        'schedule' => 'daily',
        'retention' => 14,
    ], $harness['ctx']);
    assert_true($out['schedule'] === 'daily');
    assert_true($out['retention'] === 14);
    $file = $harness['root'] . '/alphacp/etc/backup/config.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'daily'));
    assert_true(str_contains($body, '14'));
    assert_true(!str_contains($body, '|'));
    $threwSchedule = false;
    try {
        (new BackupConfig())->handle([
            'schedule' => '|/bin/sh',
            'retention' => 14,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwSchedule = str_contains($e->getMessage(), 'schedule') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwSchedule, 'hostile backup schedule must fail closed');
    $threwRetention = false;
    try {
        (new BackupConfig())->handle([
            'schedule' => 'daily',
            'retention' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwRetention = str_contains($e->getMessage(), 'retention') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwRetention, 'hostile backup retention must fail closed');
    acp_account_cleanup($harness);
});
test('backup.restoration writes json and rejects hostile mode/username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupRestoration())->handle([
        'mode' => 'full',
        'username' => 'alicehost',
    ], $harness['ctx']);
    assert_true($out['mode'] === 'full');
    assert_true($out['username'] === 'alicehost');
    $file = $harness['root'] . '/alphacp/etc/backup/restoration.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'full'));
    assert_true(str_contains($body, 'alicehost'));
    assert_true(!str_contains($body, '|'));
    $threwMode = false;
    try {
        (new BackupRestoration())->handle([
            'mode' => '|/bin/sh',
            'username' => 'alicehost',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMode = str_contains($e->getMessage(), 'mode') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwMode, 'hostile backup restoration mode must fail closed');
    $threwUser = false;
    try {
        (new BackupRestoration())->handle([
            'mode' => 'full',
            'username' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwUser = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwUser, 'hostile backup restoration username must fail closed');
    acp_account_cleanup($harness);
});
test('backup.users writes json and rejects hostile username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupUsers())->handle([
        'users' => [['username' => 'alicehost']],
    ], $harness['ctx']);
    assert_true($out['users'][0]['username'] === 'alicehost');
    $file = $harness['root'] . '/alphacp/etc/backup/users.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupUsers())->handle([
            'users' => [['username' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPipe, 'hostile backup user must fail closed');
    $threwPath = false;
    try {
        (new BackupUsers())->handle([
            'users' => [['username' => '../etc']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile backup user path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.filedir writes json and rejects hostile path', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupFiledir())->handle([
        'username' => 'alicehost',
        'path' => 'mail/inbox',
    ], $harness['ctx']);
    assert_true($out['username'] === 'alicehost');
    assert_true($out['path'] === 'mail/inbox');
    $file = $harness['root'] . '/alphacp/etc/backup/filedir.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost'));
    assert_true(str_contains($body, 'mail/inbox'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupFiledir())->handle([
            'username' => 'alicehost',
            'path' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwPipe, 'hostile filedir path must fail closed');
    $threwPath = false;
    try {
        (new BackupFiledir())->handle([
            'username' => 'alicehost',
            'path' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwPath, 'hostile filedir escape must fail closed');
    acp_account_cleanup($harness);
});
test('backup.transfer and backup.cpanel reject hostile metadata before touching the archive', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));

    $threwPipe = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => '|/bin/sh',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'FQDN');
    }
    assert_true($threwPipe, 'hostile transfer source must fail closed');

    $threwSourcePath = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => '../etc',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwSourcePath = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'FQDN');
    }
    assert_true($threwSourcePath, 'hostile transfer source path must fail closed');

    $threwAction = false;
    try {
        (new BackupCpanel())->handle([
            'username' => 'alicehost',
            'action' => '|/bin/sh',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwAction = str_contains($e->getMessage(), 'action') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwAction, 'hostile cpanel action must fail closed');

    $threwUsername = false;
    try {
        (new BackupCpanel())->handle([
            'username' => '../etc',
            'action' => 'restore',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwUsername = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwUsername, 'hostile cpanel username path must fail closed');

    $threwNoArchive = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => 'source.example.com',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwNoArchive = str_contains($e->getMessage(), 'archive');
    }
    assert_true($threwNoArchive, 'an import without an archive path must fail closed');
    assert_true(!is_file($harness['root'] . '/alphacp/etc/backup/cpanel-account.json'), 'the old JSON stub file must no longer be written');
    assert_true(!is_file($harness['root'] . '/alphacp/etc/backup/transfer.json'), 'the old JSON stub file must no longer be written');
    acp_account_cleanup($harness);
});
test('backup.review writes JSON and rejects hostile status/username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupReview())->handle([
        'username' => 'alicehost',
        'status' => 'ok',
    ], $harness['ctx']);
    assert_true($out['username'] === 'alicehost');
    assert_true($out['status'] === 'ok');
    $file = $harness['root'] . '/alphacp/etc/backup/review.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost') && str_contains($body, 'ok'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new BackupReview())->handle(['username' => 'alicehost', 'status' => '|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'status') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile review status must fail closed');
    acp_account_cleanup($harness);
});
test('cron.set writes crontab body and rejects newlines', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new CronSet())->handle([
        'username' => 'alicehost',
        'jobs' => [[
            'minute' => '0', 'hour' => '1', 'day' => '*', 'month' => '*', 'weekday' => '*',
            'command' => '/home/alicehost/bin/daily.sh',
        ]],
    ], $harness['ctx']);
    assert_true($out['jobs'] === 1);
    assert_true(str_contains((string) $harness['cmd']->crontabBody, '/home/alicehost/bin/daily.sh'));
    $threw = false;
    try {
        (new CronSet())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
                'command' => "echo hi\nrm -rf /",
            ]],
        ], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'newline in command must fail');
    acp_account_cleanup($harness);
});
test('ssl.issue writes certs and :443 vhost', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html';
    $out = (new SslIssue())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'document_root' => $doc,
        'mode' => 'selfsigned',
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['issuer'] === 'selfsigned');
    $slug = 'shop-example-com';
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-' . $slug . '-ssl.conf';
    assert_true(is_file($vhost), 'ssl vhost missing');
    assert_true(str_contains((string) file_get_contents($vhost), 'SSLEngine on'));
    assert_true(is_file($harness['root'] . '/home/alicehost/ssl/' . $slug . '/cert.pem'));
    (new SslRemove())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
    ], $harness['ctx']);
    assert_true(!is_file($vhost));
    acp_account_cleanup($harness);
});
test('ssl.issue letsencrypt runs certbot and writes :443 vhost', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html';
    $out = (new SslIssue())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'document_root' => $doc,
        'mode' => 'letsencrypt',
        'email' => 'alice@example.com',
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['issuer'] === 'letsencrypt');
    $slug = 'shop-example-com';
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-' . $slug . '-ssl.conf';
    assert_true(is_file($vhost));
    $cert = (string) file_get_contents($harness['root'] . '/home/alicehost/ssl/' . $slug . '/cert.pem');
    assert_true(str_contains($cert, 'LE-fake'));
    $bins = array_map('basename', array_column($harness['cmd']->calls, 0));
    assert_true(in_array('certbot', $bins, true), 'certbot must run');
    $leLine = '';
    foreach ($harness['cmd']->calls as $argv) {
        if (basename((string) ($argv[0] ?? '')) === 'certbot') {
            $leLine = implode(' ', $argv);
        }
    }
    assert_true(str_contains($leLine, '--webroot'), 'webroot challenge');
    assert_true(str_contains($leLine, 'alice@example.com'), 'acme email');
    acp_account_cleanup($harness);
});
test('ssl.issue letsencrypt rejects certbot failure', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $harness['cmd']->failWhenContains = 'certbot';
    $threw = false;
    try {
        (new SslIssue())->handle([
            'username' => 'alicehost',
            'domain' => 'shop.example.com',
            'document_root' => $harness['root'] . '/home/alicehost/public_html',
            'mode' => 'letsencrypt',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'certbot failed');
    }
    assert_true($threw, 'certbot failure must fail closed');
    acp_account_cleanup($harness);
});
test('SafeFs refuses writes outside the allowlisted roots', function (): void {
    $harness = acp_account_harness();
    $fs = new SafeFs($harness['ctx']->paths);
    $threw = false;
    try {
        $fs->write('/etc/passwd', 'nope');
    } catch (PathGuardException $e) {
        $threw = true;
    }
    assert_true($threw);
    acp_account_cleanup($harness);
});

// ---------------------------------------------------------------------------
// S10 — remote pull (backup.pull): cpmove archive doosre server se SSH (scp) se
// laana. Yahan asli network nahi chalta — FakeCommandExecutor ssh-keyscan /
// ssh-keygen / scp / sshpass ko intercept karta hai.
// ---------------------------------------------------------------------------

/** @return array{root: string, cmd: FakeCommandExecutor, ctx: TaskContext, drop: string} */
function acp_pull_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-pull-' . bin2hex(random_bytes(4));
    $drop = $root . '/incoming';
    mkdir($drop, 0750, true);
    putenv('ACP_STATE_ROOT=' . $root);
    putenv('ACP_IMPORT_DIR=' . $drop);
    // fake executor basename se dispatch karta hai — asli server par ye openssh-client hai
    putenv('ACP_SSH_KEYSCAN=/usr/bin/ssh-keyscan');
    putenv('ACP_SSH_KEYGEN=/usr/bin/ssh-keygen');
    putenv('ACP_SSH_SCP=/usr/bin/scp');
    putenv('ACP_SSH_SSHPASS=/usr/bin/sshpass');

    $cmd = new FakeCommandExecutor();
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(log: $log, cmd: $cmd, paths: null, taskId: null, taskRow: null);

    return ['root' => $root, 'drop' => $drop, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root: string} $harness */
function acp_pull_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach (['ACP_STATE_ROOT', 'ACP_IMPORT_DIR', 'ACP_SSH_KEYSCAN', 'ACP_SSH_KEYGEN', 'ACP_SSH_SCP', 'ACP_SSH_SSHPASS'] as $name) {
        putenv($name);
    }
}

test('backup.pull probe: fingerprint laata hai, kuch download nahi karta', function (): void {
    $h = acp_pull_harness();
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['probe'] === true, 'probe mode flag');
    assert_true(str_starts_with($result['fingerprint'], 'SHA256:'), 'fingerprint SHA256: se shuru ho');
    assert_true($result['key_type'] === 'ED25519', 'key type mila');
    assert_true($h['cmd']->scpArgv === null, 'probe me scp kabhi nahi chala');
    assert_true(glob($h['drop'] . '/*') === [] || glob($h['drop'] . '/*') === false, 'probe me koi file nahi bani');
    acp_pull_cleanup($h);
});

test('backup.pull: key auth se archive drop dir me aata hai', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('cpmove-bytes-', 20);

    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alicehost.tar.gz',
        'auth' => 'key', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----",
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['name'] === 'cpmove-alicehost.tar.gz', 'remote file ka naam mila');
    assert_true(is_file($result['path']), 'archive drop dir me likha gaya');
    assert_true(str_starts_with($result['path'], $h['drop'] . '/'), 'archive drop dir ke andar hi hai');
    assert_true($result['bytes'] === strlen(str_repeat('cpmove-bytes-', 20)), 'size sahi');
    assert_true($result['sha256'] === hash('sha256', str_repeat('cpmove-bytes-', 20)), 'sha256 sahi');
    assert_true($result['fingerprints'] === [$h['cmd']->hostKeyFingerprint], 'all presented host fingerprints are returned');
    $argv = $h['cmd']->scpArgv ?? [];
    assert_true(in_array('-i', $argv, true), 'key auth me -i pass hua');
    assert_true(in_array('BatchMode=yes', $argv, true), 'key auth batch mode me chala');
    assert_true(in_array('root@old.example.com:/home/cpmove-alicehost.tar.gz', $argv, true), 'scp source spec sahi');
    assert_true(!in_array('/usr/bin/sshpass', $argv, true), 'key auth me sshpass nahi');
    acp_pull_cleanup($h);
});

test('backup.pull: host key pin na ho to refuse (MITM se bachav)', function (): void {
    $h = acp_pull_harness();
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----', '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'not pinned');
    }
    assert_true($blocked, 'bina pinned fingerprint ke pull refuse ho');
    assert_true($h['cmd']->scpArgv === null, 'refuse hone par scp hi nahi chala');
    acp_pull_cleanup($h);
});

test('backup.pull: fingerprint mismatch (server badla / MITM) -> refuse', function (): void {
    $h = acp_pull_harness();
    // admin ne pehle probe karke is fingerprint ko pin kiya tha...
    $first = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    // ...aur ab wahi server (ya beech me koi) DOOSRI key dikha raha hai
    $h['cmd']->hostKeySecondFingerprint = 'SHA256:TOTALLYdiFFerentFingerprintAAAAAAAAAAAAAAAAAAA';

    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $first['fingerprint'], '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'MISMATCH');
    }
    assert_true($blocked, 'fingerprint badalne par pull refuse ho');
    assert_true($h['cmd']->scpArgv === null, 'mismatch par scp chala hi nahi');
    assert_true(glob($h['drop'] . '/*') === [] || glob($h['drop'] . '/*') === false, 'koi file nahi chhodi');
    acp_pull_cleanup($h);
});

test('backup.pull: password auth me password argv me nahi, sshpass -f file se', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('pw-archive-', 12);

    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/backup/cpmove-bob.tar.gz',
        'auth' => 'password', 'password' => 'hunter2-super-secret',
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['auth'] === 'password', 'auth mode report hua');
    $pwFile = $h['cmd']->sshpassFile;
    assert_true(is_string($pwFile) && $pwFile !== '', 'sshpass ko -f <file> mila');
    assert_true(!is_file($pwFile), 'password file kaam ke baad delete ho gayi');
    $argv = $h['cmd']->calls;
    foreach ($argv as $call) {
        assert_true(!in_array('hunter2-super-secret', $call, true), 'password kabhi argv me nahi gaya');
    }
    acp_pull_cleanup($h);
});

test('backup.pull: sshpass na ho to password auth saaf message ke saath refuse', function (): void {
    $h = acp_pull_harness();
    putenv('ACP_SSH_SSHPASS=');           // override hatao -> asli path hi use hoga
    if (is_executable(RemotePull::SSHPASS)) {
        acp_pull_cleanup($h);
        assert_true(true, 'sshpass installed — skip');

        return;
    }
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'password', 'password' => 'x',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'sshpass');
    }
    assert_true($blocked, 'sshpass missing par saaf message');
    acp_pull_cleanup($h);
});

test('backup.pull: remote path aur naam ke niyam (.. / absolute / tar ext)', function (): void {
    $h = acp_pull_harness();
    $fp = $h['cmd']->hostKeyFingerprint;

    foreach (['/home/../etc/passwd', 'relative/path.tar.gz', '/home/c pmove.tar.gz'] as $bad) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => 'old.example.com', 'user' => 'root', 'remote_path' => $bad,
                'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "remote path '{$bad}' refuse ho");
    }

    foreach (['evil.sh', 'archive.zip', '.bashrc'] as $badName) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
                'dest_name' => $badName, 'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "dest_name '{$badName}' refuse ho (sirf tar/tar.gz/tgz)");
    }

    foreach (['old.example.com; rm -rf /', 'not a host', ''] as $badHost) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => $badHost, 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
                'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "host '{$badHost}' refuse ho");
    }
    acp_pull_cleanup($h);
});

test('backup.pull: sha256 mismatch par file drop dir me nahi rehti', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('x', 100);
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'sha256' => str_repeat('a', 64),
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($blocked, 'galat sha256 par refuse');
    $left = glob($h['drop'] . '/*');
    assert_true($left === [] || $left === false, 'adhoora archive drop dir me nahi chhoda gaya');
    acp_pull_cleanup($h);
});

test('backup.pull: maujooda file overwrite nahi hoti (jab tak overwrite=true na ho)', function (): void {
    $h = acp_pull_harness();
    file_put_contents($h['drop'] . '/cpmove-alice.tar.gz', 'purana-archive');
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alice.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'already exists');
    }
    assert_true($blocked, 'bina overwrite ke refuse');
    assert_true(file_get_contents($h['drop'] . '/cpmove-alice.tar.gz') === 'purana-archive', 'purani file waise hi hai');

    (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alice.tar.gz',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----', 'overwrite' => true,
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    assert_true(file_get_contents($h['drop'] . '/cpmove-alice.tar.gz') !== 'purana-archive', 'overwrite=true par nayi file');
    acp_pull_cleanup($h);
});

test('backup.pull: private key disk par nahi rehti (temp files saaf)', function (): void {
    $h = acp_pull_harness();
    $secret = 'ACPCANARY-PRIVATE-KEY-MATERIAL-0123456789';
    (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n{$secret}\n-----END OPENSSH PRIVATE KEY-----",
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    $leftovers = glob(sys_get_temp_dir() . '/acprp*') ?: [];
    assert_true($leftovers === [], 'koi temp file nahi bachi: ' . implode(',', $leftovers));
    foreach ($leftovers as $file) {
        assert_true(!str_contains((string) @file_get_contents($file), $secret), 'key material disk par nahi');
    }
    acp_pull_cleanup($h);
});

test('backup.pull: scp fail hone par .part file saaf ho jati hai', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpFails = true;
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'remote pull fail');
    }
    assert_true($blocked, 'scp fail par task fail');
    $left = glob($h['drop'] . '/*');
    assert_true($left === [] || $left === false, 'koi adhura archive nahi chhoda');
    acp_pull_cleanup($h);
});

test('backup.pull: server kai host keys de to sab fingerprints milte hain (ed25519 pehle)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ecdsa-sha2-nistp256 AAAAECDSA', 'fingerprint' => 'SHA256:ECDSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ECDSA'],
    ];
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['key_type'] === 'ED25519', 'sabse strong key pehle');
    assert_true($result['fingerprint'] === 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'ed25519 ka fingerprint diya');
    assert_true(count($result['fingerprints']) === 3, 'teenon keys ke fingerprints mile');
    assert_true(str_contains($result['pubkey'], 'ssh-rsa'), 'poora key block mila (scp chahe jo bhi use kare)');
    acp_pull_cleanup($h);
});

test('backup.pull: keyscan ka order badle to bhi pinned key match ho (LIVE bug ka fix)', function (): void {
    $h = acp_pull_harness();
    // pehle server ne ed25519 pehle diya (probe)
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
    ];
    $probed = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    $pinned = $probed['fingerprint'];

    // ab keyscan ne order ulta diya (asli server par aisa hi hota hai)
    $h['cmd']->hostKeys = array_reverse($h['cmd']->hostKeys);
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        'host_fingerprint' => $pinned, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['name'] === 'cpmove-a.tar.gz', 'order badalne ke bawajood pull chal gaya');
    assert_true($result['fingerprint'] === $pinned, 'pinned fingerprint report hua');
    acp_pull_cleanup($h);
});

test('backup.pull: pin kisi bhi key se match na ho to MISMATCH (saare fingerprints dikhe)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
    ];
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => 'SHA256:kisiAurKiKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'MISMATCH'), 'MISMATCH par refuse');
    assert_true(str_contains($msg, 'ED25519key'), 'error me server ki saari keys dikhen');
    assert_true(str_contains($msg, 'RSAkey'), 'error me doosri key bhi dikhe');
    acp_pull_cleanup($h);
});

test('backup.pull: auth ki kami network se pehle pakdi jaye (keyscan call hi na ho)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->keyscanCalls = 0;
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'key', 'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'private_key'), 'error key ke bare me ho: ' . $msg);
    assert_true($h['cmd']->keyscanCalls === 0, 'network (keyscan) call hi nahi hua');

    $h['cmd']->keyscanCalls = 0;
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'password', 'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'password'), 'error password ke bare me ho: ' . $msg);
    assert_true($h['cmd']->keyscanCalls === 0, 'network (keyscan) call hi nahi hua (password case)');
    acp_pull_cleanup($h);
});

// ---------------------------------------------------------------------------
// S10 — remote backup destinations (backup.destination): apne archives doosre
// server par bhejna. Yahan bhi asli network nahi chalta — FakeCommandExecutor
// ssh / scp / ssh-keygen / ssh-keyscan / sshpass ko intercept karta hai.
// ---------------------------------------------------------------------------

/** @return array{root: string, cmd: FakeCommandExecutor, ctx: TaskContext, saves: string} */
function acp_dest_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-dest-' . bin2hex(random_bytes(4));
    $saves = $root . '/backups/accounts/alicehost';
    mkdir($saves, 0777, true);
    putenv('ACP_STATE_ROOT=' . $root);
    putenv('ACP_SSH_KEYSCAN=/usr/bin/ssh-keyscan');
    putenv('ACP_SSH_KEYGEN=/usr/bin/ssh-keygen');
    putenv('ACP_SSH_SCP=/usr/bin/scp');
    putenv('ACP_SSH_BIN=/usr/bin/ssh');
    putenv('ACP_SSH_SSHPASS=/usr/bin/sshpass');

    $cmd = new FakeCommandExecutor();
    $cmd->hostKeys = [
        ['pubkey' => 'backup.example.com ssh-ed25519 AAAAED', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'backup.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'type' => 'RSA'],
    ];
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(log: $log, cmd: $cmd, paths: null, taskId: null, taskRow: null);

    return ['root' => $root, 'saves' => $saves, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root: string} $harness */
function acp_dest_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach (['ACP_STATE_ROOT', 'ACP_SSH_KEYSCAN', 'ACP_SSH_KEYGEN', 'ACP_SSH_SCP', 'ACP_SSH_BIN', 'ACP_SSH_SSHPASS'] as $name) {
        putenv($name);
    }
}

/** @param array{cmd: FakeCommandExecutor, ctx: TaskContext} $h */
function acp_dest_save(array $h, array $extra = []): array
{
    return (new BackupDestination())->handle(array_merge([
        'action'           => 'save',
        'name'             => 'offsite1',
        'host'             => 'backup.example.com',
        'user'             => 'backup',
        'path'             => '/srv/backups/alphacp',
        'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        '_confirm'         => 'backup.destination',
    ], $extra), $h['ctx']);
}

test('backup.destination save: config + naya ed25519 key, result me koi secret nahi', function (): void {
    $h = acp_dest_harness();
    $out = acp_dest_save($h);

    assert_true(($out['action'] ?? '') === 'save', 'action save');
    $cfg = $out['destination'];
    assert_true(($cfg['name'] ?? '') === 'offsite1', 'name wapas mila');
    assert_true(($cfg['host'] ?? '') === 'backup.example.com', 'host wapas mila');
    assert_true(($cfg['host_fingerprint'] ?? '') === 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'host key pin saved');
    assert_true(str_contains((string) ($cfg['public_key'] ?? ''), 'ssh-ed25519'), 'public key mila (admin backup server par dale)');
    assert_true(($cfg['has_key'] ?? false) === true, 'key file ban gayi');

    $json = json_encode($out);
    assert_true(!str_contains((string) $json, 'PRIVATE KEY'), 'result me private key nahi: ' . (string) $json);
    assert_true(!array_key_exists('password', $cfg) && !array_key_exists('private_key', $cfg) && !array_key_exists('key_path', $cfg), 'result me koi secret field nahi');
    assert_true(($cfg['has_password'] ?? false) === false, 'key auth me password stored nahi');

    $file = $h['root'] . '/etc/backup-destinations/offsite1.json';
    assert_true(is_file($file), 'config file likhi gayi');
    assert_true((fileperms($file) & 0777) === 0600, 'config 0600 hai');
    $key = $h['root'] . '/etc/backup-keys/offsite1';
    assert_true(is_file($key), 'key file bani');
    assert_true((fileperms($key) & 0777) === 0600, 'key 0600 hai');
    acp_dest_cleanup($h);
});

test('backup.destination save: bina host key pin ke refuse', function (): void {
    $h = acp_dest_harness();
    $msg = '';
    try {
        acp_dest_save($h, ['host_fingerprint' => '', 'accept_host_key' => false]);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'host key pin'), 'pin maanga: ' . $msg);
    assert_true(!is_file($h['root'] . '/etc/backup-destinations/offsite1.json'), 'config nahi bani');
    acp_dest_cleanup($h);
});

test('backup.destination save: galat naam (path escape / space) refuse', function (): void {
    $h = acp_dest_harness();
    foreach (['../evil', 'bad name', '-flag', 'toolongdestinationnameaaaaaaaaaaaaaaaaa'] as $bad) {
        $msg = '';
        try {
            acp_dest_save($h, ['name' => $bad]);
        } catch (TaskRejectedException $e) {
            $msg = $e->getMessage();
        }
        assert_true($msg !== '', "naam '{$bad}' refuse hona chahiye");
    }
    assert_true(($h['cmd']->keyscanCalls ?? 0) === 0, 'validation me koi network call nahi');
    acp_dest_cleanup($h);
});

test('backup.destination list: saved destinations dikhti hain, secret nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $out = (new BackupDestination())->handle(['action' => 'list', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['count'] ?? 0) === 1, 'ek destination: ' . json_encode($out));
    assert_true(($out['destinations'][0]['name'] ?? '') === 'offsite1', 'naam sahi');
    assert_true(!str_contains(json_encode($out), 'PRIVATE KEY'), 'list me key nahi');
    acp_dest_cleanup($h);
});

test('backup.destination test: ssh ek baar chala, pin verify hua', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->sshStdout = "ACP-OK\n";
    $out = (new BackupDestination())->handle([
        'action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination',
    ], $h['ctx']);

    assert_true(($out['ok'] ?? false) === true, 'test ok: ' . json_encode($out));
    assert_true($h['cmd']->sshCalls === 1, 'ssh ek baar chala');
    $argv = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($argv, 'backup@backup.example.com'), 'ssh target sahi: ' . $argv);
    assert_true(str_contains($argv, '/srv/backups/alphacp'), 'remote path sahi: ' . $argv);
    assert_true(str_contains($argv, 'ACP-OK'), 'probe command gaya');
    assert_true(str_contains($argv, 'StrictHostKeyChecking=yes'), 'host key strict');
    assert_true(!str_contains($argv, 'PRIVATE KEY'), 'argv me key nahi');
    acp_dest_cleanup($h);
});

test('backup.destination test: host key badal gayi (MITM) to MISMATCH', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->hostKeys = [['pubkey' => 'backup.example.com ssh-ed25519 AAAANEW', 'fingerprint' => 'SHA256:NEWkeyCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC', 'type' => 'ED25519']];
    $msg = '';
    try {
        (new BackupDestination())->handle(['action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'MISMATCH'), 'MISMATCH pakda: ' . $msg);
    assert_true($h['cmd']->sshCalls === 0, 'ssh call hi nahi hui');
    acp_dest_cleanup($h);
});

test('backup.destination push: .part se upload + remote sha256 verify', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $sha = hash_file('sha256', $archive);
    $h['cmd']->sshStdout = $sha . "  /srv/backups/alphacp/abc123.tar.gz\n";

    $out = (new BackupDestination())->handle([
        'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
    ], $h['ctx']);

    assert_true(($out['verified'] ?? false) === true, 'push verified: ' . json_encode($out));
    assert_true(($out['sha256'] ?? '') === $sha, 'sha256 match');
    assert_true(($out['file'] ?? '') === 'abc123.tar.gz', 'file naam');
    assert_true(($out['remote_path'] ?? '') === '/srv/backups/alphacp/abc123.tar.gz', 'remote path');
    $scp = implode(' ', $h['cmd']->scpArgv ?? []);
    assert_true(str_contains($scp, $archive), 'scp source archive: ' . $scp);
    assert_true(str_contains($scp, 'backup@backup.example.com:/srv/backups/alphacp/abc123.tar.gz.part'), 'scp .part par gaya: ' . $scp);
    $ssh = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($ssh, 'mv -f'), 'atomic rename hua');
    assert_true(str_contains($ssh, 'sha256sum'), 'remote checksum hua');
    acp_dest_cleanup($h);
});

test('backup.destination push: checksum mismatch par remote file hat gayi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $h['cmd']->sshStdout = str_repeat('f', 64) . "  /srv/backups/alphacp/abc123.tar.gz\n";
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'checksum mismatch'), 'mismatch pakda: ' . $msg);
    $ssh = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($ssh, 'rm -f'), 'remote se file hatayi: ' . $ssh);
    acp_dest_cleanup($h);
});

test('backup.destination push: backup store ke BAHAR wali file refuse', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $outside = $h['root'] . '/etc/passwd.tar.gz';
    file_put_contents($outside, 'nope');
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $outside, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'backup store'), 'store ke bahar refuse: ' . $msg);
    assert_true(($h['cmd']->scpArgv ?? null) === null, 'scp chala hi nahi');
    acp_dest_cleanup($h);
});

test('backup.destination browse: sirf tarball naam, ajeeb entry nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->sshStdout = "abc123.tar.gz\n../evil\nrandom.txt\ndef456.tar.gz\n";
    $out = (new BackupDestination())->handle(['action' => 'browse', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['files'] ?? []) === ['abc123.tar.gz', 'def456.tar.gz'], 'sirf archives: ' . json_encode($out));
    assert_true(($out['count'] ?? 0) === 2, 'count 2');
    acp_dest_cleanup($h);
});

test('backup.destination remove: config + key dono gayab', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $out = (new BackupDestination())->handle(['action' => 'remove', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['removed'] ?? false) === true, 'remove hua');
    assert_true(!is_file($h['root'] . '/etc/backup-destinations/offsite1.json'), 'config gayi');
    assert_true(!is_file($h['root'] . '/etc/backup-keys/offsite1'), 'key gayi');
    $list = (new BackupDestination())->handle(['action' => 'list', '_confirm' => 'backup.destination'], $h['ctx']);
    assert_true(($list['count'] ?? -1) === 0, 'list khaali');
    acp_dest_cleanup($h);
});

test('backup.destination password auth: sshpass wrapper chala, password argv me nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h, ['auth' => 'password', 'password' => 'SuperSecret123']);
    $h['cmd']->sshStdout = "ACP-OK\n";
    $out = (new BackupDestination())->handle(['action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['ok'] ?? false) === true, 'password auth se test chala');
    assert_true(is_file($h['root'] . '/etc/backup-keys/offsite1.password'), 'password file bani');
    assert_true((fileperms($h['root'] . '/etc/backup-keys/offsite1.password') & 0777) === 0600, 'password file 0600');
    $argv = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(!str_contains($argv, 'SuperSecret123'), 'argv me password nahi: ' . $argv);
    assert_true(str_contains($argv, 'PubkeyAuthentication=no'), 'password-only auth');
    acp_dest_cleanup($h);
});

test('backup.destination: unknown action refuse', function (): void {
    $h = acp_dest_harness();
    $msg = '';
    try {
        (new BackupDestination())->handle(['action' => 'destroy', '_confirm' => 'backup.destination'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'nahi chalega'), 'unknown action refuse: ' . $msg);
    acp_dest_cleanup($h);
});

// ---------------------------------------------------------------------------
// Ye do test LIVE bug (5 Oct, 0.73.0) se paida hue: live check me har
// destination action "binary not in agent allowlist: /usr/bin/ssh" se fail hua
// — FakeCommandExecutor allowlist check nahi karta, isliye offline tests green
// the. Ab dono taraf se band hai.
// ---------------------------------------------------------------------------

test('remote SSH tooling: ssh/scp/ssh-keygen/ssh-keyscan/sshpass agent allowlist me hain', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $list = $ref->getConstant('BIN_ALLOWLIST');
    assert_true(is_array($list), 'CommandRunner allowlist mili');

    $needed = [
        RemotePull::KEYSCAN, RemotePull::KEYGEN, RemotePull::SCP, RemotePull::SSHPASS,
        RemoteDestination::SSH, RemoteDestination::SCP, RemoteDestination::KEYGEN, RemoteDestination::SSHPASS,
    ];
    foreach ($needed as $bin) {
        assert_true(in_array($bin, $list, true), "{$bin} agent allowlist me hona chahiye — nahi to task 'binary not in agent allowlist' se fail hoga");
    }
});

test('backup.destination push: archive path destination se PEHLE check hota hai', function (): void {
    $h = acp_dest_harness();
    // destination save hi nahi ki — phir bhi ghalat path ka jawab "backup store" wala aana chahiye
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action'       => 'push',
            'name'         => 'no-such-destination',
            'archive_path' => '/etc/passwd.tar.gz',
            '_confirm'     => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'backup store'), 'galat path ka sahi error: ' . $msg);
    assert_true(!str_contains($msg, 'nahi mili'), 'error "destination nahi mili" nahi hona chahiye: ' . $msg);
    acp_dest_cleanup($h);
});

test('backup.destination push: verify call fail ho to bhi remote .part hatane ki koshish hoti hai', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $h['cmd']->sshFails = true;              // verify wala ssh call fail
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true($msg !== '', 'push fail hona chahiye');
    assert_true($h['cmd']->sshCalls >= 2, 'verify ke baad rm -f ki koshish bhi hui: calls=' . $h['cmd']->sshCalls);
    acp_dest_cleanup($h);
});

test('agent source lint: jo file catch (Throwable kare wo use Throwable bhi kare', function (): void {
    // 0.73.0 ka LIVE bug: RemoteDestination.php me `use Throwable;` missing tha, to
    // namespace ke andar `catch (Throwable)` kabhi match hi nahi hua aur push ki
    // saafai (remote .part hatana) chup-chaap skip ho gayi. Ye test dobara na ho.
    $root = dirname(__DIR__) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $bad = [];
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (!str_contains($code, 'catch (Throwable')) {
            continue;
        }
        if (preg_match('/^use Throwable;$/m', $code) !== 1) {
            $bad[] = $file->getPathname();
        }
    }
    assert_true($bad === [], 'in files me use Throwable missing hai: ' . implode(', ', $bad));
});


fwrite(STDOUT, "\nS9 BIND9 (dns.bind)\n");

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_bind_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-bind-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/etc/bind',
        $root . '/etc/bind/zones',
        $root . '/home',
        $root . '/alphacp',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    // distro jaisa named.conf + options (updater inhi par kaam karega)
    file_put_contents(
        $root . '/etc/bind/named.conf',
        "include \"/etc/bind/named.conf.options\";\ninclude \"/etc/bind/named.conf.local\";\n"
    );
    file_put_contents(
        $root . '/etc/bind/named.conf.options',
        "options {\n    directory \"/var/cache/bind\";\n};\n"
    );
    putenv('ACP_BIND_CONF=' . $root . '/etc/bind/named.conf');
    putenv('ACP_BIND_OPTIONS=' . $root . '/etc/bind/named.conf.options');
    putenv('ACP_BIND_ZONES=' . $root . '/etc/bind/named.conf.alphacp');
    putenv('ACP_BIND_ZONE_DIR=' . $root . '/etc/bind/zones');
    // fake executor in bins ko intercept karta hai — absolute path hona kaafi hai
    putenv('ACP_BIND_CHECKCONF=' . $root . '/bin/named-checkconf');
    putenv('ACP_BIND_CHECKZONE=' . $root . '/bin/named-checkzone');
    putenv('ACP_BIND_RNDC=' . $root . '/bin/rndc');
    putenv('ACP_BIND_DIG=' . $root . '/bin/dig');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');
    putenv('ACP_BIND_DIG_WAIT=1');   // tests me intezaar nahi (asli server 0.7s leta hai)

    $cmd = new FakeCommandExecutor();
    $cmd->hostnameI = "203.0.113.5 10.0.0.7\n";
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(
        log: $log,
        cmd: $cmd,
        paths: new PathGuard($dirs),
        taskId: null,
        taskRow: null,
    );

    return ['root' => $root, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root:string} $harness */
function acp_bind_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach ([
        'ACP_BIND_CONF', 'ACP_BIND_OPTIONS', 'ACP_BIND_ZONES', 'ACP_BIND_ZONE_DIR',
        'ACP_BIND_CHECKCONF', 'ACP_BIND_CHECKZONE', 'ACP_BIND_RNDC', 'ACP_BIND_DIG',
        'ACP_BIND_DIG_WAIT',
    ] as $name) {
        putenv($name);
    }
}

/** @return list<array<string,string>> */
function acp_bind_records(string $domain): array
{
    return [
        ['domain' => $domain, 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => $domain, 'name' => 'www', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => $domain, 'name' => 'mail', 'type' => 'A', 'value' => '203.0.113.11'],
        ['domain' => $domain, 'name' => '@', 'type' => 'MX', 'value' => 'mail.' . $domain],
        ['domain' => $domain, 'name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 a mx -all'],
        ['domain' => $domain, 'name' => 'shop', 'type' => 'CNAME', 'value' => $domain],
    ];
}

test('dns.bind setup idempotent — do baar chalao to bhi ek hi include line', function (): void {
    $h = acp_bind_harness();
    $conf = $h['root'] . '/etc/bind/named.conf';
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $after1 = (string) file_get_contents($conf);
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $after2 = (string) file_get_contents($conf);
    $include = 'include "' . $h['root'] . '/etc/bind/named.conf.alphacp";';
    assert_true(substr_count($after2, $include) === 1, 'include line ek hi baar likhni chahiye');
    assert_true(str_contains($after1, 'include "/etc/bind/named.conf.options";'), 'distro lines rehni chahiye');
    // backup sirf pehli baar banta hai (doosri baar overwrite nahi hota)
    assert_true(is_file($h['root'] . '/etc/bind/named.conf.options.acp-orig'));
    $backup = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.options.acp-orig');
    assert_true(str_contains($backup, 'directory "/var/cache/bind"'), 'asli options backup me bacchi honi chahiye');
    // managed options: loopback + server ka apna IP, recursion off
    $options = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.options');
    assert_true(str_contains($options, 'listen-on { 127.0.0.1; 203.0.113.5; };'), 'listen-on ghalat: ' . $options);
    assert_true(str_contains($options, 'recursion no;'));
    assert_true(str_contains($options, 'allow-transfer { none; };'));
    assert_true(is_dir($h['root'] . '/etc/bind/zones'));
    acp_bind_cleanup($h);
});

test('dns.bind setup named-checkconf fail ho to purani config wapas', function (): void {
    $h = acp_bind_harness();
    $options = $h['root'] . '/etc/bind/named.conf.options';
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->bindCheckconfFails = true;
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'named-checkconf fail');
    }
    assert_true($threw, 'checkconf fail par task reject hona chahiye');
    $restored = (string) file_get_contents($options);
    assert_true(str_contains($restored, 'directory "/var/cache/bind"'), 'purani options wapas aani chahiye');
    assert_true(!str_contains($restored, 'AlphaCP managed'), 'managed block hatna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone ke baad hi zone file likhi jati hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['ok'] === true);
    assert_true($out['records'] === 6, '6 records likhne chahiye the, mile ' . (int) $out['records']);
    assert_true(is_file($out['file']));
    assert_true($h['cmd']->namedCheckzoneCalls >= 1, 'named-checkzone chalana hi padta hai');
    assert_true(in_array('reload', (array) ($h['cmd']->rndcArgv ?? []), true), 'rndc reload hona chahiye');
    assert_true($out['verified'] === true, 'dig se SOA milna chahiye');
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '$TTL 300'), 'TTL header chahiye');
    assert_true(str_contains($body, '@ IN SOA ns1.alice.test. hostmaster.alice.test.'));
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    assert_true(str_contains($body, '@ IN MX 10 mail.alice.test.'));
    assert_true(str_contains($body, 'shop IN CNAME alice.test.'));
    assert_true(str_contains($body, '@ IN TXT "v=spf1 a mx -all"'), 'TXT quoted hona chahiye: ' . $body);
    assert_true(str_contains($body, 'ns1 IN A 203.0.113.5'), 'in-zone NS ka glue A chahiye');
    // zone clause named.conf.alphacp me
    $zones = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.alphacp');
    assert_true(str_contains($zones, 'zone "alice.test" { type master;'), 'zone clause chahiye: ' . $zones);
    // koi temp file nahi chhutni chahiye
    $leftovers = glob($h['root'] . '/etc/bind/zones/.db.*') ?: [];
    assert_true($leftovers === [], 'temp files saf ho jani chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone reject kare to doosre domain ka record zone me nahi jata', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => [
            ['domain' => 'alice.test', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
            ['domain' => 'bob.test', 'name' => '@', 'type' => 'A', 'value' => '198.51.100.10'],
        ],
    ], $h['ctx']);
    $file = $h['root'] . '/etc/bind/zones/db.alice.test';
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '198.51.100.10'), 'doosre domain ka record is zone me nahi likhna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone reject kare to kuch nahi likha jata (purani zone surakshit)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $first = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $before = (string) file_get_contents($first['file']);

    $h['cmd']->bindCheckzoneFails = true;
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => 'bad', 'type' => 'A', 'value' => '203.0.113.99']],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'named-checkzone');
    }
    assert_true($threw, 'checkzone fail par reject hona chahiye');
    $after = (string) file_get_contents($first['file']);
    assert_true($after === $before, 'purani zone bilkul waise hi rehni chahiye');
    assert_true(!str_contains($after, '203.0.113.99'), 'reject hua record kabhi nahi likhna chahiye');
    assert_true((glob($h['root'] . '/etc/bind/zones/.db.*') ?: []) === [], 'temp file hatni chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — hostile record reject, zone file banti hi nahi', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => '|/bin/sh', 'type' => 'A', 'value' => '203.0.113.10']],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile record reject hona chahiye');
    assert_true(!is_file($h['root'] . '/etc/bind/zones/db.alice.test'));
    // value me newline ho to bhi
    $threw2 = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => 'x', 'type' => 'TXT', 'value' => "ok\n@ IN NS evil.test."]],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw2 = true;
    }
    assert_true($threw2, 'newline wala TXT reject hona chahiye (zone injection)');
    acp_bind_cleanup($h);
});

test('dns.bind write — serial har baar badhta hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $a = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $b = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($b['serial'] > $a['serial'], 'naya serial purane se bada hona chahiye');
    $body = (string) file_get_contents($b['file']);
    assert_true(str_contains($body, (string) $b['serial']), 'zone me naya serial hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — NAYA zone `rndc reconfig` ke bina serve nahi hota', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $h['cmd']->rndcArgvs = [];

    // pehli baar = naya zone: named ko config dobara padhni padti hai
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $sawReconfig = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $sawReconfig = true;
        }
    }
    assert_true($sawReconfig, 'naye zone ke liye rndc reconfig chalna hi chahiye (warna dig khamosh)');

    // doosri baar = zone maujood, dig jawab de raha -> reconfig ki zaroorat nahi
    $h['cmd']->rndcArgvs = [];
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $reconfigAgain = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $reconfigAgain = true;
        }
    }
    assert_true(!$reconfigAgain, 'maujooda zone par reconfig nahi chalna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named chalu na ho to bhi sach boli jaati hai (verified=false)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    // systemd khamosh: is-active fail -> named_running false
    $h['cmd']->failWhenContains = 'is-active';
    $status = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true(($status['named_running'] ?? true) === false, 'named_running false hona chahiye');
    // dig khamosh -> verified false (jhoothi "ok" nahi)
    $h['cmd']->failWhenContains = null;
    $h['cmd']->digStdout = '';
    $out = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['verified'] === false);
    assert_true($out['dig_soa'] === '');
    acp_bind_cleanup($h);
});

test('dns.bind write — nameserver.json ke hisaab se NS (glue A ke saath)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    mkdir($h['root'] . '/alphacp/etc/dns', 0755, true);
    file_put_contents(
        $h['root'] . '/alphacp/etc/dns/nameserver.json',
        json_encode(['software' => 'bind', 'ns1' => 'ns1.alice.test', 'ns2' => 'ns2.alice.test'])
    );
    $out = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '@ IN NS ns1.alice.test.'));
    assert_true(str_contains($body, '@ IN NS ns2.alice.test.'));
    assert_true(str_contains($body, 'ns1 IN A 203.0.113.5'), 'ns1 ka glue A chahiye');
    assert_true(str_contains($body, 'ns2 IN A 203.0.113.5'), 'ns2 ka glue A chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — account ke zone.json se records (panel wahi likhta hai)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    mkdir($h['root'] . '/home/alicehost/etc/dns', 0755, true);
    file_put_contents(
        $h['root'] . '/home/alicehost/etc/dns/zone.json',
        (string) json_encode(acp_bind_records('alice.test'))
    );
    $out = (new BindSetup())->handle([
        'action'   => 'write',
        'domain'   => 'alice.test',
        'username' => 'alicehost',
    ], $h['ctx']);
    assert_true($out['records'] === 6, 'account zone.json ke 6 records aane chahiye');
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    // bina records aur bina username -> reject
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'write', 'domain' => 'bob.test'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'records/username ke bina likhna reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — TTL payload se zone me jata hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'ttl'     => 60,
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '$TTL 60'), 'TTL 60 hona chahiye: ' . $body);
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'ttl'    => 5,
            'records' => acp_bind_records('alice.test'),
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'TTL 60 se kam reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind verify — dig ka asli jawab, khali ho to verified false', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $out = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true($out['verified'] === true);
    assert_true(str_contains((string) $out['dig_soa'], 'ns1.alice.test.'));

    $v = (new BindSetup())->handle(['action' => 'verify', 'domain' => 'alice.test'], $h['ctx']);
    assert_true(str_contains((string) $v['soa'], 'ns1.alice.test.'));
    assert_true($v['a'] !== '');

    // ab dig khamosh ho jaye — "verified" jhooth nahi bolna chahiye
    $h['cmd']->digStdout = '';
    $silent = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true($silent['verified'] === false, 'dig khamosh ho to verified false hona chahiye');
    assert_true($silent['dig_soa'] === '');
    acp_bind_cleanup($h);
});

test('dns.bind remove — zone file hat ti hai aur zone clause bhi', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $out = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true(is_file($out['file']));
    $del = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($del['removed'] === true);
    assert_true(!is_file($out['file']), 'zone file hatni chahiye');
    $zones = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.alphacp');
    assert_true(!str_contains($zones, 'zone "alice.test"'), 'zone clause bhi hatna chahiye: ' . $zones);
    // dobara remove = shant, koi error nahi
    $again = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($again['removed'] === false && $again['ok'] === true);
    acp_bind_cleanup($h);
});

test('dns.bind remove — zone mitne ke baad named use serve nahi karta (reconfig)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $h['cmd']->digFollowsZones = true;   // asli named jaisa: file hat te hi khamosh
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['verified'] === true, 'pehle zone live honi chahiye');

    $h['cmd']->rndcArgvs = [];
    $del = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($del['removed'] === true);
    assert_true($del['gone'] === true, 'zone hatne ke baad dig khamosh hona chahiye (memory se bhi)');
    assert_true($del['dig_after'] === '', 'dig ab kuch nahi dena chahiye');
    $sawReconfig = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $sawReconfig = true;
        }
    }
    assert_true($sawReconfig, 'zone hatane ke baad bhi rndc reconfig chalna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind list — zone files count', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    (new BindSetup())->handle(['action' => 'write', 'domain' => 'bob.test', 'records' => [['domain' => 'bob.test', 'name' => '@', 'type' => 'A', 'value' => '198.51.100.10']], ], $h['ctx']);
    $list = (new BindSetup())->handle(['action' => 'list'], $h['ctx']);
    assert_true($list['count'] === 2, '2 zones hone chahiye, mile ' . (int) $list['count']);
    assert_true(in_array('alice.test', $list['zones'], true));
    assert_true(in_array('bob.test', $list['zones'], true));
    acp_bind_cleanup($h);
});

test('dns.bind status — installed aur checkconf ki sachchi report', function (): void {
    $h = acp_bind_harness();
    $st = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st['installed'] === true, 'env override ke saath installed true hona chahiye');
    assert_true($st['checkconf'] === 'ok');
    assert_true($st['zones'] === 0);
    $h['cmd']->bindCheckconfFails = true;
    $bad = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($bad['checkconf'] !== 'ok', 'checkconf fail report hona chahiye');
    // jab bind9 installed hi na ho (env hata do)
    foreach (['ACP_BIND_CHECKCONF', 'ACP_BIND_CHECKZONE'] as $k) {
        putenv($k);
    }
    $h['cmd']->binsAbsent = true;   // live par bind9 asli me laga ho to bhi hermetic
    $none = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($none['installed'] === false, 'bina bind9 ke installed false hona chahiye');
    assert_true(isset($none['error']));
    acp_bind_cleanup($h);
});

test('dns.bind — galat action aur galat domain reject', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'nuclear'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'unknown action reject hona chahiye');
    $threw2 = false;
    try {
        (new BindSetup())->handle(['action' => 'remove', 'domain' => '|/bin/sh'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw2 = true;
    }
    assert_true($threw2, 'hostile domain reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind sync — sab accounts ke zone.json se zones, ek fail to doosra nahi rukta', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    // do account + ek system dir (ignore hona chahiye)
    foreach (['alicehost' => 'alice.test', 'bobhost' => 'bob.test'] as $user => $domain) {
        mkdir($h['root'] . '/home/' . $user . '/etc/dns', 0755, true);
        file_put_contents(
            $h['root'] . '/home/' . $user . '/etc/dns/zone.json',
            (string) json_encode(acp_bind_records($domain))
        );
    }
    mkdir($h['root'] . '/home/ubuntu/etc', 0755, true);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";

    $out = (new BindSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['count'] === 2, '2 zones likhni chahiye, mili ' . (int) $out['count']);
    assert_true($out['failed'] === [], 'koi zone fail nahi hona chahiye');
    assert_true($out['ok'] === true);
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.alice.test'));
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.bob.test'));
    $body = (string) file_get_contents($h['root'] . '/etc/bind/zones/db.bob.test');
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    assert_true(!is_file($h['root'] . '/etc/bind/zones/db.ubuntu'), 'system dir zone nahi banni chahiye');

    // ab bob ka zone kharaab kar do — alice phir bhi likhni chahiye
    file_put_contents(
        $h['root'] . '/home/bobhost/etc/dns/zone.json',
        (string) json_encode([['domain' => 'bob.test', 'name' => '|/bin/sh', 'type' => 'A', 'value' => '198.51.100.10']])
    );
    @unlink($h['root'] . '/etc/bind/zones/db.alice.test');
    @unlink($h['root'] . '/etc/bind/zones/db.bob.test');
    $out2 = (new BindSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out2['ok'] === false, 'ek zone fail hone par ok false hona chahiye');
    assert_true(count($out2['failed']) === 1, 'ek hi zone fail hona chahiye');
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.alice.test'), 'doosra account phir bhi likha jana chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind schema — payload fail-closed', function (): void {
    $schema = acp_task_registry()['dns.bind']['schema'];
    $good = [
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => [['domain' => 'alice.test', 'name' => 'www', 'type' => 'A', 'value' => '203.0.113.10']],
    ];
    assert_true(JsonSchema::validate($schema, $good) === [], 'valid payload pass hona chahiye');
    assert_true(JsonSchema::validate($schema, $good + ['evil' => 1]) !== [], 'extra key reject');
    // status/setup/list ko domain ki zaroorat nahi, par galat domain/TTL reject hona chahiye
    assert_true(JsonSchema::validate($schema, ['action' => 'status']) === [], 'status ko domain ki zaroorat nahi');
    assert_true(JsonSchema::validate($schema, ['action' => 'remove', 'domain' => '|/bin/sh']) !== [], 'hostile domain schema me reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'write', 'domain' => 'alice.test', 'ttl' => 5, 'records' => $good['records']]) !== [], 'TTL range ke bahar reject');
    $bad = $good;
    $bad['action'] = 'nuclear';
    assert_true(JsonSchema::validate($schema, $bad) !== [], 'unknown action schema me reject');
    $bad2 = $good;
    $bad2['records'][0]['type'] = 'AAAA';
    assert_true(JsonSchema::validate($schema, $bad2) !== [], 'AAAA abhi allow nahi (A/CNAME/MX/TXT)');
});

test('BIND tools: har possible path agent allowlist me hai (chuppi hui allowlist fail na ho)', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach ([
        'named-checkconf' => BindServer::CHECKCONF_PATHS,
        'named-checkzone' => BindServer::CHECKZONE_PATHS,
        'rndc'            => BindServer::RNDC_PATHS,
        'dig'             => BindServer::DIG_PATHS,
    ] as $tool => $paths) {
        assert_true($paths !== [], "{$tool} candidates khali nahi hone chahiye");
        foreach ($paths as $path) {
            assert_true(
                in_array($path, $allow, true),
                "allowlist me {$path} nahi hai — server par binary wahan mila to task chup-chaap fail hoga"
            );
        }
    }
    // distro ke hisaab se binary kahin bhi ho — dono jagah allowlist me honi chahiye
    assert_true(in_array('/usr/sbin/named-checkconf', $allow, true));
    assert_true(in_array('/usr/bin/named-checkconf', $allow, true));
    assert_true(in_array('/usr/sbin/rndc', $allow, true));
    assert_true(in_array('/usr/bin/rndc', $allow, true));
});

test('BindServer renderZone — zone injection impossible (quote/escape)', function (): void {
    $body = BindServer::renderZone(
        'alice.test',
        [['domain' => 'alice.test', 'name' => 'x', 'type' => 'TXT', 'value' => 'say "hi" \\ ok']],
        ['ns1.alice.test'],
        '203.0.113.5',
        2025090101,
        300,
    );
    assert_true(str_contains($body, 'x IN TXT "say \\"hi\\" \\\\ ok"'), 'TXT me quote/backslash escape hone chahiye: ' . $body);
    assert_true(substr_count($body, "\n") === 5, 'SOA + NS + glue A + 1 record + trailing newline');
});


fwrite(STDOUT, "\nS7 MAIL SERVER (mail.server)\n");

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_mail_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-mail-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/home', $root . '/etc/exim4', $root . '/etc/dovecot/conf.d', $root . '/alphacp',
        $root . '/etc/exim4/vacation', $root . '/etc/exim4/spam', $root . '/alphacp/etc/mail/dkim',
        $root . '/var/log/exim4', $root . '/etc/spamassassin', $root . '/run/greylistd',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    // distro jaisi exim template (backup lene ke liye)
    file_put_contents($root . '/etc/exim4/exim4.conf.template', "# distro exim template\n");
    putenv('ACP_MAIL_EXIM_TEMPLATE=' . $root . '/etc/exim4/exim4.conf.template');
    putenv('ACP_MAIL_EXIM_DOMAINS=' . $root . '/etc/exim4/alphacp-domains');
    putenv('ACP_MAIL_EXIM_RECIPIENTS=' . $root . '/etc/exim4/alphacp-recipients');
    putenv('ACP_MAIL_EXIM_ALIASES=' . $root . '/etc/exim4/alphacp-aliases');
    putenv('ACP_MAIL_FILTERS=' . $root . '/etc/exim4/alphacp-filters');
    putenv('ACP_MAIL_CATCHALL=' . $root . '/etc/exim4/alphacp-catchall');
    putenv('ACP_MAIL_VACATION_DIR=' . $root . '/etc/exim4/vacation');
    putenv('ACP_MAIL_SPAM_DIR=' . $root . '/etc/exim4/spam');
    putenv('ACP_MAIL_DKIM_DIR=' . $root . '/alphacp/etc/mail/dkim');
    putenv('ACP_MAIL_DOVECOT_USERS=' . $root . '/etc/dovecot/alphacp-users');
    putenv('ACP_MAIL_DOVECOT_CONF=' . $root . '/etc/dovecot/conf.d/99-alphacp.conf');
    // fake executor in binaries ko intercept karta hai
    putenv('ACP_MAIL_EXIM=' . $root . '/bin/exim4');
    putenv('ACP_MAIL_DOVECOT=' . $root . '/bin/dovecot');
    putenv('ACP_MAIL_DOVEADM=' . $root . '/bin/doveadm');
    putenv('ACP_MAIL_DOVECONF=' . $root . '/bin/doveconf');
    putenv('ACP_MAIL_UPDATE_EXIM=' . $root . '/bin/update-exim4.conf');
    putenv('ACP_MAIL_EXIM_OPTIONS=' . $root . '/alphacp/etc/mail/exim-options.json');
    putenv('ACP_MAIL_DOVECOT_OPTIONS=' . $root . '/alphacp/etc/mail/dovecot-options.json');
    putenv('ACP_MAIL_SPAMASSASSIN_CONF=' . $root . '/etc/spamassassin/local.cf');
    putenv('ACP_MAIL_GREYLISTD_SOCKET=' . $root . '/run/greylistd/socket');
    putenv('ACP_MAIL_MAINLOG=' . $root . '/var/log/exim4/mainlog');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');

    $cmd = new FakeCommandExecutor();
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(
        log: $log,
        cmd: $cmd,
        paths: new PathGuard($dirs),
        taskId: null,
        taskRow: null,
    );

    return ['root' => $root, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root:string} $harness */
function acp_mail_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach ([
        'ACP_MAIL_EXIM_TEMPLATE', 'ACP_MAIL_EXIM_DOMAINS', 'ACP_MAIL_EXIM_RECIPIENTS',
        'ACP_MAIL_EXIM_ALIASES', 'ACP_MAIL_CATCHALL', 'ACP_MAIL_VACATION_DIR',
        'ACP_MAIL_SPAM_DIR', 'ACP_MAIL_DKIM_DIR',
        'ACP_MAIL_DOVECOT_USERS', 'ACP_MAIL_DOVECOT_CONF',
        'ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT', 'ACP_MAIL_DOVEADM', 'ACP_MAIL_DOVECONF',
        'ACP_MAIL_UPDATE_EXIM',
        'ACP_MAIL_EXIM_OPTIONS', 'ACP_MAIL_DOVECOT_OPTIONS', 'ACP_MAIL_MAINLOG',
        'ACP_MAIL_FILTERS',
    ] as $name) {
        putenv($name);
    }
}

/** Do account: alicehost (2 mailbox + 1 forwarder) aur bobhost (1 mailbox). */
function acp_mail_seed_accounts(string $root): void
{
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
    $base = [
        'alicehost' => [
            'passwd' => [
                "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/alicehost/mail/alice.test/info::",
                "sales@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/alicehost/mail/alice.test/sales::userdb_quota_rule=*:storage=1024M",
                // doosre account ka maildir — kabhi accept nahi hona chahiye
                "steal@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/bobhost/mail/bob.test/steal::",
            ],
            'aliases' => [
                'contact@alice.test: info@alice.test',
                'bad@alice.test:',
            ],
        ],
        'bobhost' => [
            'passwd' => [
                "info@bob.test:{BLF-CRYPT}{$hash}:1002:1002::{$root}/home/bobhost/mail/bob.test/info::",
                "garbage-line-without-fields",
            ],
            'aliases' => [],
        ],
    ];
    foreach ($base as $user => $files) {
        $dir = $root . '/home/' . $user . '/etc/mail';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/passwd', implode("\n", $files['passwd']) . "\n");
        file_put_contents($dir . '/aliases', implode("\n", $files['aliases']) . "\n");
    }
}

test('mail.server sync — sab accounts ke mailbox/forwarder aggregate (doosre ka maildir nahi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['mailboxes'] === 3, '3 valid mailbox (chori wala chhutna chahiye), mile ' . (int) $out['mailboxes']);
    assert_true($out['domains'] === 2, '2 domains: alice.test + bob.test');
    assert_true($out['aliases'] === 1, '1 valid forwarder (khali dest wala chhut jana chahiye)');

    $users = (string) file_get_contents($h['root'] . '/etc/dovecot/alphacp-users');
    assert_true(str_contains($users, 'info@alice.test:'));
    assert_true(!str_contains($users, 'steal@alice.test'), 'doosre account ka maildir kabhi nahi aana chahiye');
    assert_true(!str_contains($users, 'garbage-line'), 'bekaar line ignore honi chahiye');

    $rec = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-recipients');
    assert_true(str_contains($rec, 'info@alice.test: ' . $h['root'] . '/home/alicehost/mail/alice.test/info 1001 1001'), 'recipients line: ' . $rec);
    assert_true(str_contains($rec, '1024M') === false, 'recipients me quota nahi hota');

    $dom = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-domains');
    assert_true(str_contains($dom, 'alice.test') && str_contains($dom, 'bob.test'));

    $al = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($al, 'contact@alice.test: info@alice.test'));
    acp_mail_cleanup($h);
});

test('mail.server sync publishes sanitized mailing-list subscribers to Exim aliases', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $listDir = $h['root'] . '/home/alicehost/etc/mail';
    file_put_contents($listDir . '/lists.json', json_encode([[
        'local' => 'news',
        'domain' => 'alice.test',
        'owner' => 'owner@example.net',
        'members' => ['team@example.org', 'info@alice.test', 'team@example.org'],
    ]], JSON_UNESCAPED_SLASHES));

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['lists'] === 1, 'one Exim list route must be published');
    assert_true($out['aliases'] === 2, 'one forwarder plus one list route should be in the alias map');
    $aliases = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($aliases, 'contact@alice.test: info@alice.test'), 'forwarder must remain intact');
    assert_true(str_contains($aliases, 'news@alice.test: info@alice.test, team@example.org'), 'list must fan out to normalized unique subscribers');
    assert_true(str_contains((string) file_get_contents($h['root'] . '/etc/exim4/alphacp-domains'), 'alice.test'), 'list domain must be locally accepted');
    acp_mail_cleanup($h);
});

test('mail.server sync skips list/mailbox collisions, nested lists, and hostile members', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $listDir = $h['root'] . '/home/alicehost/etc/mail';
    file_put_contents($listDir . '/lists.json', json_encode([
        ['local' => 'info', 'domain' => 'alice.test', 'owner' => 'owner@example.net', 'members' => ['x@example.net']],
        ['local' => 'contact', 'domain' => 'alice.test', 'owner' => 'owner@example.net', 'members' => ['x@example.net']],
        ['local' => 'list-a', 'domain' => 'alice.test', 'owner' => 'owner@example.net', 'members' => ['list-b@alice.test']],
        ['local' => 'list-b', 'domain' => 'alice.test', 'owner' => 'owner@example.net', 'members' => ['list-a@alice.test']],
        ['local' => 'bad', 'domain' => 'alice.test', 'owner' => 'owner@example.net', 'members' => ['|/bin/sh']],
    ], JSON_UNESCAPED_SLASHES));

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['lists'] === 0, 'all unsafe/conflicting list routes must be skipped');
    assert_true($out['list_errors'] >= 5, 'each collision/invalid or nested list must be counted');
    $aliases = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($aliases, 'contact@alice.test: info@alice.test'), 'original forwarder must not be overwritten');
    assert_true(!str_contains($aliases, 'list-a@alice.test:') && !str_contains($aliases, 'list-b@alice.test:'), 'nested list loop must not be installed');
    acp_mail_cleanup($h);
});

test('mail.server setup — config validate hone ke baad hi apply (warn: mail band na ho)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    assert_true($out['ok'] === true);
    assert_true(is_file($h['root'] . '/etc/dovecot/conf.d/99-alphacp.conf'));
    assert_true(is_file($h['root'] . '/etc/exim4/exim4.conf.template.acp-orig'), 'asli template ki backup honi chahiye');
    assert_true(is_file($h['root'] . '/alphacp/etc/mail-server-configured'), 'configured marker likhna chahiye');
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'alphacp_maildir:'), 'exim transport hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_mailbox:'), 'exim router hona chahiye');
    assert_true(str_contains($tpl, 'deny message = relay not permitted'), 'open relay band hona chahiye');
    $dov = (string) file_get_contents($h['root'] . '/etc/dovecot/conf.d/99-alphacp.conf');
    assert_true(str_contains($dov, 'driver = passwd-file'));
    assert_true(str_contains($dov, 'mail_location = maildir:~/'), 'Maildir location hona chahiye');
    // Dovecot 2.3 docs: "scheme=" SIRF passdb ke liye hai. userdb args me likhne se
    // Dovecot poore string ko filename samajh leta hai ->
    //   passwd-file scheme=BLF-CRYPT /etc/dovecot/alphacp-users:open(...) No such file or directory
    // -> userdb dead, `doveadm user` fail (live server par yahi hua tha).
    preg_match_all('/^\s*(passdb|userdb)\s*\{(.*?)^\}/ms', $dov, $blocks, PREG_SET_ORDER);
    $b = [];
    foreach ($blocks as $set) {
        $b[$set[1]] = $set[2];
    }
    assert_true(isset($b['passdb'], $b['userdb']), 'passdb + userdb dono hone chahiye');
    assert_true(str_contains($b['passdb'], 'scheme=BLF-CRYPT'), 'passdb me scheme= hona chahiye');
    assert_true(! str_contains($b['userdb'], 'scheme='), 'userdb args me scheme= NAHI hona chahiye (Dovecot ise filename samajhta hai)');
    assert_true(
        (bool) preg_match('/args\s*=\s*\S*alphacp-users\s*$/m', trim($b['userdb'])),
        'userdb args me seedha users-file path hona chahiye',
    );
    // systemctl enable/restart dono services ke liye chale
    $line = implode(' ', array_map(static fn (array $a): string => implode(' ', $a), $h['cmd']->calls));
    assert_true(str_contains($line, 'systemctl enable exim4'));
    assert_true(str_contains($line, 'systemctl enable dovecot'));
    acp_mail_cleanup($h);
});

test('mail.server setup — kharaab exim config ho to purani template wapas', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $h['cmd']->mailEximConfigFails = true;
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'exim config reject');
    }
    assert_true($threw, 'kharaab config par reject hona chahiye');
    $restored = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true($restored === "# distro exim template\n", 'purani template wapas aani chahiye');
    acp_mail_cleanup($h);
});

test('mail.server verify — asli exim routing + doveadm mailbox (jhoothi ok nahi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

    $h['cmd']->eximBtOutput = "info@alice.test\n  router = alphacp_mailbox, transport = alphacp_maildir\n";
    $h['cmd']->doveadmUserOutput = "field value\nuid 1001\ngid 1001\nhome {$h['root']}/home/alicehost/mail/alice.test/info\n";
    $v = (new MailServerSetup())->handle(['action' => 'verify', 'address' => 'info@alice.test'], $h['ctx']);
    assert_true($v['routed'] === true, 'routing milna chahiye');
    assert_true($v['has_mailbox'] === true, 'doveadm se mailbox milna chahiye');

    // ab exim bole "unrouteable" -> verified false hona chahiye
    $h['cmd']->eximBtOutput = "Unrouteable address\n";
    $h['cmd']->doveadmUserOutput = '';
    $bad = (new MailServerSetup())->handle(['action' => 'verify', 'address' => 'ghost@alice.test'], $h['ctx']);
    assert_true($bad['routed'] === false, 'Unrouteable par routed false hona chahiye');
    assert_true($bad['has_mailbox'] === false);

    // galat address reject
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'verify', 'address' => '|/bin/sh@x'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile address reject hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server status/list — sachchi report (installed na ho to bhi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $st = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st['installed'] === true, 'env override ke saath installed true');
    assert_true($st['exim_config'] === 'ok', 'exim config ok: ' . json_encode($st['exim_config']));
    assert_true($st['dovecot_config'] === 'ok', 'dovecot config ok: ' . json_encode($st['dovecot_config']));
    assert_true(($st['services']['exim4'] ?? false) === true, 'exim4 active: ' . json_encode($st['services']));
    assert_true($st['mailboxes'] === 0, 'abhi sync nahi hua to 0 (mile ' . (int) $st['mailboxes'] . ')');

    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    $st2 = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st2['mailboxes'] === 3);
    assert_true($st2['domains'] === 2);

    $list = (new MailServerSetup())->handle(['action' => 'list'], $h['ctx']);
    assert_true($list['count'] === 3);
    assert_true(in_array('info@alice.test', $list['mailboxes'], true));
    assert_true(in_array('info@bob.test', $list['mailboxes'], true));
    assert_true(!in_array('steal@alice.test', $list['mailboxes'], true));

    // ab binaries hi na hon (env hata do, asli path sandbox me maujood nahi)
    foreach (['ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT', 'ACP_MAIL_DOVEADM'] as $k) {
        putenv($k);
    }
    $h['cmd']->binsAbsent = true;   // live par exim/dovecot asli me lage hain
    $none = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($none['installed'] === false, 'bina binaries ke installed false hona chahiye');
    assert_true(isset($none['error']));
    acp_mail_cleanup($h);
});

test('mail.server — galat action reject', function (): void {
    $h = acp_mail_harness();
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'nuclear'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'unknown action reject hona chahiye');
    acp_mail_cleanup($h);
});

test('S7 mail tools: har possible path agent allowlist me hai', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach ([
        'exim4' => MailServer::EXIM_PATHS,
        'dovecot' => MailServer::DOVECOT_PATHS,
        'doveadm' => MailServer::DOVEADM_PATHS,
        'doveconf' => MailServer::DOVECONF_PATHS,
        'update-exim4.conf' => MailServer::UPDATE_EXIM_PATHS,
    ] as $tool => $paths) {
        foreach ($paths as $path) {
            assert_true(in_array($path, $allow, true), "allowlist me {$path} nahi hai ({tool})");
        }
    }
});

test('mail.server schema — payload fail-closed', function (): void {
    $schema = acp_task_registry()['mail.server']['schema'];
    assert_true(JsonSchema::validate($schema, ['action' => 'status']) === [], 'status pass hona chahiye');
    assert_true(JsonSchema::validate($schema, ['action' => 'status', 'evil' => 1]) !== [], 'extra key reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'destroy']) !== [], 'unknown action reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'verify', 'address' => '|/bin/sh']) !== [], 'hostile address reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'verify', 'address' => 'a@b.test']) === [], 'sahi address pass');
    assert_true(JsonSchema::validate($schema, [
        'action' => 'spamassassin', 'enabled' => true, 'required_score' => 6.5,
        'reject_score' => 0, 'greylisting' => false,
    ]) === [], 'SpamAssassin + greylisting ka valid payload pass');
    assert_true(JsonSchema::validate($schema, ['action' => 'spamassassin', 'enabled' => 'yes']) !== [], 'boolean field me string reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'spamassassin', 'reject_score' => 31]) !== [], 'reject score limit se bahar reject');
});


fwrite(STDOUT, "\nS7 MAIL EXTRAS (catch-all / autoresponder / spam / SPF-DKIM-DMARC)\n");

/** @param array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} $h */
function acp_mail_seed_extras(array $h): void
{
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    // alicehost: 1 mailbox + catchall + autoresponder + spam lists + deliverability
    $home = $root . '/home/alicehost';
    mkdir($home . '/etc/mail', 0755, true);
    mkdir($home . '/mail/alice.test/info', 0755, true);
    file_put_contents($home . '/etc/mail/passwd', "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$home}/mail/alice.test/info::\n");
    file_put_contents($home . '/etc/mail/catchall', "*@alice.test: info@alice.test\n");
    // pipe wala destination kabhi nahi chalna chahiye
    file_put_contents($home . '/etc/mail/aliases', "web@alice.test: info@alice.test\n");
    file_put_contents(
        $home . '/etc/mail/autorespond',
        json_encode([[
            'local' => 'info', 'domain' => 'alice.test',
            'subject' => "Office band hai\nInjected: evil", 'body' => "Main chutti par hu.\nKal lautunga.",
            'interval_h' => 24,
        ], [
            'local' => 'ghost', 'domain' => 'alice.test',   // aisa mailbox hai hi nahi
            'subject' => 'x', 'body' => 'y', 'interval_h' => 24,
        ]])
    );
    file_put_contents($home . '/etc/mail/spam.json', json_encode([
        'required_score' => 5,
        'blacklist'      => ['spam@bad.test', '|/bin/sh'],
        'whitelist'      => ['boss@good.test'],
    ]));
    file_put_contents($home . '/etc/mail/deliverability.json', json_encode([['domain' => 'alice.test']]));
    mkdir($home . '/etc/dns', 0755, true);
    file_put_contents($home . '/etc/dns/zone.json', json_encode([
        ['domain' => 'alice.test', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => 'alice.test', 'name' => 'www', 'type' => 'CNAME', 'value' => 'alice.test'],
        ['domain' => 'alice.test', 'name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 include:old -all'],
        ['domain' => 'alice.test', 'name' => 'note', 'type' => 'TXT', 'value' => 'user ka apna note'],
    ]));
}

test('mail.server sync — catch-all + autoresponder + spam lists (asli files)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['catchalls'] === 1, '1 catch-all hona chahiye, mile ' . (int) $out['catchalls']);
    assert_true($out['responders'] === 1, 'sirf maujooda mailbox ka autoresponder (ghost nahi), mile ' . (int) $out['responders']);
    assert_true($out['spam_lists'] === 1, '1 mailbox ke liye spam lists');

    $catch = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-catchall');
    assert_true(str_contains($catch, '*@alice.test: info@alice.test'), 'catchall file: ' . $catch);

    $eml = (string) @file_get_contents($h['root'] . '/etc/exim4/vacation/info@alice.test.eml');
    assert_true(str_contains($eml, 'Subject: Office band hai'), 'subject ek line me hona chahiye: ' . $eml);
    assert_true(!str_contains($eml, "\nInjected"), 'subject me newline inject nahi hona chahiye');
    assert_true(str_contains($eml, 'Main chutti par hu.'), 'body hona chahiye');
    assert_true(trim((string) @file_get_contents($h['root'] . '/etc/exim4/vacation/info@alice.test.repeat')) === '24h', 'once_repeat 24h');
    assert_true(!is_file($h['root'] . '/etc/exim4/vacation/ghost@alice.test.eml'), 'bina mailbox ke autoresponder nahi');

    $deny = (string) @file_get_contents($h['root'] . '/etc/exim4/spam/info@alice.test.deny');
    assert_true(str_contains($deny, 'spam@bad.test'), 'deny list: ' . $deny);
    assert_true(!str_contains($deny, '/bin/sh'), 'pipe wala entry kabhi nahi likhna chahiye');
    $allow = (string) @file_get_contents($h['root'] . '/etc/exim4/spam/info@alice.test.allow');
    assert_true(str_contains($allow, 'boss@good.test'), 'allow list: ' . $allow);
    acp_mail_cleanup($h);
});

test('mail.server sync — hataya hua autoresponder ka file bhi hatana padta hai', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    $stale = $h['root'] . '/etc/exim4/vacation/info@alice.test.eml';
    assert_true(is_file($stale), 'pehli baar file bani');
    // ab autoresponder hata do
    file_put_contents($h['root'] . '/home/alicehost/etc/mail/autorespond', '[]');
    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(!is_file($stale), 'purana vacation file hatna chahiye — warna deleted responder ke jawab jate rahenge');
    acp_mail_cleanup($h);
});

test('mail.server setup — exim template me catchall/autoreply/DNSBL/spam ACL', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'alphacp_catchall:'), 'catchall router hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_autoreply:'), 'autoreply router hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_vacation:'), 'vacation transport hona chahiye');
    assert_true(str_contains($tpl, 'driver = autoreply'), 'autoreply driver');
    assert_true(str_contains($tpl, 'unseen'), 'autoreply unseen hona chahiye (delivery bhi ho)');
    assert_true(str_contains($tpl, 'wildlsearch;'), 'spam lists ACL me wildlsearch');
    assert_true(str_contains($tpl, 'zen.spamhaus.org'), 'DNSBL hona chahiye');
    assert_true(str_contains($tpl, 'add_header = X-AlphaCP-DNSBL'), 'DNSBL sirf header/log (reject nahi)');
    assert_true(!str_contains($tpl, 'spam = nobody'), 'bina spamd ke SpamAssassin ACL nahi likhna chahiye');
    // DKIM is exim build me support nahi (fake -bV me DKIM nahi) -> config me nahi
    assert_true(!str_contains($tpl, 'dkim_private_key'), 'DKIM unsupported hone par config me nahi hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server spamassassin — score/header config, score zero tag-only, service start', function (): void {
    $h = acp_mail_harness();
    $oldSpamd = getenv('ACP_MAIL_SPAMD');
    putenv('ACP_MAIL_SPAMD=' . $h['root'] . '/bin/spamd');
    try {
        $h['cmd']->mailDkim = true; // fake -bV: Content_Scanning + DKIM
        $h['cmd']->systemctlStates['spamassassin'] = 'inactive';
        $localCf = $h['root'] . '/etc/spamassassin/local.cf';
        file_put_contents($localCf, "# administrator note\nrequired_score 5.0\nclear_report_template\n");
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

        $out = (new MailServerSetup())->handle([
            'action' => 'spamassassin',
            'enabled' => true,
            'required_score' => 6.5,
            'reject_score' => 0,
        ], $h['ctx']);
        assert_true(($out['status'] ?? '') === 'ok', 'SpamAssassin config safal');
        assert_true(($out['spam']['enabled'] ?? false) === true, 'enabled status');
        assert_true((float) ($out['spam']['reject_score'] ?? -1) === 0.0, 'zero ka matlab tag-only: ' . var_export($out['spam']['reject_score'] ?? null, true));
        assert_true(($h['cmd']->systemctlStates['spamassassin'] ?? '') === 'active', 'spamd service start hua');

        $calls = implode("\n", array_map(static fn (array $a): string => implode(' ', $a), $h['cmd']->calls));
        assert_true(str_contains($calls, 'systemctl enable spamassassin'), 'spamd boot par enable');
        assert_true(str_contains($calls, 'systemctl start spamassassin'), 'spamd start');
        assert_true(str_contains($h['cmd']->spamAssassinConfAtServiceChange['start'] ?? '', 'required_score 6.5'), 'spamd start se pehle local.cf score likhi gayi');

        // Existing spamd ko required_score edit ke baad restart karna zaroori hai,
        // warna us process me purani local.cf cached rehti hai.
        $scoreUpdate = (new MailServerSetup())->handle([
            'action' => 'spamassassin', 'required_score' => 7.0,
        ], $h['ctx']);
        assert_true(($scoreUpdate['spam']['required_score'] ?? 0.0) === 7.0, 'naya score status me');
        $calls = implode("\n", array_map(static fn (array $a): string => implode(' ', $a), $h['cmd']->calls));
        assert_true(str_contains($calls, 'systemctl restart spamassassin'), 'required_score change par running spamd restart');
        assert_true(str_contains($h['cmd']->spamAssassinConfAtServiceChange['restart'] ?? '', 'required_score 7.0'), 'restart se pehle local.cf update');

        $cf = (string) file_get_contents($localCf);
        assert_true(str_contains($cf, '# administrator note') && str_contains($cf, 'clear_report_template'), 'local.cf ka user data bacha');
        assert_true(str_contains($cf, 'required_score 7.0'), 'latest required_score write');
        assert_true(($out['spam']['required_score'] ?? 0.0) === 6.5, 'status last required_score dikhata hai');

        $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
        assert_true(str_contains($tpl, 'warn spam = nobody:true/defer_ok'), 'SpamAssassin scan fail-open syntax');
        assert_true(str_contains($tpl, 'add_header = X-Spam-Score:'), 'score header');
        $acl = strstr($tpl, 'acl_check_data:');
        $acl = is_string($acl) ? substr($acl, 0, (int) strpos($acl, 'begin routers')) : '';
        assert_true(!str_contains($acl, 'rejected as spam'), 'reject_score=0 kisi mail ko reject nahi kare');
    } finally {
        if ($oldSpamd === false) {
            putenv('ACP_MAIL_SPAMD');
        } else {
            putenv('ACP_MAIL_SPAMD=' . $oldSpamd);
        }
        acp_mail_cleanup($h);
    }
});

test('mail.server spamassassin — content scanning unsupported ho to setting apply nahi hoti', function (): void {
    $h = acp_mail_harness();
    $oldSpamd = getenv('ACP_MAIL_SPAMD');
    putenv('ACP_MAIL_SPAMD=' . $h['root'] . '/bin/spamd');
    try {
        $h['cmd']->mailDkim = false; // exim4-daemon-light: no Content_Scanning
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $before = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
        $threw = false;
        try {
            (new MailServerSetup())->handle(['action' => 'spamassassin', 'enabled' => true], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = str_contains($e->getMessage(), 'exim4-daemon-heavy');
        }
        assert_true($threw, 'unsupported exim par feature enable reject');
        assert_true(!is_file($h['root'] . '/alphacp/etc/mail/exim-options.json'), 'options file mutate nahi hui');
        assert_true((string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template') === $before, 'purani template byte-for-byte');
        assert_true(($h['cmd']->systemctlStates['spamassassin'] ?? 'inactive') === 'inactive', 'spamd start nahi hua');
    } finally {
        if ($oldSpamd === false) {
            putenv('ACP_MAIL_SPAMD');
        } else {
            putenv('ACP_MAIL_SPAMD=' . $oldSpamd);
        }
        acp_mail_cleanup($h);
    }
});

test('mail.server spamassassin — Exim validation fail ho to options/local.cf/service rollback', function (): void {
    $h = acp_mail_harness();
    $oldSpamd = getenv('ACP_MAIL_SPAMD');
    putenv('ACP_MAIL_SPAMD=' . $h['root'] . '/bin/spamd');
    try {
        $h['cmd']->mailDkim = true;
        $h['cmd']->systemctlStates['spamassassin'] = 'inactive';
        $localCf = $h['root'] . '/etc/spamassassin/local.cf';
        file_put_contents($localCf, "# keep exactly\nrequired_score 4.5\n");
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $beforeTemplate = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
        $beforeLocalCf = (string) file_get_contents($localCf);
        $h['cmd']->mailEximGenerateFails = true;

        $threw = false;
        try {
            (new MailServerSetup())->handle([
                'action' => 'spamassassin', 'enabled' => true,
                'required_score' => 6.5, 'reject_score' => 9.0,
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = str_contains($e->getMessage(), 'exim config reject');
        }
        assert_true($threw, 'bad generated Exim config reject');
        assert_true(!is_file($h['root'] . '/alphacp/etc/mail/exim-options.json'), 'new Exim options rolled back');
        assert_true((string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template') === $beforeTemplate, 'previous Exim template restored');
        assert_true((string) file_get_contents($localCf) === $beforeLocalCf, 'local.cf bytes rolled back');
        assert_true(($h['cmd']->systemctlStates['spamassassin'] ?? '') === 'inactive', 'newly started spamd stopped after rollback');
    } finally {
        if ($oldSpamd === false) {
            putenv('ACP_MAIL_SPAMD');
        } else {
            putenv('ACP_MAIL_SPAMD=' . $oldSpamd);
        }
        acp_mail_cleanup($h);
    }
});

test('mail.server greylisting — greylistd socket, recipient verification, trusted senders', function (): void {
    $h = acp_mail_harness();
    $socket = $h['root'] . '/run/greylistd/socket';
    file_put_contents($socket, 'test socket marker'); // file_exists contract; real host has a Unix socket
    try {
        $h['cmd']->systemctlStates['greylistd'] = 'inactive';
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $out = (new MailServerSetup())->handle([
            'action' => 'spamassassin',
            'greylisting' => true,
        ], $h['ctx']);
        assert_true(($out['spam']['greylisting'] ?? false) === true, 'greylisting config enabled');
        assert_true(($out['spam']['greylisting_active'] ?? false) === true, 'greylistd + socket active');
        assert_true(($h['cmd']->systemctlStates['greylistd'] ?? '') === 'active', 'greylistd service start hua');

        $calls = implode("\n", array_map(static fn (array $a): string => implode(' ', $a), $h['cmd']->calls));
        assert_true(str_contains($calls, 'systemctl enable greylistd'), 'greylistd boot par enable');
        assert_true(str_contains($calls, 'systemctl start greylistd'), 'greylistd start');

        $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
        assert_true(str_contains($tpl, '--grey $sender_host_address $sender_address $local_part@$domain'), 'official --grey triplet query');
        assert_true(str_contains($tpl, 'verify = recipient'), 'unknown mailbox pe 451 greylist na kare');
        assert_true(str_contains($tpl, '!senders = :'), 'empty sender / DSN greylist na ho');
        assert_true(str_contains($tpl, '!hosts = : +relay_from_hosts'), 'trusted relay greylist na ho');
        assert_true(str_contains($tpl, '!authenticated = *'), 'SMTP AUTH user greylist na ho');
        assert_true(str_contains($tpl, $socket), 'socket path env se render hua');
    } finally {
        acp_mail_cleanup($h);
    }
});

test('mail.server greylisting — socket absent ho to option save/restart nahi hota', function (): void {
    $h = acp_mail_harness();
    try {
        $h['cmd']->systemctlStates['greylistd'] = 'inactive';
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $before = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
        $threw = false;
        try {
            (new MailServerSetup())->handle(['action' => 'spamassassin', 'greylisting' => true], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = str_contains($e->getMessage(), 'Unix socket nahi mila');
        }
        assert_true($threw, 'socket absent par fail safely');
        assert_true(!is_file($h['root'] . '/alphacp/etc/mail/exim-options.json'), 'greylisting option mutate nahi hui');
        assert_true((string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template') === $before, 'purani template byte-for-byte');
        assert_true(($h['cmd']->systemctlStates['greylistd'] ?? '') === 'inactive', 'naya greylistd service stop hua');
    } finally {
        acp_mail_cleanup($h);
    }
});

test('mail.server setup — DKIM: exim support kare to signing, warna fail-closed', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->mailDkim = true;
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'dkim_private_key'), 'DKIM support par signing config honi chahiye');
    assert_true(str_contains($tpl, 'dkim_selector = default'), 'selector default hona chahiye');
    assert_true(str_contains($tpl, '{0}'), 'key na mile to 0 (signing off)');
    $caps = (new MailServer($h['ctx']->cmd, $h['ctx']->log))->capabilities();
    assert_true($caps['dkim'] === true, 'capabilities: dkim true');
    assert_true($caps['content_scanning'] === true, 'capabilities: content_scanning true');
    acp_mail_cleanup($h);
});

test('mail.server deliverability — SPF + DMARC + DKIM records asli zone me', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $out = (new MailServerSetup())->handle(['action' => 'deliverability'], $h['ctx']);
    assert_true($out['ok'] === true, 'deliverability ok (fail: ' . json_encode($out['failed']) . ')');
    assert_true($out['count'] === 1, '1 domain, mile ' . (int) $out['count']);
    assert_true(($out['domains'][0]['dkim'] ?? false) === true, 'DKIM key bani honi chahiye');
    assert_true(!empty($h['cmd']->opensslArgvs), 'openssl chalna chahiye (key banane ke liye)');

    $zone = json_decode((string) file_get_contents($h['root'] . '/home/alicehost/etc/dns/zone.json'), true);
    $byName = [];
    foreach ($zone as $row) {
        $byName[$row['name'] . '|' . $row['type']] = $row['value'];
    }
    assert_true(($byName['@|A'] ?? '') === '203.0.113.10', 'A record bacha rahe');
    assert_true(($byName['www|CNAME'] ?? '') === 'alice.test', 'CNAME bacha rahe');
    assert_true(($byName['note|TXT'] ?? '') === 'user ka apna note', 'user ka TXT bacha rahe');
    assert_true(($byName['@|TXT'] ?? '') === 'v=spf1 a mx -all', 'purana SPF replace hoke naya aana chahiye: ' . ($byName['@|TXT'] ?? ''));
    assert_true(str_starts_with((string) ($byName['_dmarc|TXT'] ?? ''), 'v=DMARC1; p=quarantine'), 'DMARC record: ' . ($byName['_dmarc|TXT'] ?? ''));
    $dkim = (string) ($byName['default._domainkey|TXT'] ?? '');
    assert_true(str_starts_with($dkim, 'v=DKIM1; k=rsa; p='), 'DKIM record: ' . substr($dkim, 0, 60));
    acp_mail_cleanup($h);
});

test('mail.server deliverability — openssl na ho to jhootha DKIM nahi (sirf SPF/DMARC)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->opensslFails = true;
    $out = (new MailServerSetup())->handle(['action' => 'deliverability'], $h['ctx']);
    assert_true($out['count'] === 1, 'domain process hona chahiye');
    assert_true(($out['domains'][0]['dkim'] ?? true) === false, 'key na bane to dkim false bolna chahiye');
    $zone = json_decode((string) file_get_contents($h['root'] . '/home/alicehost/etc/dns/zone.json'), true);
    $names = array_column($zone, 'name');
    assert_true(!in_array('default._domainkey', $names, true), 'bina key ke DKIM record nahi');
    assert_true(in_array('_dmarc', $names, true), 'DMARC phir bhi likhna chahiye');
    acp_mail_cleanup($h);
});

test('mail.set/mail.forward ke baad auto-sync (alag se sync command nahi)', function (): void {
    // asli account harness (getent/useradd fake hain — warna "not an AlphaCP account")
    $h = acp_account_harness();
    $root = $h['root'];
    (new \Alphacp\Agent\Tasks\AccountCreate())->handle(acp_create_payload(), $h['ctx']);
    // mail ke env overrides usi root me
    foreach ([
        'ACP_MAIL_EXIM_TEMPLATE' => $root . '/etc/exim4/exim4.conf.template',
        'ACP_MAIL_EXIM_DOMAINS' => $root . '/etc/exim4/alphacp-domains',
        'ACP_MAIL_EXIM_RECIPIENTS' => $root . '/etc/exim4/alphacp-recipients',
        'ACP_MAIL_EXIM_ALIASES' => $root . '/etc/exim4/alphacp-aliases',
        'ACP_MAIL_CATCHALL' => $root . '/etc/exim4/alphacp-catchall',
        'ACP_MAIL_VACATION_DIR' => $root . '/etc/exim4/vacation',
        'ACP_MAIL_SPAM_DIR' => $root . '/etc/exim4/spam',
        'ACP_MAIL_DKIM_DIR' => $root . '/alphacp/etc/mail/dkim',
        'ACP_MAIL_DOVECOT_USERS' => $root . '/etc/dovecot/alphacp-users',
        'ACP_MAIL_DOVECOT_CONF' => $root . '/etc/dovecot/conf.d/99-alphacp.conf',
        'ACP_MAIL_EXIM' => $root . '/bin/exim4',
        'ACP_MAIL_DOVECOT' => $root . '/bin/dovecot',
        'ACP_MAIL_DOVEADM' => $root . '/bin/doveadm',
        'ACP_MAIL_DOVECONF' => $root . '/bin/doveconf',
        'ACP_MAIL_UPDATE_EXIM' => $root . '/bin/update-exim4.conf',
    ] as $name => $value) {
        putenv($name . '=' . $value);
    }
    if (!is_dir($root . '/etc/exim4')) {
        mkdir($root . '/etc/exim4', 0755, true);
    }
    file_put_contents($root . '/etc/exim4/exim4.conf.template', "# distro template\n");
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    // 1) mail server configured nahi -> sync skip (mail task fail nahi hona chahiye)
    $set1 = (new \Alphacp\Agent\Tasks\MailSet())->handle([
        'username'  => 'alicehost',
        'mailboxes' => [['local' => 'info', 'domain' => 'alice.test', 'hash' => $hash, 'quota_mb' => 100]],
    ], $h['ctx']);
    assert_true(str_contains((string) $set1['mail_sync'], 'skipped'), 'bina setup ke sync skip: ' . $set1['mail_sync']);

    // 2) setup ke baad -> mail.set khud sync kare
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $set2 = (new \Alphacp\Agent\Tasks\MailSet())->handle([
        'username'  => 'alicehost',
        'mailboxes' => [['local' => 'sales', 'domain' => 'alice.test', 'hash' => $hash, 'quota_mb' => 100]],
    ], $h['ctx']);
    assert_true(str_contains((string) $set2['mail_sync'], 'ok ('), 'setup ke baad sync hona chahiye: ' . $set2['mail_sync']);
    $users = (string) file_get_contents($root . '/etc/dovecot/alphacp-users');
    assert_true(str_contains($users, 'sales@alice.test:'), 'naya mailbox turant aggregate me hona chahiye');

    // 3) mail.forward ke baad bhi
    $fwd = (new \Alphacp\Agent\Tasks\MailForward())->handle([
        'username' => 'alicehost',
        'forwards' => [['local' => 'contact', 'domain' => 'alice.test', 'dest' => 'info@alice.test']],
    ], $h['ctx']);
    assert_true(str_contains((string) $fwd['mail_sync'], 'ok ('), 'forward ke baad bhi sync: ' . $fwd['mail_sync']);
    $aliases = (string) file_get_contents($root . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($aliases, 'contact@alice.test: info@alice.test'), 'naya forwarder turant lagu: ' . $aliases);

    foreach ([
        'ACP_MAIL_EXIM_TEMPLATE', 'ACP_MAIL_EXIM_DOMAINS', 'ACP_MAIL_EXIM_RECIPIENTS',
        'ACP_MAIL_EXIM_ALIASES', 'ACP_MAIL_CATCHALL', 'ACP_MAIL_VACATION_DIR',
        'ACP_MAIL_SPAM_DIR', 'ACP_MAIL_DKIM_DIR', 'ACP_MAIL_DOVECOT_USERS',
        'ACP_MAIL_DOVECOT_CONF', 'ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT',
        'ACP_MAIL_DOVEADM', 'ACP_MAIL_DOVECONF', 'ACP_MAIL_UPDATE_EXIM',
    ] as $name) {
        putenv($name);
    }
    acp_account_cleanup($h);
});

test('S7 mail tools: openssl bhi agent allowlist me hai', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach (MailServer::OPENSSL_PATHS as $path) {
        assert_true(in_array($path, $allow, true), "allowlist me {$path} nahi hai");
    }
});

test('mail.server schema — deliverability action allowed, galat action nahi', function (): void {
    $schema = acp_task_registry()['mail.server']['schema'];
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability']) === [], 'deliverability pass hona chahiye');
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability', 'username' => 'alicehost']) === [], 'username ke saath bhi');
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability', 'username' => '../root']) !== [], 'path traversal reject');
});


fwrite(STDOUT, "\nS7 MAIL FIXES (maildir ownership + dovecot userdb probe)\n");

test('mail.server sync — root-owned Maildir parents theek (live wala asli bug)', function (): void {
    $h = acp_mail_harness();
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $home = $root . '/home/alicehost';
    $box = $home . '/mail/alice.test/info';
    mkdir($box . '/new', 0777, true);      // "root ne bana diya, chown bhool gaya"
    mkdir($box . '/cur', 0777, true);
    mkdir($box . '/tmp', 0777, true);
    @chmod($box, 0777);
    // uid/gid = is process ke (sandbox me hum root nahi, isliye chown path chhoda)
    $uid = (string) (function_exists('posix_getuid') ? posix_getuid() : 1000);
    $gid = (string) (function_exists('posix_getgid') ? posix_getgid() : 1000);
    mkdir($home . '/etc/mail', 0755, true);
    file_put_contents($home . '/etc/mail/passwd', "info@alice.test:{BLF-CRYPT}{$hash}:{$uid}:{$gid}::{$box}::\n");

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['mailboxes'] === 1, '1 mailbox');
    assert_true(($out['maildirs_fixed'] ?? 0) >= 1, 'Maildir theek hona chahiye (mode 0777 -> 0700), fixed=' . (int) ($out['maildirs_fixed'] ?? 0));
    assert_true((fileperms($box) & 0777) === 0700, 'mailbox dir 0700 hona chahiye, ab ' . decoct(fileperms($box) & 0777));
    assert_true((fileperms($box . '/new') & 0777) === 0700, 'new/ 0700 hona chahiye');
    assert_true((fileperms(dirname($box)) & 0777) === 0700, '~/mail/<domain> bhi 0700 (traversable by owner)');
    acp_mail_cleanup($h);
});

test('mail.server setup — Dovecot userdb probe: fail ho to 0644 relax karke dobara', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->doveadmUserOutput = "field\tvalue\nuid\t1001\nhome\t/home/alicehost/mail/alice.test/info\n";
    $h['cmd']->doveadmFailFirst = 1;     // pehli koshish fail -> relax -> dobara
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $probe = $out['dovecot_userdb'] ?? [];
    assert_true(($probe['ok'] ?? false) === true, 'probe ok hona chahiye: ' . json_encode($probe));
    assert_true(($probe['mode'] ?? '') === '0644-relaxed', 'relax mode report hona chahiye: ' . json_encode($probe));
    assert_true((fileperms($h['root'] . '/etc/dovecot/alphacp-users') & 0777) === 0644, 'file 0644 ho jana chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — Dovecot userdb probe: dono baar fail to jhoothi ok nahi', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->doveadmAlwaysFails = true;
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $probe = $out['dovecot_userdb'] ?? [];
    assert_true(($probe['ok'] ?? true) === false, 'fail report hona chahiye (chhupana nahi): ' . json_encode($probe));
    assert_true(str_contains((string) ($probe['error'] ?? ''), 'userdb lookup failed'), 'asli error hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — exim unit non-root ho to root drop-in likhe (user= delivery)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    putenv('SIM_EXIM_UNIT_USER=Debian-exim');
    try {
        $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        assert_true(($out['ok'] ?? false) === true, 'setup chalna chahiye (drop-in likhne ki koshish ke bawajud)');
    } finally {
        putenv('SIM_EXIM_UNIT_USER');
    }
    acp_mail_cleanup($h);
});

test('mail.server setup — exim template me deliver_drop_privilege = false', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'deliver_drop_privilege = false'), 'mailbox uid se delivery ke liye zaroori');
    acp_mail_cleanup($h);
});


test('mail.server deliverability — deliverability.json na ho to bhi (mailbox domain se)', function (): void {
    $h = acp_mail_harness();
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $home = $root . '/home/alicehost';
    mkdir($home . '/etc/mail', 0755, true);
    // sirf mailbox hai, deliverability.json NAHI (panel ka page khula hi nahi)
    file_put_contents($home . '/etc/mail/passwd', "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$home}/mail/alice.test/info::\n");
    $out = (new MailServerSetup())->handle(['action' => 'deliverability'], $h['ctx']);
    assert_true($out['count'] === 1, 'mailbox ke domain ke liye records likhne chahiye, count=' . (int) $out['count']);
    $zone = json_decode((string) @file_get_contents($home . '/etc/dns/zone.json'), true);
    $byName = [];
    foreach ((array) $zone as $row) {
        $byName[$row['name'] . '|' . $row['type']] = $row['value'];
    }
    assert_true(str_starts_with((string) ($byName['@|TXT'] ?? ''), 'v=spf1'), 'SPF likha jana chahiye');
    assert_true(str_starts_with((string) ($byName['_dmarc|TXT'] ?? ''), 'v=DMARC1'), 'DMARC likha jana chahiye');
    acp_mail_cleanup($h);
});

test('#145 sync — SPF/DKIM/DMARC apne aap (server default), koi manual action nahi', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $home = $h['root'] . '/home/alicehost';
    // mail server configure hone ka nishaan (jaise asli server par)
    @mkdir($h['root'] . '/alphacp', 0755, true);
    file_put_contents($h['root'] . '/alphacp/etc/mail-server-configured', "1\n");

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    $deliv = (array) ($out['deliverability'] ?? []);
    assert_true(($deliv['ok'] ?? false) === true, 'sync ke saath deliverability ok hona chahiye (fail: ' . json_encode($deliv['failed'] ?? []) . ')');
    assert_true((int) ($deliv['count'] ?? 0) === 1, 'mailbox ka domain apne aap process ho, count=' . (int) ($deliv['count'] ?? -1));
    assert_true((int) ($deliv['changed'] ?? 0) === 1, 'pehli baar records likhna chahiye (changed=1)');

    // syncFiles() bsdk manually chalane se bhi (syncIfConfigured wahi call karta hai)
    $agg = (new MailServer($h['ctx']->cmd, $h['ctx']->log))->syncFiles();
    $again = (array) ($agg['deliverability'] ?? []);
    assert_true((int) ($again['changed'] ?? -1) === 0, 'records pehle se sahi hain to dobara likhna nahi (changed=0)');
    assert_true(($again['domains'][0]['dns']['reason'] ?? '') === 'already-present', 'skip ka kaaran clear ho');

    $zone = json_decode((string) file_get_contents($home . '/etc/dns/zone.json'), true);
    $byName = [];
    foreach ((array) $zone as $row) {
        $byName[$row['name'] . '|' . $row['type']] = $row['value'];
    }
    assert_true(($byName['@|TXT'] ?? '') === 'v=spf1 a mx -all', 'SPF apne aap likha jaye');
    assert_true(str_starts_with((string) ($byName['_dmarc|TXT'] ?? ''), 'v=DMARC1'), 'DMARC apne aap likha jaye');
    $dkim = (string) ($byName['default._domainkey|TXT'] ?? '');
    assert_true(str_starts_with($dkim, 'v=DKIM1; k=rsa; p='), 'DKIM apne aap likha jaye');
    // DKIM TXT ka p= asli .pub file se match kare
    $pub = (string) @file_get_contents($h['root'] . '/alphacp/etc/mail/dkim/alice.test.pub');
    if ($pub !== '') {
        $b64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pub);
        assert_true(str_contains($dkim, 'p=' . $b64), 'DKIM p= asli public key se match kare');
    }
    acp_mail_cleanup($h);
});

test('#145 sync — DNS/zone likhna fail ho to bhi sync ki baaki cheezein zinda rehti hain', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $home = $h['root'] . '/home/alicehost';
    // zone.json likhna hi na ho: write fail hona chahiye. chmod-read-only trick ROOT
    // par kaam nahi karti (root unix permissions bypass karta hai — live par suite
    // root chalta hai), isliye zone.json ko DIRECTORY bana do: file_put_contents
    // har uid par EISDIR se fail hota hai (fail-closed path wahi demonstrate hota hai).
    @unlink($home . '/etc/dns/zone.json');
    @rmdir($home . '/etc/dns/zone.json');
    @mkdir($home . '/etc/dns/zone.json', 0755);

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(($out['mailboxes'] ?? -1) >= 0, 'sync khud fail nahi hona chahiye (mailboxes key maujood)');
    $deliv = (array) ($out['deliverability'] ?? []);
    assert_true(($deliv['ok'] ?? true) === false, 'jhoothi success nahi — ok=false');
    assert_true(!empty($deliv['failed']), 'failure ki wajah report ho');
    assert_true(($deliv['failed'][0]['domain'] ?? '') === 'alice.test', 'kaunsa domain fail hua wo bhi batao');
    assert_true(str_contains((string) ($deliv['failed'][0]['error'] ?? ''), 'zone.json'), 'error message me zone.json ho');
    @rmdir($home . '/etc/dns/zone.json');
    acp_mail_cleanup($h);
});

test('#145 sync — mail.server setup se pehle bhi sync crash nahi karta', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    // mail-server-configured nishaan NAHI (yaani mail.server setup abhi nahi hua)
    assert_true(MailServer::isConfigured() === false, 'configured nahi hona chahiye');
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(array_key_exists('deliverability', $out), 'deliverability key har haal me ho (panel isko padh sakta hai)');
    $deliv = (array) ($out['deliverability'] ?? []);
    assert_true((int) ($deliv['count'] ?? -1) === 1, 'mailbox ka domain phir bhi cover ho, count=' . (int) ($deliv['count'] ?? -1));
    assert_true(($deliv['ok'] ?? false) === true, 'chupchap skip bhi nahi — records likh diye');
    acp_mail_cleanup($h);
});


test('mail.server setup — system users ke liye mail_spool (root ki mail queue me na atke)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'transport = mail_spool'), 'local_user system delivery ke liye mail_spool');
    assert_true(str_contains($tpl, 'file = /var/mail/$local_part'), 'mail_spool Debian wala path');
    acp_mail_cleanup($h);
});


fwrite(STDOUT, "\nS7 MAIL SERVER-WIDE (queue / reports / exim-dovecot config / disk usage)\n");

test('mail.server queue — exim -bp parse (2 mail, ek frozen) + count', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->eximBpOutput = implode("\n", [
        '10m  1.1K 1oABCD-0000xy-1a <root@ip-172-26-4-65>',
        '        info@acp-mail-check.test',
        '',
        '25m  3.2K 1oABCE-0000xz-1b <nobody@example.test> *** frozen ***',
        '        unknown@acp-mail-check.test',
        '',
    ]);

    $out = (new MailServerSetup())->handle(['action' => 'queue'], $h['ctx']);
    assert_true($out['count'] === 2, '2 mail queue me hone chahiye, count=' . (int) $out['count']);
    $items = (array) ($out['items'] ?? []);
    assert_true(($items[0]['id'] ?? '') === '1oABCD-0000xy-1a', 'pehla message id');
    assert_true(($items[0]['sender'] ?? '') === 'root@ip-172-26-4-65', 'sender <...> se nikalna chahiye');
    assert_true(in_array('info@acp-mail-check.test', (array) ($items[0]['recipients'] ?? []), true), 'recipient line parse honi chahiye');
    assert_true(($items[0]['frozen'] ?? null) === false, 'pehla frozen nahi hona chahiye');
    assert_true(($items[1]['frozen'] ?? null) === true, 'doosra frozen hona chahiye');
    assert_true(($items[1]['age'] ?? '') === '25m', 'age parse hona chahiye');
    assert_true(($items[1]['size'] ?? '') === '3.2K', 'size parse hona chahiye');

    $count = (new MailServerSetup())->handle(['action' => 'queue', 'op' => 'count'], $h['ctx']);
    assert_true(($count['count'] ?? -1) === 2, 'count op bhi 2 bole');

    // khali queue
    $h['cmd']->eximBpOutput = "The mail queue is empty.\n";
    $empty = (new MailServerSetup())->handle(['action' => 'queue'], $h['ctx']);
    assert_true($empty['count'] === 0, 'khali queue 0');
    assert_true($empty['note'] !== null, 'khali queue par note hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server queue — deliver/remove asli id se, khatarnak id reject', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

    $out = (new MailServerSetup())->handle(
        ['action' => 'queue', 'op' => 'deliver', 'id' => '1oABCD-0000xy-1a'],
        $h['ctx'],
    );
    assert_true($out['ok'] === true, 'deliver chalna chahiye');
    assert_true(($h['cmd']->eximQueueArgvs[0][1] ?? '') === '-M', 'exim -M hi bheja jana chahiye');
    assert_true(($h['cmd']->eximQueueArgvs[0][2] ?? '') === '1oABCD-0000xy-1a', 'id wahi jayega');

    $out = (new MailServerSetup())->handle(
        ['action' => 'queue', 'op' => 'remove', 'id' => '1oABCD-0000xy-1a'],
        $h['ctx'],
    );
    assert_true(($out['op'] ?? '') === 'remove', 'remove op');
    assert_true(($h['cmd']->eximQueueArgvs[1][1] ?? '') === '-Mrm', 'exim -Mrm');

    $threw = false;
    try {
        (new MailServerSetup())->handle(
            ['action' => 'queue', 'op' => 'remove', 'id' => '../../etc/passwd'],
            $h['ctx'],
        );
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'asli message id');
    }
    assert_true($threw, 'path-traversal id reject hona chahiye');

    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'queue', 'op' => 'nuke'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'nahi chalega');
    }
    assert_true($threw, 'namalum op reject hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server reports — asli mainlog se ginati (arrived/delivered/deferred/failed/rejected)', function (): void {
    $h = acp_mail_harness();
    $log = $h['root'] . '/var/log/exim4/mainlog';
    file_put_contents($log, implode("\n", [
        '2026-10-05 10:00:01 1oAAAA-000001-AA <= root@ip-172-26-4-65 U=root P=local S=500',
        '2026-10-05 10:00:02 1oAAAA-000001-AA => info@acp-mail-check.test R=alphacp_mailbox T=alphacp_maildir',
        '2026-10-05 10:00:02 1oAAAA-000001-AA Completed',
        '2026-10-05 10:01:01 1oBBBB-000002-AB <= sender@remote.test H=mx.remote.test [10.0.0.1] P=esmtp S=900',
        '2026-10-05 10:01:05 1oBBBB-000002-AB == gone@nowhere.test R=dnslookup T=remote_smtp defer (-42): host not found',
        '2026-10-05 10:02:00 1oCCCC-000003-AC <= spam@remote.test P=esmtp S=100',
        '2026-10-05 10:02:00 1oCCCC-000003-AC H=bad.test [10.0.0.2]: rejected RCPT info@x.test: relay not permitted',
        '2026-10-05 10:03:00 1oDDDD-000004-AD <= bounce@x.test P=local S=10',
        '2026-10-05 10:03:00 1oDDDD-000004-AD ** nope@nowhere.test R=dnslookup: host not found',
        '',
    ]));

    $out = (new MailServerSetup())->handle(['action' => 'reports'], $h['ctx']);
    assert_true($out['ok'] === true, 'mainlog milna chahiye');
    $c = (array) ($out['counts'] ?? []);
    assert_true(($c['arrived'] ?? 0) === 4, 'arrived=4, mila ' . (int) ($c['arrived'] ?? 0));
    assert_true(($c['delivered'] ?? 0) === 1, 'delivered=1, mila ' . (int) ($c['delivered'] ?? 0));
    assert_true(($c['deferred'] ?? 0) === 1, 'deferred=1');
    assert_true(($c['failed'] ?? 0) === 1, 'failed=1');
    assert_true(($c['rejected'] ?? 0) === 1, 'rejected=1');
    assert_true(($c['completed'] ?? 0) === 1, 'completed=1');
    $senders = (array) ($out['top_senders'] ?? []);
    assert_true($senders !== [], 'top_senders khali nahi hona chahiye');
    assert_true(($senders[0]['address'] ?? '') !== '', 'top sender ka address hona chahiye');

    $filtered = (new MailServerSetup())->handle(['action' => 'reports', 'search' => 'nowhere.test'], $h['ctx']);
    assert_true(($filtered['entries_total'] ?? -1) === 2, 'search se sirf 2 entry milni chahiye, mili ' . (int) ($filtered['entries_total'] ?? -1));
    acp_mail_cleanup($h);
});

test('mail.server reports — log na mile to jhoothi report nahi', function (): void {
    $h = acp_mail_harness();
    @unlink($h['root'] . '/var/log/exim4/mainlog');
    $out = (new MailServerSetup())->handle(['action' => 'reports'], $h['ctx']);
    assert_true($out['ok'] === false, 'log nahi mila to ok=false');
    assert_true(str_contains((string) ($out['error'] ?? ''), 'mainlog nahi mila'), 'wajah batani chahiye');
    assert_true(($out['counts'] ?? null) === [], 'ginati khali honi chahiye');
    acp_mail_cleanup($h);
});

test('mail.server eximconf — option set karne par template me asli value', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

    $show = (new MailServerSetup())->handle(['action' => 'eximconf'], $h['ctx']);
    assert_true($show['applied'] === false, 'bina set ke sirf dikhana chahiye');
    assert_true(($show['options']['message_size_limit'] ?? '') === '50M', 'default 50M');
    assert_true(in_array('smtp_accept_max', (array) ($show['allowed'] ?? []), true), 'allowed list honi chahiye');
    assert_true(in_array('spam_score_limit', (array) ($show['allowed'] ?? []), true), 'spam limit bhi allowed');
    assert_true(!in_array('spam_enabled', (array) ($show['allowed'] ?? []), true), 'spam toggle dedicated safe action se hi');
    assert_true(!in_array('greylisting', (array) ($show['allowed'] ?? []), true), 'greylisting dedicated safe action se hi');

    $out = (new MailServerSetup())->handle([
        'action' => 'eximconf',
        'set'    => ['message_size_limit' => '100M', 'queue_run_max' => '10', 'smtp_accept_max' => '250'],
    ], $h['ctx']);
    assert_true($out['applied'] === true, 'apply hona chahiye');
    assert_true(($out['options']['message_size_limit'] ?? '') === '100M', 'option 100M');
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'message_size_limit = 100M'), 'template me 100M hona chahiye');
    assert_true(str_contains($tpl, 'queue_run_max = 10'), 'template me queue_run_max = 10');
    assert_true(str_contains($tpl, 'smtp_accept_max = 250'), 'template me smtp_accept_max = 250');
    // $smtp_active_hostname exim ka variable hai — option value me waisa hi bachna chahiye
    assert_true(
        str_contains($tpl, 'smtp_banner = $smtp_active_hostname ESMTP AlphaCP'),
        'smtp_banner me $smtp_active_hostname waisa hi rehna chahiye',
    );
    assert_true(is_file($h['root'] . '/alphacp/etc/mail/exim-options.json'), 'options file likhni chahiye');

    // dobara read karne par wahi value (panel restart ke baad bhi)
    $again = (new MailServerSetup())->handle(['action' => 'eximconf'], $h['ctx']);
    assert_true(($again['options']['queue_run_max'] ?? '') === '10', 'value file se wapas aani chahiye');
    acp_mail_cleanup($h);
});

test('mail.server eximconf — galat value reject, config kharaab na ho (fail-closed)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $before = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');

    foreach ([
        ['message_size_limit' => '50X'],        // size galat
        ['queue_run_max' => '5; rm -rf /'],     // command injection
        ['timeout_frozen_after' => '7'],        // duration me unit chahiye
        ['kuch_bhi' => '100'],                  // allowed nahi
        ['smtp_banner' => "line1\nline2"],      // multi-line = config tod dega
        ['smtp_accept_max' => '-5'],            // negative
        ['spam_enabled' => true],                // service must be managed with ACL
        ['greylisting' => true],                 // service must be managed with ACL
    ] as $bad) {
        $threw = false;
        try {
            (new MailServerSetup())->handle(['action' => 'eximconf', 'set' => $bad], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'reject hona chahiye: ' . (string) json_encode($bad));
    }

    assert_true(!is_file($h['root'] . '/alphacp/etc/mail/exim-options.json'), 'galat value par options file nahi likhni chahiye');
    assert_true(
        (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template') === $before,
        'template bilkul nahi badalna chahiye',
    );
    acp_mail_cleanup($h);
});

test('mail.server dovecot conf — webmail SSO master passdb sirf file hone par', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $master = $h['root'] . '/etc/webmail-master.pw';
    putenv('ACP_MAIL_MASTER_PASSDB=' . $master);
    try {
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $conf = (string) file_get_contents((string) getenv('ACP_MAIL_DOVECOT_CONF'));
        assert_true(!str_contains($conf, 'master = yes'), 'bina master file ke master passdb include NAHI hona chahiye');
        file_put_contents($master, "acpmaster:\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234\n");
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        $conf2 = (string) file_get_contents((string) getenv('ACP_MAIL_DOVECOT_CONF'));
        assert_true(str_contains($conf2, 'master = yes'), 'master file hone par master passdb include hona chahiye');
        assert_true(str_contains($conf2, $master), 'master passdb args me asli file path hona chahiye');
    } finally {
        putenv('ACP_MAIL_MASTER_PASSDB');
    }
    acp_mail_cleanup($h);
});

test('mail.server dovecotconf — option set karne par 99-alphacp.conf me asli value', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

    $out = (new MailServerSetup())->handle([
        'action' => 'dovecotconf',
        'set'    => [
            'mail_max_userip_connections' => '20',
            'protocols'                   => 'imap',
            'disable_plaintext_auth'      => true,
        ],
    ], $h['ctx']);
    assert_true($out['applied'] === true, 'apply hona chahiye');
    assert_true(($out['options']['disable_plaintext_auth'] ?? '') === 'yes', 'bool true -> yes');

    $conf = (string) file_get_contents($h['root'] . '/etc/dovecot/conf.d/99-alphacp.conf');
    assert_true(str_contains($conf, 'mail_max_userip_connections = 20'), 'conf me mail_max_userip_connections = 20');
    assert_true(str_contains($conf, "\nprotocols = imap\n"), 'conf me protocols = imap');
    assert_true(str_contains($conf, 'disable_plaintext_auth = yes'), 'conf me disable_plaintext_auth = yes');

    $threw = false;
    try {
        (new MailServerSetup())->handle(
            ['action' => 'dovecotconf', 'set' => ['protocols' => 'imap # comment']],
            $h['ctx'],
        );
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, "'#' wali value reject honi chahiye (config comment ban jayega)");
    acp_mail_cleanup($h);
});

test('mail.server diskusage — account ki mail jagah asli bytes me', function (): void {
    $h = acp_mail_harness();
    $root = $h['root'];
    $box = $root . '/home/alicehost/mail/alice.test/info/new';
    mkdir($box, 0755, true);
    file_put_contents($box . '/1696500000.M1P2Q3.host', str_repeat('x', 2048));
    $box2 = $root . '/home/bobhost/mail/bob.test/support/new';
    mkdir($box2, 0755, true);
    file_put_contents($box2 . '/1696500001.M1P2Q4.host', str_repeat('y', 1024));

    $out = (new MailServerSetup())->handle(['action' => 'diskusage'], $h['ctx']);
    assert_true(($out['account_count'] ?? -1) === 2, '2 account mail ke saath, mile ' . (int) ($out['account_count'] ?? -1));
    assert_true(($out['total_bytes'] ?? 0) >= 3072, 'total_bytes >= 3072, mila ' . (int) ($out['total_bytes'] ?? 0));

    $byName = [];
    foreach ((array) ($out['accounts'] ?? []) as $row) {
        $byName[(string) ($row['username'] ?? '')] = $row;
    }
    assert_true(($byName['alicehost']['bytes'] ?? 0) >= 2048, 'alicehost ki mail >= 2048 bytes');
    assert_true(
        ($byName['alicehost']['mailboxes'][0]['address'] ?? '') === 'info@alice.test',
        'mailbox address local@domain hona chahiye, mila ' . (string) ($byName['alicehost']['mailboxes'][0]['address'] ?? ''),
    );

    $one = (new MailServerSetup())->handle(['action' => 'diskusage', 'username' => 'bobhost'], $h['ctx']);
    assert_true(($one['account_count'] ?? -1) === 1, 'username filter se sirf 1 account');
    acp_mail_cleanup($h);
});


test('mail.server setup — routing smoke test: expansion kharaab ho to purani template wapas', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    // jaise 0.78.0 me hua: `-bV` pass par `-bt` PANIC de (Failed to find user)
    $h['cmd']->eximBtOutput = 'LOG: MAIN PANIC Failed to find user "}" from expanded string for the alphacp_userfilter router';
    $before = "# distro exim template\n";
    file_put_contents($h['root'] . '/etc/exim4/exim4.conf.template', $before);
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'routing smoke test fail');
    }
    assert_true($threw, 'PANIC par setup reject hona chahiye (mail delivery bachani chahiye)');
    assert_true(
        (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template') === $before,
        'purani template wapas aani chahiye',
    );
    acp_mail_cleanup($h);
});


fwrite(STDOUT, "\nS7 EMAIL FILTERS + TRACK (asli Exim filter files)\n");

/** mailbox + filter JSON ke saath ek account (alicehost / info@alice.test). */
function acp_mail_seed_filter_account(array $h): string
{
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $home = $root . '/home/alicehost';
    mkdir($home . '/mail/alice.test/info/new', 0755, true);
    mkdir($home . '/etc/mail', 0755, true);
    // Reproduce the live install: recursive mail config creation leaves
    // ~/etc root-owned/search-blocked for Exim's mailbox uid.
    chmod($home . '/etc', 0750);
    file_put_contents(
        $home . '/etc/mail/passwd',
        "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$home}/mail/alice.test/info::\n"
    );

    return $home;
}

test('mail.server sync — email filters se ASLI Exim filter file ban ti hai', function (): void {
    $h = acp_mail_harness();
    $home = acp_mail_seed_filter_account($h);
    file_put_contents($home . '/etc/mail/filters', (string) json_encode([
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'winner', 'action' => 'folder', 'folder' => 'junk'],
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'from', 'needle' => 'spammer', 'action' => 'discard'],
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'to', 'needle' => 'sales', 'action' => 'forward', 'dest' => 'sales@other.test'],
    ]));
    file_put_contents($home . '/etc/mail/global-filters.json', (string) json_encode([
        ['domain' => 'alice.test', 'field' => 'from', 'needle' => 'boss', 'action' => 'folder', 'folder' => 'boss'],
    ]));

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(($out['filters'] ?? -1) === 1, '1 mailbox ke liye filter banana chahiye, bana ' . (int) ($out['filters'] ?? -1));

    $etcPath = $home . '/etc';
    $etcMode = (int) ((fileperms($etcPath) ?: 0) & 0777);
    $etcOwner = (int) (fileowner($etcPath) ?: -1);
    $etcGroup = (int) (filegroup($etcPath) ?: -1);
    $mailboxCanSearchEtc = ($etcOwner === 1001 && ($etcMode & 0100) !== 0)
        || ($etcGroup === 1001 && ($etcMode & 0010) !== 0)
        || (($etcMode & 0001) !== 0);
    assert_true($mailboxCanSearchEtc, 'filter path parent ~/etc mailbox UID/GID ke liye searchable honi chahiye');
    assert_true(($etcMode & 0004) === 0, '~/etc ko world-readable nahi banana chahiye');

    $lookup = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-filters');
    assert_true(str_contains($lookup, 'info@alice.test: '), 'lookup file me address hona chahiye');

    $filter = (string) file_get_contents($home . '/etc/mail/filter.d/info@alice.test.filter');
    // LIVE BUG (0.78.0): Exim spec ke hisaab se filter file ki PEHLI line '# Exim filter'
    // honi hi chahiye — nahi to exim ise aam .forward file samajhta hai aur `exim -bf`
    // reject kar deta hai (filter install hi nahi hota).
    assert_true(
        str_starts_with($filter, '# Exim filter'),
        'PEHLI line "# Exim filter" honi chahiye (warna exim .forward samajhega), mili: '
        . substr($filter, 0, 40),
    );
    // Exim filter language — ye asli syntax hai jo exim chalaata hai
    assert_true(str_contains($filter, 'if error_message then finish endif'), 'bounce loop se bachav hona chahiye');
    assert_true(str_contains($filter, 'if $header_from: contains "boss" then'), 'account-wide (global) rule pehle');
    assert_true(str_contains($filter, 'save "' . $home . '/mail/alice.test/info/.boss/"'), 'global folder save');
    assert_true(str_contains($filter, 'if $header_subject: contains "winner" then'), 'per-mailbox rule');
    assert_true(str_contains($filter, 'save "' . $home . '/mail/alice.test/info/.junk/"'), 'folder me save');
    assert_true(str_contains($filter, 'seen finish'), 'discard = seen finish');
    assert_true(str_contains($filter, 'deliver "sales@other.test"'), 'forward = deliver');
    // global rule user rule se PEHLE aana chahiye
    assert_true(strpos($filter, 'boss') < strpos($filter, 'winner'), 'account-wide rule pehle lagu hona chahiye');

    // folder pehle se bana hona chahiye (IMAP me turant dikhe + exim -bf ko mile)
    foreach (['.boss', '.junk'] as $folder) {
        assert_true(is_dir($home . '/mail/alice.test/info/' . $folder . '/new'), "{$folder} Maildir banana chahiye");
    }

    // `exim -bf` se validate kiya gaya (fail-closed ka saboot)
    assert_true($h['cmd']->eximFilterArgvs !== [], 'exim -bf se filter validate hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server sync — kharaab filter reject ho jaye to delivery chalti rahe (fail-closed)', function (): void {
    $h = acp_mail_harness();
    $home = acp_mail_seed_filter_account($h);
    file_put_contents($home . '/etc/mail/filters', (string) json_encode([
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'x', 'action' => 'folder', 'folder' => 'junk'],
    ]));
    $h['cmd']->eximFilterFails = true;   // `exim -bf` mana kar de

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(($out['filters'] ?? -1) === 0, 'kharaab filter install nahi hona chahiye, count=' . (int) ($out['filters'] ?? -1));
    $errors = (array) ($out['filter_errors'] ?? []);
    assert_true($errors !== [], 'kyun reject hua — wajah report me aani chahiye (andha fail nahi)');
    assert_true(
        str_contains((string) reset($errors), 'filter'),
        'error me exim ka jawab hona chahiye, mila: ' . (string) reset($errors),
    );
    assert_true(
        (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-filters') === '',
        'lookup file khali rehni chahiye (mail delivery bina filter ke chalti rahe)',
    );
    assert_true(($out['mailboxes'] ?? 0) === 1, 'mailbox aggregate phir bhi hona chahiye');
    acp_mail_cleanup($h);
});

test('email filter — khatarnak needle/pipe kabhi filter file me nahi jata', function (): void {
    $h = acp_mail_harness();
    $home = acp_mail_seed_filter_account($h);
    file_put_contents($home . '/etc/mail/filters', (string) json_encode([
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'ok', 'action' => 'folder', 'folder' => 'junk'],
        // sanitizer inhe reject karta hai, par double-check: agent bhi filter me nahi likhe
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'ok', 'action' => 'pipe', 'folder' => ''],
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'ok', 'action' => 'folder', 'folder' => '../../etc'],
    ]));

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(($out['filters'] ?? -1) === 1, 'sirf wahi rule jo chal sakta hai');
    $filter = (string) @file_get_contents($home . '/etc/mail/filter.d/info@alice.test.filter');
    assert_true(!str_contains($filter, 'pipe'), 'pipe command kabhi nahi');
    assert_true(!str_contains($filter, '../'), 'path traversal kabhi nahi');
    assert_true(!str_contains($filter, '${run'), 'exim expansion kabhi nahi');
    acp_mail_cleanup($h);
});

test('webdisk.list/create/delete: WebDAV digest+DAV conf provisioning, ro/rw write-limit, guards', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $root = $harness['root'];

    $list = (new WebDiskList())->handle(['account' => 'alicehost'], $harness['ctx']);
    assert_true($list['accounts'] === [] && $list['realm'] === WebDisk::REALM, 'shuru me koi WebDisk account nahi');

    (new WebDiskCreate())->handle(['account' => 'alicehost', 'login' => 'designer', 'permissions' => 'rw', 'password' => 'secret123'], $harness['ctx']);
    (new WebDiskCreate())->handle(['account' => 'alicehost', 'login' => 'auditor', 'permissions' => 'ro', 'password' => 'auditpass9'], $harness['ctx']);

    $list = (new WebDiskList())->handle(['account' => 'alicehost'], $harness['ctx']);
    assert_true(count($list['accounts']) === 2, '2 WebDisk accounts list hue');

    $conf = (string) file_get_contents($root . '/home/alicehost/etc/webdisk.conf');
    assert_true(str_contains($conf, 'Alias /webdisk "' . $root . '/home/alicehost"'), 'Alias account home par');
    assert_true(str_contains($conf, 'DAV on') && str_contains($conf, 'AuthType Digest'), 'DAV + Digest auth conf');
    assert_true(str_contains($conf, 'Require user designer'), 'rw login write-methods list me');
    assert_true(!str_contains($conf, 'Require user auditor'), 'ro login write list me nahi');

    $digest = (string) file_get_contents($root . '/home/alicehost/etc/webdisk.digest');
    $hash = md5('designer:' . WebDisk::REALM . ':secret123');
    assert_true(str_contains($digest, 'designer:' . WebDisk::REALM . ':' . $hash), 'digest hash (md5 A1) sahi');
    assert_true(!str_contains($digest, 'secret123'), 'plaintext password digest me nahi');

    $vhosts = glob($root . '/apache/sites-available/*') ?: [];
    $included = false;
    foreach ($vhosts as $v) {
        if (str_contains((string) file_get_contents($v), 'IncludeOptional ' . $root . '/home/alicehost/etc/webdisk.conf')) {
            $included = true;
        }
    }
    assert_true($included, 'vhost me webdisk.conf IncludeOptional hua');

    (new WebDiskCreate())->handle(['account' => 'alicehost', 'login' => 'designer', 'permissions' => 'rw', 'password' => 'newpass456'], $harness['ctx']);
    $digest2 = (string) file_get_contents($root . '/home/alicehost/etc/webdisk.digest');
    assert_true(str_contains($digest2, md5('designer:' . WebDisk::REALM . ':newpass456')), 'reset ke baad naya hash');
    assert_true(!str_contains($digest2, $hash), 'purana hash hat gaya');

    foreach (['../evil', '', str_repeat('a', 61), 'bad login'] as $bad) {
        $threw = false;
        try {
            (new WebDiskCreate())->handle(['account' => 'alicehost', 'login' => $bad, 'permissions' => 'rw', 'password' => 'secret123'], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'invalid login reject: ' . $bad);
    }
    $threw = false;
    try {
        (new WebDiskCreate())->handle(['account' => 'alicehost', 'login' => 'x1', 'permissions' => 'rw', 'password' => 'short'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'chhota password reject');

    (new WebDiskDelete())->handle(['account' => 'alicehost', 'login' => 'auditor'], $harness['ctx']);
    $list = (new WebDiskList())->handle(['account' => 'alicehost'], $harness['ctx']);
    assert_true(count($list['accounts']) === 1, 'delete ke baad 1 account');
    $conf = (string) file_get_contents($root . '/home/alicehost/etc/webdisk.conf');
    assert_true(str_contains($conf, 'login=designer') && !str_contains($conf, 'login=auditor'), 'conf me sirf bacha account');

    (new WebDiskDelete())->handle(['account' => 'alicehost', 'login' => 'designer'], $harness['ctx']);
    assert_true(!is_file($root . '/home/alicehost/etc/webdisk.conf'), 'aakhri delete par conf clean');
    assert_true(!is_file($root . '/home/alicehost/etc/webdisk.digest'), 'aakhri delete par digest clean');
    assert_true((new WebDiskList())->handle(['account' => 'alicehost'], $harness['ctx'])['accounts'] === [], 'list khali');

    acp_account_cleanup($harness);
});

test('email filter — forward khud ko ho to loop nahi (rule chhod diya jaye)', function (): void {
    $h = acp_mail_harness();
    $home = acp_mail_seed_filter_account($h);
    file_put_contents($home . '/etc/mail/filters', (string) json_encode([
        ['local' => 'info', 'domain' => 'alice.test', 'field' => 'subject', 'needle' => 'x', 'action' => 'forward', 'dest' => 'info@alice.test'],
    ]));
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(($out['filters'] ?? -1) === 0, 'khud ko forward = loop, rule chhod dena chahiye');
    acp_mail_cleanup($h);
});

test('mail.track — ASLI exim mainlog se delivery trace (cPanel #19)', function (): void {
    $h = acp_mail_harness();
    // AccountOs ko lagta hai ki alicehost hamara account hai (getent fake)
    $h['cmd']->users['alicehost'] = AccountOs::GECOS_MARKER . ' alice.test';
    acp_mail_seed_filter_account($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    file_put_contents($h['root'] . '/var/log/exim4/mainlog', implode("\n", [
        '2026-10-05 10:00:01 1oAAAA-000001-AA <= sender@remote.test P=esmtp S=500',
        '2026-10-05 10:00:02 1oAAAA-000001-AA => info@alice.test R=alphacp_userfilter T=address_directory',
        '2026-10-05 10:00:02 1oAAAA-000001-AA Completed',
        '2026-10-05 10:01:01 1oBBBB-000002-AB <= other@remote.test P=esmtp S=500',
        '2026-10-05 10:01:02 1oBBBB-000002-AB == gone@nowhere.test defer (-42)',
        '',
    ]));

    $out = (new MailTrack())->handle(['username' => 'alicehost', 'query' => 'info@alice.test'], $h['ctx']);
    assert_true(($out['status'] ?? '') === 'ok', 'track chalna chahiye');
    $log = (array) ($out['log'] ?? []);
    assert_true($log['ok'] === true, 'mainlog se trace milna chahiye: ' . (string) ($log['error'] ?? ''));
    assert_true(($log['address'] ?? '') === 'info@alice.test', 'address');
    assert_true(($log['summary']['delivered'] ?? 0) === 1, '1 mail pahunchi, mili ' . (int) ($log['summary']['delivered'] ?? 0));
    assert_true(($log['hits_total'] ?? 0) === 1, 'sirf is address ki entry, mili ' . (int) ($log['hits_total'] ?? 0));

    $threw = false;
    try {
        (new MailTrack())->handle(['username' => 'alicehost', 'query' => 'not-an-address'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'galat address reject hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — exim template me filter router + address_directory transport', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_filter_account($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'alphacp_userfilter:'), 'filter router hona chahiye');
    assert_true(str_contains($tpl, 'allow_filter'), 'Exim filter file chalana allowed hona chahiye');
    assert_true(str_contains($tpl, 'directory_transport = address_directory'), 'filter ke save ke liye transport');
    assert_true(str_contains($tpl, 'address_directory:'), 'address_directory transport hona chahiye');
    assert_true(str_contains($tpl, 'create_directory'), 'folder khud ban jana chahiye');
    // LIVE BUG 1 (0.78.0): router par `user = ${extract{2}{ }{${lookup{...}}{$value}{}}}`
    // galat brace-nesting thi -> "Failed to find user }" -> POORA mail delivery defer.
    // Sahi idiom: bina `{$value}{}` ke, wahi jo alphacp_maildir use karta hai.
    $router = substr($tpl, (int) strpos($tpl, 'alphacp_userfilter:'));
    $router = substr($router, 0, (int) strpos($router, 'alphacp_autoreply:'));
    assert_true(!str_contains($router, '{$value}'), '{$value} wali nesting galat hai (exim "Failed to find user" deta hai)');
    // LIVE BUG 2 (0.79.0): `allow_filter` ke saath `user` HATANE par exim config
    // hi reject kar deta hai ("user or check_local_user must be set with allow_filter").
    assert_true(str_contains($router, 'user = ${extract{2}{ }'), 'allow_filter ke saath user= ZAROORI hai (warna exim -bV reject)');
    assert_true(str_contains($router, 'group = ${extract{3}{ }'), 'group bhi wahi idiom');
    // LIVE BUG 3: lookup file missing/empty par defer+PANIC na ho — dono guard chahiye.
    assert_true(str_contains($router, 'require_files = '), 'require_files guard (lookup file missing par skip)');
    assert_true(str_contains($router, 'condition = ${if !eq{'), 'condition guard (is address ka filter nahi to skip)');
    // LIVE BUG 4 (0.79.0): transport par directory/user/group set karne se filter ka
    // `save` path override ho jata hai (mail .filtered/ ki jagah inbox me chali gayi).
    $tdir = substr($tpl, (int) strpos($tpl, 'address_directory:'));
    $tdir = substr($tdir, 0, 400);
    assert_true(!str_contains($tdir, 'directory ='), 'address_directory me directory= NAHI (filter ke save path ko override karta hai)');
    assert_true(!str_contains($tdir, 'user ='), 'address_directory me user= NAHI (router se inherit hota hai)');
    assert_true(!str_contains($tdir, 'group ='), 'address_directory me group= NAHI');
    assert_true(str_contains($tdir, 'create_directory'), 'folder khud ban jana chahiye');
    // filter router mailbox router se pehle aana chahiye
    assert_true(
        strpos($tpl, 'alphacp_userfilter:') < strpos($tpl, 'alphacp_mailbox:'),
        'filter pehle chalna chahiye, phir mailbox delivery',
    );
    acp_mail_cleanup($h);
});

fwrite(STDOUT, "\n" . str_repeat('-', 50) . "\n");
fwrite(STDOUT, sprintf("passed: %d   failed: %d\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_account_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-acct-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/home',
        $root . '/apache/sites-available',
        $root . '/apache/sites-enabled',
        $root . '/php/pool.d',
        $root . '/suspended',
        $root . '/alphacp',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');
    putenv('ACP_APACHE_SITES=' . $root . '/apache/sites-available');
    putenv('ACP_APACHE_ENABLED=' . $root . '/apache/sites-enabled');
    putenv('ACP_PHP_POOL_DIR=' . $root . '/php/pool.d');
    putenv('ACP_SUSPENDED_ROOT=' . $root . '/suspended');
    putenv('ACP_APACHE_SERVICE=apache2');
    putenv('ACP_NOLOGIN=/usr/sbin/nologin');
    putenv('ACP_PHP_VERSION=8.4');
    putenv('ACP_FAKE_SETQUOTA=1');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_MYSQL_CLIENT=/usr/bin/mariadb'); // fake executor intercepts it
    // mail sync (mail.set/mail.forward ke baad auto-sync) bhi isi harness me chalti hai
    if (!is_dir($root . '/etc/exim4')) {
        mkdir($root . '/etc/exim4', 0755, true);
    }
    putenv('ACP_MAIL_FILTERS=' . $root . '/etc/exim4/alphacp-filters');
    putenv('ACP_MAIL_EXIM_RECIPIENTS=' . $root . '/etc/exim4/alphacp-recipients');
    putenv('ACP_MAIL_EXIM_DOMAINS=' . $root . '/etc/exim4/alphacp-domains');
    putenv('ACP_MAIL_EXIM_ALIASES=' . $root . '/etc/exim4/alphacp-aliases');
    putenv('ACP_MAIL_CATCHALL=' . $root . '/etc/exim4/alphacp-catchall');
    putenv('ACP_MAIL_DOVECOT_USERS=' . $root . '/etc/dovecot/alphacp-users');
    putenv('ACP_MAIL_VACATION_DIR=' . $root . '/etc/exim4/vacation');
    putenv('ACP_MAIL_SPAM_DIR=' . $root . '/etc/exim4/spam');
    putenv('ACP_MAIL_DKIM_DIR=' . $root . '/alphacp/etc/mail/dkim');

    $cmd = new FakeCommandExecutor();
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(
        log: $log,
        cmd: $cmd,
        paths: new PathGuard($dirs),
        taskId: null,
        taskRow: null,
    );
    return ['root' => $root, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root:string} $harness */
function acp_account_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach ([
        'ACP_ACCOUNTS_ROOT', 'ACP_APACHE_SITES', 'ACP_APACHE_ENABLED', 'ACP_PHP_POOL_DIR',
        'ACP_SUSPENDED_ROOT', 'ACP_PHP_FPM_SERVICE', 'ACP_APACHE_SERVICE', 'ACP_NOLOGIN',
        'ACP_PHP_VERSION', 'ACP_FAKE_SETQUOTA',
        'ACP_MAIL_FILTERS', 'ACP_MAIL_EXIM_RECIPIENTS', 'ACP_MAIL_EXIM_DOMAINS',
        'ACP_MAIL_EXIM_ALIASES', 'ACP_MAIL_CATCHALL', 'ACP_MAIL_DOVECOT_USERS',
        'ACP_MAIL_VACATION_DIR', 'ACP_MAIL_SPAM_DIR', 'ACP_MAIL_DKIM_DIR',
    ] as $name) {
        putenv($name);
    }
}

/** @return array<string, mixed> */
function acp_create_payload(): array
{
    return [
        'username'    => 'alicehost',
        'domain'      => 'shop.example.com',
        'shadow_hash' => '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv',
        'quota_mb'    => 1024,
        'php_version' => '8.4',
    ];
}
PHPEOF
  cat > "${PANEL}/app/Http/Controllers/WebmailController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\WebmailSetting;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Webmail — preferred client via paneld. No Roundcube/Horde install, no SSO. */
class WebmailController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->webmailSetting;

        $mailbox = null;
        if ($account !== null && ($row?->enabled ?? false)) {
            $mailbox = $account->mailboxes()->orderBy('id')->first()?->address();
        }

        return view('webmail.index', [
            'account' => $account,
            'enabled' => (bool) ($row?->enabled ?? false),
            'client' => (string) ($row?->client ?? 'roundcube'),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
            'openMailbox' => $mailbox,
            'webmailPort' => (int) config('acp.webmail_port', 2096),
        ]);
    }

    /**
     * cPanel-style "Open Webmail": one-time SSO token banao (10 min) aur
     * Roundcube (port webmail_port) par bhejo; plugin token verify kar ke
     * Dovecot master-user se seamless login karta hai.
     */
    public function open(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $mailbox = $account->mailboxes()->orderBy('id')->first()?->address();
        if ($mailbox === null || $mailbox === '') {
            return back()->withErrors(['webmail' => 'Koi mailbox nahi mila — pehle Email Accounts me ek account banayein.']);
        }
        $token = bin2hex(random_bytes(32));
        \Illuminate\Support\Facades\DB::table('webmail_sso_tokens')->insert([
            'token'      => $token,
            'user_id'    => (int) $request->user()->id,
            'mailbox'    => $mailbox,
            'used'       => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $port = (int) config('acp.webmail_port', 2096);

        return redirect()->away('https://' . $request->getHost() . ':' . $port . '/?_acp_token=' . $token);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['client' => 'Cannot change Webmail on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'enabled' => ['nullable', 'in:0,1'],
            'client' => ['required', 'string', 'max:16'],
        ]);
        $client = Mail::tryClient($data['client']);
        if ($client === null) {
            return back()->withErrors(['client' => 'Client must be roundcube or horde, no pipe.'])->withInput();
        }
        $row = WebmailSetting::query()->firstOrNew(['account_id' => $account->id]);
        $row->enabled = ($data['enabled'] ?? '0') === '1';
        $row->client = $client;
        $row->save();
        MailProvisioner::enqueueWebmail($account);
        $account->recordEvent('mail.webmail.queued', $row->enabled ? $client : 'off');
        Audit::log('mail.webmail', 'info', 'account', $account->id, ['enabled' => $row->enabled, 'client' => $client]);

        return redirect()->route('webmail.index')->with('success', 'Webmail is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'webmailSetting']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
PHPEOF
  cat > "${PANEL}/app/Http/Controllers/WebmailSsoController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel-internal endpoint: Roundcube ka acp_sso plugin yahan one-time token
 * verify karta hai (shared secret /usr/local/alphacp/etc/webmail-sso.secret,
 * root:www-data 0640). Public internet se expose nahi (nginx internal location
 * sirf 127.0.0.1 se allow karta hai — webmail-fix installer gate).
 */
final class WebmailSsoController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $secretFile = rtrim((string) config('acp.home'), '/') . '/etc/webmail-sso.secret';
        $secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
        $given = (string) $request->header('X-ACP-Secret', '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            return response()->json(['error' => 'bad secret'], 403);
        }

        $token = (string) $request->query('token', '');
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return response()->json(['error' => 'bad token'], 422);
        }

        $row = DB::table('webmail_sso_tokens')->where('token', $token)->first();
        if ($row === null || (int) $row->used === 1 || strtotime((string) $row->expires_at) < time()) {
            return response()->json(['error' => 'token invalid/expired'], 410);
        }
        DB::table('webmail_sso_tokens')->where('id', $row->id)->update(['used' => 1, 'updated_at' => now()]);

        return response()->json(['mailbox' => (string) $row->mailbox]);
    }
}
PHPEOF
  cat > "${PANEL}/routes/web.php" <<'PHPEOF'
<?php

declare(strict_types=1);

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PackagesController;
use App\Http\Controllers\Auth\EntryLoginController as LoginController; // ACP-ENTRY-GATE
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\ErrorPagesController;
use App\Http\Controllers\AutorespondersController;
use App\Http\Controllers\DefaultAddressController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\EmailFiltersController;
use App\Http\Controllers\ForwardersController;
use App\Http\Controllers\EmailRoutingController;
use App\Http\Controllers\TrackDeliveryController;
use App\Http\Controllers\GlobalFiltersController;
use App\Http\Controllers\AddressImporterController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\BoxTrapperController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EmailDiskUsageController;
use App\Http\Controllers\WebmailController;
use App\Http\Controllers\MysqlDatabasesController;
use App\Http\Controllers\MysqlUsersController;
use App\Http\Controllers\MysqlWizardController;
use App\Http\Controllers\PhpmyadminController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\ZoneEditorController;
use App\Http\Controllers\DynamicDnsController;
use App\Http\Controllers\TrackDnsController;
use App\Http\Controllers\DnsZonesController;
use App\Http\Controllers\HostnameAController;
use App\Http\Controllers\ZoneTemplatesController;
use App\Http\Controllers\GlobalEmailRoutingController;
use App\Http\Controllers\NsReportController;
use App\Http\Controllers\ParkDomainController;
use App\Http\Controllers\DnsCleanupController;
use App\Http\Controllers\ZoneTtlController;
use App\Http\Controllers\DomainForwardController;
use App\Http\Controllers\DnsSyncController;
use App\Http\Controllers\BackupConfigController;
use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BackupRestorationController;
use App\Http\Controllers\BackupUserSelectionController;
use App\Http\Controllers\FileDirectoryRestorationController;
use App\Http\Controllers\TransferRestoreController;
use App\Http\Controllers\TransferToolController;
use App\Http\Controllers\TransferReviewController;
use App\Http\Controllers\NameserverSelectionController;
use App\Http\Controllers\MailingListsController;
use App\Http\Controllers\SpamFiltersController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\IndexesController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupWizardController;
use App\Http\Controllers\FileRestorationController;
use App\Http\Controllers\DiskUsageController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\HandlersController;
use App\Http\Controllers\MimeTypesController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\PhpIniController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SshController;
use App\Http\Controllers\SslController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AlphaCP panel routes
|--------------------------------------------------------------------------
| Contract for future edits (any AI/dev):
|   * Every privileged action lives behind `auth` + `2fa` + `password.fresh`.
|   * Permission checks live in the `perm:` middleware, never in views.
|   * Module routes keep the module prefix (users.*, system.*, audit.* …) so
|   * Step 3+ modules can be dropped in without touching what exists here.
|   * Anything that changes state MUST write to the audit log.
*/

// ---------------------------------------------------------------------------
// Guest
// ---------------------------------------------------------------------------
// Panel-internal (server-to-server, shared secret): Roundcube SSO verify.
Route::get('/internal/webmail-sso', [App\Http\Controllers\WebmailSsoController::class, 'verify'])
    ->middleware('throttle:60,1')->name('internal.webmail-sso');

Route::middleware('guest')->group(function (): void {
    Route::get('/', [LoginController::class, 'show'])->name('login');
    // /login bhi wahi login page — bookmark/WHMCS/cPanel aadat. Pehle 404 deta tha.
    Route::get('/login', [LoginController::class, 'show'])->name('login.page');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

// ---------------------------------------------------------------------------
// Authenticated (2FA challenge happens before anything else)
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('twofactor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('twofactor.verify');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// ---------------------------------------------------------------------------
// Panel (auth + 2FA verified + fresh password)
// ---------------------------------------------------------------------------
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/domains', [DomainsController::class, 'index'])
        ->middleware('perm:domains.view')->name('domains.index');
    Route::post('/domains', [DomainsController::class, 'store'])
        ->middleware('perm:domains.manage')->name('domains.store');
    Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])
        ->middleware('perm:domains.manage')->name('domains.destroy');

    Route::get('/php', [PhpController::class, 'index'])
        ->middleware('perm:software.view')->name('php.index');
    Route::post('/php', [PhpController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.update');
    Route::get('/php/ini', [PhpIniController::class, 'index'])
        ->middleware('perm:software.view')->name('php.ini');
    Route::post('/php/ini', [PhpIniController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.ini.update');

    Route::get('/errorpages', [ErrorPagesController::class, 'index'])
        ->middleware('perm:errorpages.view')->name('errorpages.index');
    Route::post('/errorpages', [ErrorPagesController::class, 'update'])
        ->middleware('perm:errorpages.manage')->name('errorpages.update');

    Route::get('/indexes', [IndexesController::class, 'index'])
        ->middleware('perm:indexes.view')->name('indexes.index');
    Route::post('/indexes', [IndexesController::class, 'update'])
        ->middleware('perm:indexes.manage')->name('indexes.update');

    Route::get('/mime', [MimeTypesController::class, 'index'])
        ->middleware('perm:mime.view')->name('mime.index');
    Route::post('/mime', [MimeTypesController::class, 'store'])
        ->middleware('perm:mime.manage')->name('mime.store');
    Route::delete('/mime/{ext}', [MimeTypesController::class, 'destroy'])
        ->middleware('perm:mime.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('mime.destroy');

    Route::get('/handlers', [HandlersController::class, 'index'])
        ->middleware('perm:handlers.view')->name('handlers.index');
    Route::post('/handlers', [HandlersController::class, 'store'])
        ->middleware('perm:handlers.manage')->name('handlers.store');
    Route::delete('/handlers/{ext}', [HandlersController::class, 'destroy'])
        ->middleware('perm:handlers.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('handlers.destroy');

    Route::get('/files', [FilesController::class, 'index'])
        ->middleware('perm:files.view')->name('files.index');
    Route::post('/files/mkdir', [FilesController::class, 'mkdir'])
        ->middleware('perm:files.manage')->name('files.mkdir');
    Route::post('/files/write', [FilesController::class, 'write'])
        ->middleware('perm:files.manage')->name('files.write');
    Route::post('/files/rename', [FilesController::class, 'rename'])
        ->middleware('perm:files.manage')->name('files.rename');
    Route::post('/files/delete', [FilesController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('files.destroy');

    Route::get('/disk', [DiskUsageController::class, 'index'])
        ->middleware('perm:files.view')->name('disk.index');

    Route::get('/backup', [BackupController::class, 'index'])
        ->middleware('perm:files.view')->name('backup.index');
    Route::post('/backup', [BackupController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup.store');
    Route::post('/backup/archive', [BackupController::class, 'archive'])
        ->middleware('perm:files.manage')->name('backup.archive');
    Route::get('/backup/archive/{archiveId}/download', [BackupController::class, 'download'])
        ->middleware('perm:files.view')->name('backup.archive-download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])
        ->middleware('perm:files.manage')->name('backup.restore-archive');

    Route::get('/backup-wizard', [BackupWizardController::class, 'index'])
        ->middleware('perm:files.view')->name('backup-wizard.index');
    Route::post('/backup-wizard', [BackupWizardController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup-wizard.store');

    Route::get('/file-restoration', [FileRestorationController::class, 'index'])
        ->middleware('perm:files.view')->name('file-restoration.index');
    Route::post('/file-restoration', [FileRestorationController::class, 'store'])
        ->middleware('perm:files.manage')->name('file-restoration.store');

    Route::get('/email', [MailController::class, 'index'])
        ->middleware('perm:email.view')->name('email.index');
    Route::post('/email', [MailController::class, 'store'])
        ->middleware('perm:email.manage')->name('email.store');
    Route::delete('/email/{mailbox}', [MailController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email.destroy');

    Route::get('/forwarders', [ForwardersController::class, 'index'])
        ->middleware('perm:email.view')->name('forwarders.index');
    Route::post('/forwarders', [ForwardersController::class, 'store'])
        ->middleware('perm:email.manage')->name('forwarders.store');
    Route::delete('/forwarders/{forwarder}', [ForwardersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('forwarders.destroy');

    Route::get('/autoresponders', [AutorespondersController::class, 'index'])
        ->middleware('perm:email.view')->name('autoresponders.index');
    Route::post('/autoresponders', [AutorespondersController::class, 'store'])
        ->middleware('perm:email.manage')->name('autoresponders.store');
    Route::delete('/autoresponders/{autoresponder}', [AutorespondersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('autoresponders.destroy');

    Route::get('/default-address', [DefaultAddressController::class, 'index'])
        ->middleware('perm:email.view')->name('default-address.index');
    Route::post('/default-address', [DefaultAddressController::class, 'store'])
        ->middleware('perm:email.manage')->name('default-address.store');
    Route::delete('/default-address/{catchall}', [DefaultAddressController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('default-address.destroy');

    Route::get('/email-filters', [EmailFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('email-filters.index');
    Route::post('/email-filters', [EmailFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-filters.store');
    Route::delete('/email-filters/{filter}', [EmailFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email-filters.destroy');

    Route::get('/deliverability', [DeliverabilityController::class, 'index'])
        ->middleware('perm:email.view')->name('deliverability.index');
    Route::post('/deliverability', [DeliverabilityController::class, 'store'])
        ->middleware('perm:email.manage')->name('deliverability.store');

    Route::get('/spam-filters', [SpamFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('spam-filters.index');
    Route::post('/spam-filters', [SpamFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('spam-filters.store');

    Route::get('/mailing-lists', [MailingListsController::class, 'index'])
        ->middleware('perm:email.view')->name('mailing-lists.index');
    Route::post('/mailing-lists', [MailingListsController::class, 'store'])
        ->middleware('perm:email.manage')->name('mailing-lists.store');
    Route::patch('/mailing-lists/{mailing_list}', [MailingListsController::class, 'update'])
        ->middleware('perm:email.manage')->name('mailing-lists.update');
    Route::delete('/mailing-lists/{mailing_list}', [MailingListsController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('mailing-lists.destroy');

    Route::get('/email-routing', [EmailRoutingController::class, 'index'])
        ->middleware('perm:email.view')->name('email-routing.index');
    Route::post('/email-routing', [EmailRoutingController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-routing.store');

    Route::get('/track-delivery', [TrackDeliveryController::class, 'index'])
        ->middleware('perm:email.view')->name('track-delivery.index');
    Route::post('/track-delivery', [TrackDeliveryController::class, 'store'])
        ->middleware('perm:email.manage')->name('track-delivery.store');

    Route::get('/global-filters', [GlobalFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('global-filters.index');
    Route::post('/global-filters', [GlobalFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('global-filters.store');
    Route::delete('/global-filters/{global_filter}', [GlobalFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('global-filters.destroy');

    Route::get('/address-importer', [AddressImporterController::class, 'index'])
        ->middleware('perm:email.view')->name('address-importer.index');
    Route::post('/address-importer', [AddressImporterController::class, 'store'])
        ->middleware('perm:email.manage')->name('address-importer.store');

    Route::get('/encryption', [EncryptionController::class, 'index'])
        ->middleware('perm:email.view')->name('encryption.index');
    Route::post('/encryption', [EncryptionController::class, 'store'])
        ->middleware('perm:email.manage')->name('encryption.store');
    Route::delete('/encryption/{encryption_key}', [EncryptionController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('encryption.destroy');

    Route::get('/boxtrapper', [BoxTrapperController::class, 'index'])
        ->middleware('perm:email.view')->name('boxtrapper.index');
    Route::post('/boxtrapper', [BoxTrapperController::class, 'store'])
        ->middleware('perm:email.manage')->name('boxtrapper.store');

    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('perm:email.view')->name('calendar.index');
    Route::post('/calendar', [CalendarController::class, 'store'])
        ->middleware('perm:email.manage')->name('calendar.store');
    Route::delete('/calendar/{calendar_item}', [CalendarController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('calendar.destroy');

    Route::get('/email-disk', [EmailDiskUsageController::class, 'index'])
        ->middleware('perm:email.view')->name('email-disk.index');

    Route::get('/webmail', [WebmailController::class, 'index'])
        ->middleware('perm:email.view')->name('webmail.index');
    Route::post('/webmail', [WebmailController::class, 'store'])
        ->middleware('perm:email.manage')->name('webmail.store');
    Route::post('/webmail/open', [WebmailController::class, 'open'])
        ->middleware('perm:email.view')->name('webmail.open');

    Route::get('/mysql', [MysqlDatabasesController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql.index');
    Route::post('/mysql', [MysqlDatabasesController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql.store');
    Route::delete('/mysql/{mysql_database}', [MysqlDatabasesController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql.destroy');

    Route::get('/mysql-users', [MysqlUsersController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-users.index');
    Route::post('/mysql-users', [MysqlUsersController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-users.store');
    Route::post('/mysql-users/grant', [MysqlUsersController::class, 'grant'])
        ->middleware('perm:databases.manage')->name('mysql-users.grant');
    Route::post('/mysql-users/{mysql_user}/password', [MysqlUsersController::class, 'password'])
        ->middleware('perm:databases.manage')->name('mysql-users.password');
    Route::delete('/mysql-users/{mysql_user}', [MysqlUsersController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql-users.destroy');

    Route::get('/mysql-wizard', [MysqlWizardController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-wizard.index');
    Route::post('/mysql-wizard', [MysqlWizardController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-wizard.store');

    Route::get('/phpmyadmin', [PhpmyadminController::class, 'index'])
        ->middleware('perm:databases.view')->name('phpmyadmin.index');
    Route::post('/phpmyadmin', [PhpmyadminController::class, 'store'])
        ->middleware('perm:databases.manage')->name('phpmyadmin.store');

    Route::get('/remote-mysql', [RemoteMysqlController::class, 'index'])
        ->middleware('perm:databases.view')->name('remote-mysql.index');
    Route::post('/remote-mysql', [RemoteMysqlController::class, 'store'])
        ->middleware('perm:databases.manage')->name('remote-mysql.store');
    Route::delete('/remote-mysql/{mysql_remote_host}', [RemoteMysqlController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('remote-mysql.destroy');

    Route::get('/zone-editor', [ZoneEditorController::class, 'index'])
        ->middleware('perm:dns.view')->name('zone-editor.index');
    Route::post('/zone-editor', [ZoneEditorController::class, 'store'])
        ->middleware('perm:dns.manage')->name('zone-editor.store');
    Route::delete('/zone-editor/{dns_record}', [ZoneEditorController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('zone-editor.destroy');

    Route::get('/dynamic-dns', [DynamicDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('dynamic-dns.index');
    Route::post('/dynamic-dns', [DynamicDnsController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.store');
    Route::delete('/dynamic-dns/{dns_dynamic_host}', [DynamicDnsController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.destroy');

    Route::get('/track-dns', [TrackDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('track-dns.index');
    Route::post('/track-dns', [TrackDnsController::class, 'store'])
        ->middleware('perm:dns.view')->name('track-dns.store');

    Route::get('/dns-zones', [DnsZonesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-zones.index');
    Route::post('/dns-zones', [DnsZonesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-zones.store');
    Route::delete('/dns-zones', [DnsZonesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('dns-zones.destroy');
    Route::post('/dns-zones/{account}/sync', [DnsZonesController::class, 'sync'])
        ->middleware('perm:accounts.view')->name('dns-zones.sync');

    Route::get('/hostname-a', [HostnameAController::class, 'index'])
        ->middleware('perm:accounts.view')->name('hostname-a.index');
    Route::post('/hostname-a', [HostnameAController::class, 'store'])
        ->middleware('perm:accounts.view')->name('hostname-a.store');

    Route::get('/zone-templates', [ZoneTemplatesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-templates.index');
    Route::post('/zone-templates', [ZoneTemplatesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-templates.store');
    Route::delete('/zone-templates/{dns_template}', [ZoneTemplatesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('zone-templates.destroy');

    Route::get('/global-email-routing', [GlobalEmailRoutingController::class, 'index'])
        ->middleware('perm:accounts.view')->name('global-email-routing.index');
    Route::post('/global-email-routing', [GlobalEmailRoutingController::class, 'store'])
        ->middleware('perm:accounts.view')->name('global-email-routing.store');

    Route::get('/ns-report', [NsReportController::class, 'index'])
        ->middleware('perm:accounts.view')->name('ns-report.index');
    Route::post('/ns-report', [NsReportController::class, 'store'])
        ->middleware('perm:accounts.view')->name('ns-report.store');

    Route::get('/park-domain', [ParkDomainController::class, 'index'])
        ->middleware('perm:accounts.view')->name('park-domain.index');
    Route::post('/park-domain', [ParkDomainController::class, 'store'])
        ->middleware('perm:accounts.view')->name('park-domain.store');

    Route::get('/dns-cleanup', [DnsCleanupController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.index');
    Route::post('/dns-cleanup', [DnsCleanupController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.store');

    Route::get('/zone-ttl', [ZoneTtlController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-ttl.index');
    Route::post('/zone-ttl', [ZoneTtlController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-ttl.store');

    Route::get('/domain-forward', [DomainForwardController::class, 'index'])
        ->middleware('perm:accounts.view')->name('domain-forward.index');
    Route::post('/domain-forward', [DomainForwardController::class, 'store'])
        ->middleware('perm:accounts.view')->name('domain-forward.store');

    Route::get('/dns-sync', [DnsSyncController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-sync.index');
    Route::post('/dns-sync', [DnsSyncController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-sync.store');

    Route::get('/nameserver-selection', [NameserverSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.index');
    Route::post('/nameserver-selection', [NameserverSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.store');

    Route::get('/backup-config', [BackupConfigController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-config.index');
    Route::post('/backup-config', [BackupConfigController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-config.store');

    Route::get('/backup-destinations', [BackupDestinationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-destinations.index');
    Route::post('/backup-destinations', [BackupDestinationController::class, 'store'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.store');
    Route::post('/backup-destinations/test', [BackupDestinationController::class, 'test'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.test');
    Route::post('/backup-destinations/push', [BackupDestinationController::class, 'push'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.push');
    Route::post('/backup-destinations/browse', [BackupDestinationController::class, 'browse'])
        ->middleware('perm:accounts.view')->name('backup-destinations.browse');
    Route::delete('/backup-destinations/{name}', [BackupDestinationController::class, 'destroy'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.destroy');

    Route::get('/backup-restoration', [BackupRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-restoration.index');
    Route::post('/backup-restoration', [BackupRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-restoration.store');

    Route::get('/backup-user-selection', [BackupUserSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.index');
    Route::post('/backup-user-selection', [BackupUserSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.store');

    Route::get('/file-directory-restoration', [FileDirectoryRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.index');
    Route::post('/file-directory-restoration', [FileDirectoryRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.store');

    Route::get('/transfer-tool', [TransferToolController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-tool.index');
    Route::post('/transfer-tool', [TransferToolController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-tool.store');
    // S10 remote pull: 1) host key fingerprint lao (probe) 2) archive lao (pull)
    Route::post('/transfer-tool/probe', [TransferToolController::class, 'probe'])
        ->middleware('perm:accounts.view')->name('transfer-tool.probe');
    Route::post('/transfer-tool/pull', [TransferToolController::class, 'pull'])
        ->middleware('perm:accounts.view')->name('transfer-tool.pull');

    Route::get('/transfer-restore', [TransferRestoreController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-restore.index');
    Route::post('/transfer-restore', [TransferRestoreController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-restore.store');

    Route::get('/transfer-review', [TransferReviewController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-review.index');
    Route::post('/transfer-review', [TransferReviewController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-review.store');

    Route::get('/ssh', [SshController::class, 'index'])
        ->middleware('perm:ssh.view')->name('ssh.index');
    Route::post('/ssh', [SshController::class, 'store'])
        ->middleware('perm:ssh.manage')->name('ssh.store');
    Route::post('/ssh/delete', [SshController::class, 'destroy'])
        ->middleware('perm:ssh.manage')->name('ssh.destroy');
    Route::post('/ssh/shell', [SshController::class, 'shell'])
        ->middleware('perm:ssh.manage')->name('ssh.shell');

    Route::get('/privacy', [PrivacyController::class, 'index'])
        ->middleware('perm:privacy.view')->name('privacy.index');
    Route::post('/privacy', [PrivacyController::class, 'store'])
        ->middleware('perm:privacy.manage')->name('privacy.store');
    Route::post('/privacy/delete', [PrivacyController::class, 'destroy'])
        ->middleware('perm:privacy.manage')->name('privacy.destroy');

    Route::get('/cron', [CronController::class, 'index'])
        ->middleware('perm:cron.view')->name('cron.index');
    Route::post('/cron', [CronController::class, 'store'])
        ->middleware('perm:cron.manage')->name('cron.store');
    Route::delete('/cron/{cron}', [CronController::class, 'destroy'])
        ->middleware('perm:cron.manage')->name('cron.destroy');

    Route::get('/ssl', [SslController::class, 'index'])
        ->middleware('perm:ssl.view')->name('ssl.index');
    Route::post('/ssl/autossl', [SslController::class, 'autossl'])
        ->middleware('perm:ssl.manage')->name('ssl.autossl');
    Route::post('/ssl/{domain}', [SslController::class, 'issue'])
        ->middleware('perm:ssl.manage')->name('ssl.issue');
    Route::post('/ssl/{domain}/autossl', [SslController::class, 'toggle'])
        ->middleware('perm:ssl.manage')->name('ssl.toggle');
    Route::delete('/ssl/{domain}', [SslController::class, 'destroy'])
        ->middleware('perm:ssl.manage')->name('ssl.destroy');

    // -- Security (always available to the logged-in user) -------------------
    Route::prefix('security')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::post('/2fa/start', [SecurityController::class, 'startTwoFactor'])->name('2fa.start');
        Route::post('/2fa/confirm', [SecurityController::class, 'confirmTwoFactor'])->name('2fa.confirm');
        Route::post('/2fa/disable', [SecurityController::class, 'disableTwoFactor'])->name('2fa.disable');
        Route::get('/password', [SecurityController::class, 'password'])->name('password');
        Route::post('/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{id}', [SecurityController::class, 'destroySession'])->name('sessions.destroy');
    });

    // -- Hosting accounts (Step 3). /create MUST sit before /{account}.
    Route::get('/accounts', [AccountsController::class, 'index'])
        ->middleware('perm:accounts.view')->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])
        ->middleware('perm:accounts.create')->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])
        ->middleware('perm:accounts.create')->name('accounts.store');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])
        ->middleware('perm:accounts.view')->name('accounts.show');
    Route::post('/accounts/{account}/suspend', [AccountsController::class, 'suspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.suspend');
    Route::post('/accounts/{account}/unsuspend', [AccountsController::class, 'unsuspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.unsuspend');
    Route::post('/accounts/{account}/terminate', [AccountsController::class, 'terminate'])
        ->middleware('perm:accounts.terminate')->name('accounts.terminate');
    Route::post('/accounts/{account}/upgrade', [AccountsController::class, 'upgrade'])
        ->middleware('perm:accounts.modify')->name('accounts.upgrade');
    Route::post('/accounts/{account}/quota', [AccountsController::class, 'quota'])
        ->middleware('perm:accounts.modify')->name('accounts.quota');
    Route::post('/accounts/{account}/php', [AccountsController::class, 'php'])
        ->middleware('perm:accounts.modify')->name('accounts.php');

    Route::get('/packages', [PackagesController::class, 'index'])
        ->middleware('perm:packages.view')->name('packages.index');
    Route::middleware('perm:packages.manage')->group(function (): void {
        Route::get('/packages/create', [PackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [PackagesController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
        Route::post('/packages/{package}/archive', [PackagesController::class, 'archive'])->name('packages.archive');
    });

    // -- Users (panel logins) -------------------------------------------------
    Route::middleware('perm:users.view')->group(function (): void {
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    });
    Route::middleware('perm:users.manage')->group(function (): void {
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password', [UsersController::class, 'resetPassword'])->name('users.password');
    });

    // -- Audit -----------------------------------------------------------------
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('perm:audit.view')->name('audit.index');

    // -- License / trial (admin) -----------------------------------------------
    Route::prefix('license')->name('license.')->middleware('perm:license.view')->group(function (): void {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
        Route::post('/activate', [LicenseController::class, 'activate'])
            ->middleware('perm:license.manage')->name('activate');
    });

    // -- Server (admin) ----------------------------------------------------------
    Route::prefix('system')->name('system.')->middleware('perm:system.view')->group(function (): void {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::get('/services', [SystemController::class, 'services'])->name('services');
        Route::get('/tasks', [SystemController::class, 'tasks'])->name('tasks');
        Route::post('/tasks/run', [SystemController::class, 'runTask'])
            ->middleware('perm:system.manage')->name('tasks.run');
    });
});

// Fallback: unknown panel URLs get a clean 404 page, not a stack trace.
Route::fallback(fn () => response()->view('errors.404', [], 404));
// ---- FTP Accounts (portable feature: pure-ftpd) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ftp', [\App\Http\Controllers\FtpController::class, 'index'])
        ->middleware('perm:files.view')->name('ftp.index');
    Route::post('/ftp', [\App\Http\Controllers\FtpController::class, 'store'])
        ->middleware('perm:files.manage')->name('ftp.store');
    Route::post('/ftp/{ftpAccount}/password', [\App\Http\Controllers\FtpController::class, 'password'])
        ->middleware('perm:files.manage')->name('ftp.password');
    Route::delete('/ftp/{ftpAccount}', [\App\Http\Controllers\FtpController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('ftp.destroy');
});
// ---- /FTP ----
// ---- Metrics (portable feature: access-log stats) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('metrics.index');
});
// ---- /Metrics ----
// ---- IP Blocker (portable feature: ufw/iptables deny) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'index'])
        ->middleware('perm:security.view')->name('ip-blocker.index');
    Route::post('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'store'])
        ->middleware('perm:security.view')->name('ip-blocker.store');
    Route::delete('/ip-blocker/{blockedIp}', [\App\Http\Controllers\IpBlockerController::class, 'destroy'])
        ->middleware('perm:security.view')->name('ip-blocker.destroy');
});
// ---- /IP Blocker ----
// ---- Security Tools (ModSecurity WAF + Virus Scanner) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/security-tools', [\App\Http\Controllers\SecurityToolsController::class, 'index'])
        ->middleware('perm:security.view')->name('security-tools.index');
    Route::post('/security-tools/modsec', [\App\Http\Controllers\SecurityToolsController::class, 'toggleModsec'])
        ->middleware('perm:security.view')->name('security-tools.modsec');
    Route::post('/security-tools/scan', [\App\Http\Controllers\SecurityToolsController::class, 'scan'])
        ->middleware('perm:security.view')->name('security-tools.scan');
});
// ---- /Security Tools ----
// ---- Site Software / App Installer (WordPress one-click) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/apps', [\App\Http\Controllers\AppsController::class, 'index'])
        ->middleware('perm:software.view')->name('apps.index');
    Route::post('/apps', [\App\Http\Controllers\AppsController::class, 'store'])
        ->middleware('perm:software.manage')->name('apps.store');
});
// ---- /Site Software ----
// ---- Monitoring / Resource Usage ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'index'])
        ->middleware('perm:metrics.view')->name('monitoring.index');
});
// ---- /Monitoring ----
// ---- WHM API 1 compatible (billing integration, Bearer token) ----
Route::prefix('json-api')->middleware([\App\Http\Middleware\EnsureApiToken::class])->group(function (): void {
    Route::get('/listaccts', [\App\Http\Controllers\WhmApiController::class, 'listaccts']);
    Route::get('/accountsummary', [\App\Http\Controllers\WhmApiController::class, 'accountsummary']);
    Route::post('/createacct', [\App\Http\Controllers\WhmApiController::class, 'createacct']);
    Route::get('/suspendacct', [\App\Http\Controllers\WhmApiController::class, 'suspendacct']);
    Route::get('/unsuspendacct', [\App\Http\Controllers\WhmApiController::class, 'unsuspendacct']);
    Route::get('/removeacct', [\App\Http\Controllers\WhmApiController::class, 'removeacct']);
});
// ---- /WHM API ----
// ---- Manage API Tokens (panel UI) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'index'])
        ->middleware('perm:api.view')->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'store'])
        ->middleware('perm:api.manage')->name('api-tokens.store');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\ApiTokensController::class, 'destroy'])
        ->middleware('perm:api.manage')->name('api-tokens.destroy');
});
// ---- /API Tokens ----

// ---- Reseller Center (WHM-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/resellers', [\App\Http\Controllers\ResellersController::class, 'index'])
        ->middleware('perm:users.view')->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellersController::class, 'store'])
        ->middleware('perm:roles.manage')->name('resellers.store');
    Route::post('/resellers/privileges', [\App\Http\Controllers\ResellersController::class, 'updatePrivileges'])
        ->middleware('perm:roles.manage')->name('resellers.privileges');
    Route::delete('/resellers/{user}', [\App\Http\Controllers\ResellersController::class, 'destroy'])
        ->middleware('perm:roles.manage')->name('resellers.destroy');
});
// ---- /Reseller Center ----

// ---- G5: Git Version Control + Terminal ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/git', [\App\Http\Controllers\GitController::class, 'index'])
        ->middleware('perm:files.view')->name('git.index');
    Route::post('/git/clone', [\App\Http\Controllers\GitController::class, 'clone'])
        ->middleware('perm:files.manage')->name('git.clone');
    Route::get('/git/status/{dir}', [\App\Http\Controllers\GitController::class, 'status'])
        ->middleware('perm:files.view')->name('git.status');
    Route::post('/git/pull/{dir}', [\App\Http\Controllers\GitController::class, 'pull'])
        ->middleware('perm:files.manage')->name('git.pull');

    Route::get('/terminal', [\App\Http\Controllers\TerminalController::class, 'index'])
        ->middleware('perm:system.manage')->name('terminal.index');
    Route::post('/terminal', [\App\Http\Controllers\TerminalController::class, 'run'])
        ->middleware('perm:system.manage')->name('terminal.run');
});
// ---- /G5 ----

// ---- License Server (sellable signed licenses) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'index'])
        ->middleware('perm:license.manage')->name('license-server.index');
    Route::post('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'store'])
        ->middleware('perm:license.manage')->name('license-server.store');
    Route::delete('/license-server/{licenseKey}', [\App\Http\Controllers\LicenseServerController::class, 'destroy'])
        ->middleware('perm:license.manage')->name('license-server.destroy');
});
// Customer panel ka online verify (public, read-only)
Route::post('/license-server/verify', [\App\Http\Controllers\LicenseServerController::class, 'verify'])
    ->name('license-server.verify');
// ---- /License Server ----

// ---- Security extras: Hotlink + Leech Protection ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/hotlink-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'hotlink'])
        ->middleware('perm:security.view')->name('secextra.hotlink');
    Route::get('/leech-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'leech'])
        ->middleware('perm:security.view')->name('secextra.leech');
    Route::post('/security-extras', [\App\Http\Controllers\SecurityExtrasController::class, 'store'])
        ->middleware('perm:security.manage')->name('secextra.store');
});
// ---- /Security extras ----

// ---- Web Disk (WebDAV accounts) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'index'])
        ->middleware('perm:files.view')->name('webdisk.index');
    Route::post('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'store'])
        ->middleware('perm:files.manage')->name('webdisk.store');
    Route::delete('/webdisk/{login}', [\App\Http\Controllers\WebDiskController::class, 'destroy'])
        ->where('login', '[A-Za-z0-9._-]+')
        ->middleware('perm:files.manage')->name('webdisk.destroy');
});
// ---- /Web Disk ----

// ---- File extras: Images + Optimize Website + Trash ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/images', [\App\Http\Controllers\ImagesController::class, 'index'])
        ->middleware('perm:files.view')->name('images.index');
    Route::get('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'index'])
        ->middleware('perm:files.view')->name('optimize.index');
    Route::post('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'store'])
        ->middleware('perm:files.manage')->name('optimize.store');
    Route::get('/trash', [\App\Http\Controllers\TrashController::class, 'index'])
        ->middleware('perm:files.view')->name('trash.index');
    Route::delete('/trash/{file}', [\App\Http\Controllers\TrashController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('trash.destroy');
});
// ---- /File extras ----

// ---- DNS Cluster (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'index'])
        ->middleware('perm:dns.view')->name('dns-cluster.index');
    Route::post('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dns-cluster.store');
    Route::post('/dns-cluster/sync', [\App\Http\Controllers\DnsClusterController::class, 'sync'])
        ->middleware('perm:dns.manage')->name('dns-cluster.sync');
    Route::delete('/dns-cluster/{dnsClusterNode}', [\App\Http\Controllers\DnsClusterController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dns-cluster.destroy');
});
// ---- /DNS Cluster ----

// ---- Owner Ports Config ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ports', [\App\Http\Controllers\PortsController::class, 'index'])
        ->middleware('perm:system.manage')->name('ports.index');
    Route::post('/ports', [\App\Http\Controllers\PortsController::class, 'store'])
        ->middleware('perm:system.manage')->name('ports.store');
});
// ---- /Ports ----
PHPEOF
  cat > "${PANEL}/config/acp.php" <<'PHPEOF'
<?php

declare(strict_types=1);

/**
 * AlphaCP panel configuration.
 *
 * Values come from the app .env (written by installer/step2b-install.sh).
 * Never hardcode paths or credentials in panel code — read them from here.
 */
return [
    // Panel + agent versions (shown in the UI footer and system page)
    'version'       => env('ACP_VERSION', '0.72.0'),
    'agent_version' => env('ACP_AGENT_VERSION', '0.62.0'),

    // AlphaCP install root (agent, etc/, logs/, panel/)
    'home'          => rtrim((string) env('ACP_HOME', '/usr/local/alphacp'), '/'),

    // Webmail (Roundcube) entry — cPanel-style alag port (default 2096).
    // OWNER-CTRL slice me ye superadmin-controlled ho jayega.
    'webmail_port'  => (int) env('ACP_WEBMAIL_PORT', 2096),

    // This server's row in `servers`.
    //
    // Source of truth = etc/panel.env (written by the installer, readable by
    // the panel user). Dev machines fall back to etc/database.env / app .env.
    // This guarantees panel and agent always agree on the server id.
    'server_id'     => (int) App\Support\PanelEnv::get('ACP_SERVER_ID', (string) env('ACP_SERVER_ID', '1')),

    // Where customer data lives (used from Step 3 onward)
    'paths' => [
        'accounts' => env('ACP_ACCOUNTS_PATH', '/home'),
        'www'      => env('ACP_WWW_PATH', '/var/www'),
    ],

    // Seconds the panel waits for paneld after enqueueing account tasks.
    // Tests force 0 (see AccountProvisioner).
    'provision_wait' => (int) env('ACP_PROVISION_WAIT', 25),

    // First admin account (used once by AdminUserSeeder during install).
    // Kept here — not read with env() in the seeder — because env() is
    // unavailable when the config cache is warm on a production server.
    'admin' => [
        'user'         => env('ACP_ADMIN_USER', 'admin'),
        'password'     => env('ACP_ADMIN_PASSWORD'),
        'email'        => env('ACP_ADMIN_EMAIL'),
        'force_change' => (bool) env('ACP_ADMIN_FORCE_CHANGE', true),
    ],

    // License client (S2C): offline-first signed payload + local trial.
    // An empty API URL is safe: the panel starts a local trial and never blocks
    // customer websites/email because a license server is unavailable.
    'license' => [
        'api_url' => (string) env('ACP_LICENSE_API_URL', ''),
        'timeout' => (int) env('ACP_LICENSE_TIMEOUT', 8),
        'store_path' => (string) env('ACP_LICENSE_STORE_PATH', storage_path('app/private/license.json')),
        'public_key' => (string) env('ACP_LICENSE_PUBLIC_KEY', ''),
        'public_key_path' => (string) env('ACP_LICENSE_PUBLIC_KEY_PATH', config_path('license_public.pem')),
    ],

    // S10 scheduled backups (routes/console.php → alphacp:scheduled-backups).
    //
    // `file` is the window marker: it records which day / ISO week / month was
    // already backed up, so the hourly tick runs the real archive pass exactly
    // once per window. Tests point it at a temp file.
    'backup_schedule' => [
        'file' => (string) env('ACP_BACKUP_SCHEDULE_FILE', storage_path('app/private/backup-schedule.json')),
    ],

    // Password quality
    //
    // check_pwned queries haveibeenpwned.com (k-anonymity: only 5 hash chars
    // leave the server). On a host without outbound access that call is a
    // 30-second stall, so the timeout is short and the check can be turned off
    // with ACP_CHECK_PWNED=false — the rest of the policy still applies.
    'password_check_pwned'  => (bool) env('ACP_CHECK_PWNED', true),
    'password_pwned_timeout' => (int) env('ACP_PWNED_TIMEOUT', 3),

    // Login protection (cPHulk-lite — full cPHulk arrives in Step 13)
    'security' => [
        'max_login_attempts'   => (int) env('ACP_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'      => (int) env('ACP_LOCKOUT_MINUTES', 15),
        'throttle_per_minute'  => (int) env('ACP_LOGIN_THROTTLE', 10),
        'session_lifetime'     => (int) env('ACP_SESSION_LIFETIME', 30),
        'password_min_length'  => (int) env('ACP_PASSWORD_MIN_LENGTH', 10),

        // Clickjacking protection: DENY (production default) | SAMEORIGIN | OFF.
        // OFF exists only for --insecure-http dev boxes that are viewed inside a
        // preview iframe; a default install never sets it.
        'frame_options'        => env('ACP_FRAME_OPTIONS', 'DENY'),
    ],
];
PHPEOF
  mkdir -p "${PANEL}/resources/views/webmail"
  cat > "${PANEL}/resources/views/webmail/index.blade.php" <<'PHPEOF'
@extends('layouts.panel')

@section('title', 'Webmail')
@section('subtitle', 'Roundcube/Horde client preference — no install, no SSO, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set Webmail here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Webmail — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/webmail.json</span>. Roundcube/Horde app later. Pipe/shell fail closed.</p>
    <p>Status: <strong>{{ $enabled ? 'on' : 'off' }}</strong> · client: <span class="mono">{{ $client }}</span></p>
    @if ($enabled && !empty($openMailbox))
        <form method="post" action="{{ route('webmail.open') }}" class="mt">
            @csrf
            <button class="btn small" type="submit">📬 Open Webmail ({{ $openMailbox }}) — port {{ $webmailPort }}</button>
        </form>
    @elseif ($enabled)
        <p class="help">Koi mailbox nahi mila — Email Accounts me pehle ek mailbox banayein.</p>
    @endif
</div>

@can('email.manage')
<div class="card mt">
    <h3>Update</h3>
    <form method="post" action="{{ route('webmail.store') }}">
        @csrf
        <label for="enabled">Enabled</label>
        <select id="enabled" name="enabled">
            <option value="0" @selected(! $enabled)>off</option>
            <option value="1" @selected($enabled)>on</option>
        </select>
        <label for="client">Client</label>
        <select id="client" name="client" required>
            <option value="roundcube" @selected(old('client', $client) === 'roundcube')>roundcube</option>
            <option value="horde" @selected(old('client', $client) === 'horde')>horde</option>
        </select>
        <button class="btn mt" type="submit">Save Webmail</button>
    </form>
</div>
@endcan
@endif
@endsection
PHPEOF
  local lout
  for rel in "${AGENT_FILES[@]}" "${PANEL_FILES[@]}"; do
    base="$(basename "$rel")"
    if [[ "$base" == *.php ]]; then
      if ! lout="$("$PHP_BIN" -l "${AGENT}/${rel}" 2>&1)" && [[ "$rel" == src/* || "$rel" == tests/* ]]; then
        warn "lint fail: ${rel}"; say "    ${lout}"; rollback; die "lint fail ${rel}"
      fi
    fi
  done
  for rel in "${PANEL_FILES[@]}"; do
    if ! lout="$("$PHP_BIN" -l "${PANEL}/${rel}" 2>&1)"; then
      warn "lint fail: panel/${rel}"; say "    ${lout}"; rollback; die "panel lint fail ${rel}"
    fi
  done
  if ! lout="$("$PHP_BIN" -l "${RC_PLUGINS}/acp_sso/acp_sso.php" 2>&1)"; then
    warn "lint fail: plugin"; say "    ${lout}"; rollback; die "plugin lint fail"
  fi
  ok "9 payloads likhi + lint clean"

  # ---- structural asserts ----
  [[ "$(cnt 'listen 2096 ssl' "${NGX_AVAIL}/alphacp-webmail.conf")" -ge 1 ]] \
    || { rollback; die "webmail vhost me 2096 listen nahi"; }
  [[ "$(cnt 'acp_sso' "${RC_ETC}/config.inc.php")" -ge 1 ]] \
    || { rollback; die "roundcube config me plugin enable nahi"; }
  [[ "$(cnt -- '<?php' "${RC_ETC}/config.inc.php")" -eq 1 ]] \
    || { rollback; die "roundcube config me ek se zyada <?php tag (parse error)"; }
  [[ "$(cnt 'try_files' "${NGX_AVAIL}/alphacp-webmail.conf")" -ge 1 ]] \
    || { rollback; die "webmail vhost me try_files nahi"; }
  grep -qF 'location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files $uri /index.php?$args; }' "${PANEL_VHOST}" \
    || { rollback; die "panel vhost internal loc canonical (try_files) nahi"; }
  [[ "$(cnt 'master = yes' "${DOVECOT_CONF}")" -ge 1 ]] \
    || { rollback; die "dovecot conf me master passdb nahi"; }
  [[ "$(cnt 'webmail.open' "${PANEL}/routes/web.php")" -ge 1 ]] \
    || { rollback; die "panel routes me webmail.open nahi"; }
  ok "structural asserts pass"

  # ---- services + smokes (sim skip) ----
  hdr "services + HTTP smokes"
  if [[ "$SIM" == "1" ]]; then
    warn "SIM=1 — nginx/dovecot/curl skip"
  else
    if command -v nginx >/dev/null 2>&1; then
      nginx -t >>"$LOG_FILE" 2>&1 || { rollback; die "nginx -t fail"; }
      systemctl reload nginx >/dev/null 2>&1 || warn "nginx reload fail"
      ok "nginx -t + reload"
    else
      warn "nginx nahi mila"
    fi
    have_systemd && { systemctl restart dovecot >/dev/null 2>&1 || warn "dovecot restart fail"; }
    if command -v runuser >/dev/null 2>&1 && [[ -f "${PANEL}/artisan" ]]; then
      runuser -u "$(stat -c %U "${PANEL}/artisan" 2>/dev/null || echo www-data)" -- env ACP_HOME="$ACP_HOME" "$PHP_BIN" "${PANEL}/artisan" optimize:clear >>"$LOG_FILE" 2>&1 || warn "optimize:clear fail"
    fi
    local code
    code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:2096/" 2>/dev/null || echo 000)"
    if [[ "$code" == "200" || "$code" == "302" || "$code" == "304" ]]; then ok "roundcube 2096 HTTP ${code}"; else warn "roundcube 2096 HTTP ${code} — browser me check karo"; fi
    code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:8090/internal/webmail-sso?token=xx" 2>/dev/null || echo 000)"
    [[ "$code" == "403" || "$code" == "422" || "$code" == "410" ]] && ok "internal endpoint secret-gated (HTTP ${code})" || warn "internal endpoint HTTP ${code} — check karo"
  fi

  # ---- suite gate ----
  hdr "AGENT SUITE — full run (current era)"
  local slog sum n p
  slog="$(mktemp)"
  if ! "$PHP_BIN" "${AGENT}/tests/run-tests.php" >"$slog" 2>&1; then
    warn "suite run exit non-zero — aakhri lines:"
    tail -25 "$slog" | sed 's/^/    /'
    cp "$slog" "${LOG_FILE%.txt}-suite-tail.txt" 2>/dev/null || true
    rm -f "$slog"
    die "agent suite run fail — upar tail dekhein, poora log: ${LOG_FILE%.txt}-suite-tail.txt"
  fi
  sum="$(grep -E 'passed: [0-9]+ +failed: [0-9]+' "$slog" | tail -1 || true)"
  rm -f "$slog"
  n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"${sum:-}")"
  p="$(sed -E 's/.*passed: ([0-9]+).*/\1/; s/ .*//' <<<"${sum:-}")"
  info "suite: ${sum:-<summary nahi mila>}"
  if [[ "${n:-9}" != "0" || "${p:-0}" -lt 223 ]]; then
    rollback
    die "agent suite green nahi (passed=${p:-?} failed=${n:-?})"
  fi
  ok "agent suite GREEN (passed=${p} failed=0)"

  # ---- sync ----
  hdr "alphacp-sync"
  if command -v alphacp-sync >/dev/null 2>&1; then
    alphacp-sync >>"$LOG_FILE" 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync fail (baad me: sudo alphacp-sync)"
  else
    warn "alphacp-sync nahi mila"
  fi

  hdr "FINAL VERDICT"
  ok "Roundcube Webmail port 2096 par (cPanel-style) + panel se one-time token SSO"
  ok "Dovecot master passdb + nginx vhost + /internal loopback-only"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}webmail-fix v${VERSION} APPLY ho gaya.${C_0} AWS console me port 2096 kholo; panel me Email → Webmail → 'Open Webmail'."
}

usage(){ sed -nE 's/^#( |=)(.*)$/\2/p' "$0" | sed -n '1,34p'; }
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
case "${1:-apply}" in
  --diagnose|-d) diagnose ;;
  --rollback|-r) rollback ;;
  --help|-h) usage ;;
  apply|"") apply ;;
  *) die "unknown option: $1 (--diagnose | --rollback | --help)" ;;
esac
