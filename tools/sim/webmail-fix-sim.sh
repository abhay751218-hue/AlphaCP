#!/usr/bin/env bash
# =============================================================================
#  webmail-fix-sim — installer/webmail-fix.sh v1.0 ka sandbox proof (ACP_SIM=1:
#  apt/systemctl/nginx -t/curl/mysql skip; system paths env se fake).
#  PRE = 2dcfd2f (P-UI-4a code se pehle: koi SSO/master-passdb/plugin nahi) →
#  reproduce → diagnose → apply (9 payloads byte-for-byte era fdaf253 + suite
#  223/0) → diagnose post → rollback → re-apply.
#  Usage: bash tools/sim/webmail-fix-sim.sh
# =============================================================================
set -uo pipefail
unset PHP 2>/dev/null || true
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="${REPO}/installer/webmail-fix.sh"
PHPBIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"
PRE="2dcfd2f"   # pre-P-UI-4a (live state)
ERA="fdaf253"   # P-UI-4a code commit (payloads)

pass=0; fail=0
t(){ local d="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); printf '  \033[0;32mok\033[0   %s\n' "$d";
  else fail=$((fail+1)); printf '  \033[0;31mFAIL\033[0 %s\n' "$d"; fi
}
has(){ grep -qF "$2" <<<"$1"; }
hasre(){ grep -qE "$2" <<<"$1"; }
[[ -f "$FIX" ]] || { echo "installer/webmail-fix.sh nahi mila — python3 tools/build-webmail-fix.py"; exit 2; }
[[ -n "$PHPBIN" ]] || { echo "php nahi mila (PHPBIN=/path)"; exit 2; }

FAKE="$(mktemp -d /tmp/acp-wmfix.XXXXXX)"; chmod 0755 "$FAKE"
trap 'rm -rf "$FAKE"' EXIT
mkdir -p "$FAKE/logs" "$FAKE/releases" "$FAKE/etc" \
         "$FAKE/agent/tests" "$FAKE/panel/app/Http/Controllers" \
         "$FAKE/panel/routes" "$FAKE/panel/config" "$FAKE/panel/resources/views/webmail" \
         "$FAKE/rc/etc" "$FAKE/rc/plugins" "$FAKE/ngx/avail" "$FAKE/ngx/en" "$FAKE/dovecot"
cp -a "${REPO}/agent/src" "${REPO}/agent/config" "${REPO}/agent/bin" "$FAKE/agent/" 2>/dev/null || true
AG_REL=(src/MailServer.php tests/run-tests.php)
PA_REL=(app/Http/Controllers/WebmailController.php app/Http/Controllers/WebmailSsoController.php routes/web.php config/acp.php resources/views/webmail/index.blade.php)
PANEL_REL="server-snapshot/files/usr/local/alphacp/panel"
for f in "${AG_REL[@]}"; do git -C "$REPO" show "${PRE}:agent/${f}" > "$FAKE/agent/${f}"; git -C "$REPO" show "${ERA}:agent/${f}" > "$FAKE/.era-ag-${f//\//_}"; done
git -C "$REPO" show "${ERA}:agent/tests/FakeCommandExecutor.php" > "$FAKE/agent/tests/FakeCommandExecutor.php"   # payload nahi — suite ko chahiye
for f in "${PA_REL[@]}"; do
  git -C "$REPO" show "${PRE}:${PANEL_REL}/${f}" > "$FAKE/panel/${f}" 2>/dev/null || true
  git -C "$REPO" show "${ERA}:${PANEL_REL}/${f}" > "$FAKE/.era-pa-${f//\//_}"
done
# fake system files
printf 'server {\n    listen 8090 ssl;\n    server_name panel.local;\n    root /x;\n}\n' > "$FAKE/ngx/avail/alphacp-panel.conf"
printf '# managed dovecot conf\npassdb {\n  driver = passwd-file\n  args = scheme=BLF-CRYPT /x/users\n}\n' > "$FAKE/dovecot/99-alphacp.conf"
cat > "$FAKE/rc/etc/config.inc.php" <<'RCEOF'
<?php
/* Debian-style roundcube config (PRE) */
$config['db_dsnw'] = 'sqlite:///@/var/lib/roundcube/db.sqlite3?mode=0640';
<?php
<?php
/* ACP_WEBMAIL_START */
$config['imap_host'] = 'localhost:143';
/* ACP_WEBMAIL_END */
RCEOF
cp "$FAKE/ngx/avail/alphacp-panel.conf" "$FAKE/.pre-panelvhost"
cp "$FAKE/dovecot/99-alphacp.conf" "$FAKE/.pre-dovecot"

export ACP_HOME="$FAKE" ACP_SIM=1 NGX_VER="1.24.0" \
       RC_ETC="$FAKE/rc/etc" RC_PLUGINS="$FAKE/rc/plugins" \
       NGX_AVAIL="$FAKE/ngx/avail" NGX_EN="$FAKE/ngx/en" \
       PANEL_VHOST="$FAKE/ngx/avail/alphacp-panel.conf" \
       DOVECOT_CONF="$FAKE/dovecot/99-alphacp.conf" \
       WWW_GROUP=root
VHOST="$FAKE/ngx/avail/alphacp-webmail.conf"
DCONF="$FAKE/dovecot/99-alphacp.conf"
PLUGIN="$FAKE/rc/plugins/acp_sso/acp_sso.php"
RCCONF="$FAKE/rc/etc/config.inc.php"
PVHOST="$FAKE/ngx/avail/alphacp-panel.conf"
grepc(){ grep -c "$@" 2>/dev/null || true; }

echo "== reproduce: PRE state (koi webmail SSO infra nahi) =="
t "pre plugin MISSING"            test ! -f "$PLUGIN"
t "pre vhost MISSING"             test ! -f "$VHOST"
t "pre secret MISSING"            test ! -f "$FAKE/etc/webmail-sso.secret"
t "pre master pw MISSING"         test ! -f "$FAKE/etc/webmail-master.pw"
t "pre dovecot me master nahi"    test "$(grepc 'master = yes' "$DCONF")" -eq 0
t "pre routes me webmail.open nahi" test "$(grepc 'webmail.open' "$FAKE/panel/routes/web.php")" -eq 0

echo "== --diagnose (pre) =="
D1="$(bash "$FIX" --diagnose 2>&1)"
t "diagnose pre: plugin MISSING"  has "$D1" "roundcube plugin PRESENT   : MISSING"
t "diagnose pre: vhost 0"         hasre "$D1" "nginx webmail vhost 2096   : 0"
t "diagnose pre: secret MISSING"  has "$D1" "sso secret file            : MISSING"

echo "== APPLY (SIM) =="
A1="$(bash "$FIX" 2>&1)"; A1_RC=$?
echo "$A1" | grep -E 'lint clean|asserts|backup:|suite:|GREEN|FINAL|✖' | sed 's/^/    /'
t "apply exit 0"                  test "$A1_RC" -eq 0
t "lint clean msg"                has "$A1" "9 payloads likhi + lint clean"
t "asserts pass"                  has "$A1" "structural asserts pass"
t "suite GREEN 223"               has "$A1" "passed=223 failed=0"
t "backup bana"                   bash -c "ls -1d '$FAKE'/releases/webmailfix-* >/dev/null 2>&1"
t "vhost 2096 likha"              test "$(grepc 'listen 2096 ssl' "$VHOST")" -ge 1
t "1.24 par 'http2 on;' NAHI"     test "$(grepc 'http2 on;' "$VHOST")" -eq 0
t "1.24 par listen http2 suffix"  test "$(grepc 'listen 2096 ssl http2;' "$VHOST")" -ge 1
t "rc config me plugin"           test "$(grepc 'acp_sso' "$RCCONF")" -ge 1
t "rc config me EK <?php (stray <?php self-heal)" test "$(grepc -- '<?php' "$RCCONF")" -eq 1
t "internal loc canonical (try_files)" grep -qF 'location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files $uri /index.php?$args; }' "$FAKE/ngx/avail/alphacp-panel.conf"
t "rc config imap 143"            test "$(grepc "imap_host'] = 'localhost:143'" "$RCCONF")" -ge 1
t "dovecot master passdb"         test "$(grepc 'master = yes' "$DCONF")" -ge 1
t "panel vhost internal lock"     test "$(grepc 'ACP_INTERNAL_START' "$PVHOST")" -ge 1
t "secret file bana"              test -f "$FAKE/etc/webmail-sso.secret"
t "master pw + plain baney"       bash -c "test -f '$FAKE/etc/webmail-master.pw' && test -f '$FAKE/etc/webmail-master.plain'"
t "master pw BLF-CRYPT"           test "$(grepc '{BLF-CRYPT}' "$FAKE/etc/webmail-master.pw")" -ge 1
t "plugin byte-for-byte"          bash -c "cmp -s '$PLUGIN' '$REPO/webmail/roundcube/acp_sso.php'"
t "plugin conf byte-for-byte"     bash -c "cmp -s '$FAKE/rc/plugins/acp_sso/config.inc.php' '$REPO/webmail/roundcube/config.inc.php'"
t "agent MailServer byte=ERA"     bash -c "cmp -s '$FAKE/agent/src/MailServer.php' '$FAKE/.era-ag-src_MailServer.php'"
t "tests byte=ERA"                bash -c "cmp -s '$FAKE/agent/tests/run-tests.php' '$FAKE/.era-ag-tests_run-tests.php'"
t "panel routes byte=ERA"         bash -c "cmp -s '$FAKE/panel/routes/web.php' '$FAKE/.era-pa-routes_web.php'"
t "sso ctrl byte=ERA"             bash -c "cmp -s '$FAKE/panel/app/Http/Controllers/WebmailSsoController.php' '$FAKE/.era-pa-app_Http_Controllers_WebmailSsoController.php'"
t "plugin lint"                   "$PHPBIN" -l "$PLUGIN"

echo "== --diagnose (post) =="
D2="$(bash "$FIX" --diagnose 2>&1)"
t "diagnose post: plugin PRESENT" has "$D2" "roundcube plugin PRESENT   : PRESENT"
t "diagnose post: vhost >=1"      hasre "$D2" "nginx webmail vhost 2096   : [1-9]"
t "diagnose post: master >=1"     hasre "$D2" "dovecot conf master block  : [1-9]"
t "diagnose post: blocks 223"     hasre "$D2" "test blocks                : 223"

echo "== --rollback =="
R1="$(bash "$FIX" --rollback 2>&1)"; R1_RC=$?
t "rollback exit 0"               test "$R1_RC" -eq 0
t "agent MailServer PRE wapas"    bash -c "cmp -s '$FAKE/agent/src/MailServer.php' <(git -C '$REPO' show '${PRE}:agent/src/MailServer.php')"
t "panel routes PRE wapas"        bash -c "cmp -s '$FAKE/panel/routes/web.php' <(git -C '$REPO' show '${PRE}:${PANEL_REL}/routes/web.php')"
t "vhost symlink gone"            test ! -e "$FAKE/ngx/en/alphacp-webmail.conf"
t "plugin gone"                   test ! -f "$PLUGIN"
t "secrets gone"                  bash -c "test ! -f '$FAKE/etc/webmail-sso.secret' && test ! -f '$FAKE/etc/webmail-master.pw'"
t "dovecot PRE wapas"             bash -c "cmp -s '$DCONF' '$FAKE/.pre-dovecot'"
t "panel vhost PRE wapas"         bash -c "cmp -s '$PVHOST' '$FAKE/.pre-panelvhost'"

# v1.1-jaisa STALE internal loc (markers ke saath, bina try_files) — self-heal test
python3 - "$PVHOST" <<'PY'
import sys, re
p = sys.argv[1]; src = open(p).read()
src = re.sub(r"location /internal/ \{[^\n]*\}", "location /internal/ { allow 127.0.0.1; allow ::1; deny all; }", src)
open(p, "w").write(src)
PY
t "stale loc seeded"               bash -c "! grep -qF 'deny all; try_files' '$PVHOST'"

echo "== re-apply =="
A2="$(bash "$FIX" 2>&1)"; A2_RC=$?
t "re-apply exit 0"               test "$A2_RC" -eq 0
t "re-apply vhost wapas"          test "$(grepc 'listen 2096 ssl' "$VHOST")" -ge 1
t "re-apply plugin wapas"         test -f "$PLUGIN"
t "stale internal loc self-heal"  grep -qF 'location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files $uri /index.php?$args; }' "$PVHOST"

echo ""
echo "----------------------------------------"
echo "webmail-fix-sim: passed=${pass} failed=${fail}"
[[ "$fail" -eq 0 ]] || exit 1
exit 0
