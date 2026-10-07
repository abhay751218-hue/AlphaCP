#!/usr/bin/env bash
# AlphaCP entry-marker v0.1.0: prepare trusted FastCGI identity only; no port/auth changes.
set -Eeuo pipefail
VERSION=0.1.0
printf 'AlphaCP entry-marker v%s — FastCGI preparation only; ports/auth unchanged\n' "$VERSION"
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
CONF="${ACP_ENTRY_CONF:-/etc/nginx/sites-available/alphacp-panel.conf}"
BACKUPS="${ACP_ENTRY_BACKUPS:-/var/backups/alphacp-entry}"
LOCK="${ACP_ENTRY_LOCK:-/run/alphacp-entry-marker.lock}"
for tool in nginx ss curl python3 flock systemctl; do command -v "$tool" >/dev/null || { echo "Missing tool: $tool" >&2; exit 1; }; done
exec 9>"$LOCK"; flock -n 9 || { echo 'Another entry update is running.' >&2; exit 1; }
[[ -f "$CONF" ]] || { echo "Missing panel vhost: $CONF" >&2; exit 1; }
nginx -t
DUMP=$(nginx -T 2>&1)
# Ensure the edited file is actually loaded, not merely present on disk.
python3 - "$CONF" 3<<<"$DUMP" <<'PYIDENTITY'
import os, re, sys
conf = sys.argv[1]
with os.fdopen(3) as dump:
    paths = re.findall(r'^# configuration file (.+):$', dump.read(), re.M)
for path in paths:
    try:
        if os.path.samefile(conf, path):
            print('Loaded panel vhost verified (same file): ' + path)
            break
    except OSError:
        continue
else:
    raise SystemExit('Panel vhost is not loaded by nginx (no same-file match); refusing change.')
PYIDENTITY
health() {
    local port="$1" body
    body=$(curl --noproxy '*' --silent --show-error --fail --insecure --connect-timeout 5 --max-time 20 "https://127.0.0.1:${port}/login") || return 1
    grep -q 'AlphaCP' <<<"$body"
}
health 8090 || { echo 'Existing 8090 login health failed; no changes made.' >&2; exit 1; }
health 2087 || { echo 'Existing 2087 login health failed; no changes made.' >&2; exit 1; }
mkdir -p "$BACKUPS"; chmod 0700 "$BACKUPS"
BACKUP=$(mktemp "$BACKUPS/entry-marker-v${VERSION}.XXXXXX")
cp -p "$CONF" "$BACKUP"
CHANGED=0
rollback() {
    local status=$?
    trap - ERR INT TERM
    if [[ $CHANGED -eq 1 ]]; then
        echo "FAILED — restoring $BACKUP"
        cp -p "$BACKUP" "$CONF"
        if nginx -t && systemctl reload nginx; then
            echo 'Original nginx config restored/reloaded.'
        else
            echo "URGENT: rollback reload failed; backup: $BACKUP" >&2
        fi
    fi
    exit "${status:-1}"
}
trap rollback ERR
trap 'false' INT TERM
# Fail closed if shape differs; no blanket sed and no edits to distro config.
CHANGED=1
python3 - "$CONF" <<'PY'
import pathlib, re, sys
p=pathlib.Path(sys.argv[1]); text=p.read_text()
if not re.search(r'^\s*listen\s+8090\s+ssl;', text, re.M):
    raise SystemExit('Expected existing TLS 8090 listener missing')
if text.count('# ACP_PORTS_START') != 1 or text.count('# ACP_PORTS_END') != 1:
    raise SystemExit('Expected unique panel-port markers missing')
if 'root /usr/local/alphacp/panel/public;' not in text:
    raise SystemExit('Unexpected panel document root')
if not re.search(r'^\s*listen\s+2087\s+ssl;', text, re.M):
    raise SystemExit('Expected existing TLS 2087 listener missing')
anchor = '        fastcgi_param SERVER_PORT     $server_port;'
if text.count(anchor) != 1:
    raise SystemExit('Expected unique trusted SERVER_PORT parameter missing')
marker = '        fastcgi_param ACP_ENTRY_PORT  $server_port; # AlphaCP entry-marker v0.1.0'
if 'ACP_ENTRY_PORT' in text:
    if text.count(marker) != 1:
        raise SystemExit('Unrecognized entry marker; refusing replacement')
else:
    text = text.replace(anchor, anchor + '\n' + marker, 1)
p.write_text(text)
PY
nginx -t
systemctl reload nginx
health 8090
health 2087
[[ -n $(ss -Hltn 'sport = :2087') ]] || { echo '2087 did not bind after reload.' >&2; false; }
trap - ERR INT TERM
printf 'ENTRY MARKER READY v%s — existing 8090 + 2087 checks passed\nBackup: %s\n' "$VERSION" "$BACKUP"
echo 'Only trusted FastCGI identity prepared. No panel code, auth flag, ports or firewall changed; 2083/2096 remain unopened.'
if command -v alphacp-sync >/dev/null; then alphacp-sync || echo 'WARNING: snapshot sync failed; listener update completed.'; fi
