#!/usr/bin/env bash
# ============================================================================
# AlphaCP — APPLY PORTS (owner-configured) installer  v1.0
# /usr/local/alphacp/var/ports.json padhta hai (owner panel se likha gaya) aur
# nginx vhost me marker-based listen lines + HTTP-redirect block regenerate karta
# hai. nginx -t fail ho to AUTO-REVERT. Idempotent (har run ports.json se sync).
# ============================================================================
set -euo pipefail
CONF=/etc/nginx/sites-available/alphacp-panel.conf
PORTS=/usr/local/alphacp/etc/ports.json
[ -f "$PORTS" ] || PORTS=/usr/local/alphacp/var/ports.json
echo "=================================================="
echo " AlphaCP Apply Ports installer  v1.0"
echo "=================================================="

if [[ ! -f "$PORTS" ]]; then
  echo "[WARN] ports.json nahi mila — owner panel (/ports) se pehle save karo."
  echo "       Default: sirf 8090 chalta rahega."
fi

echo "== Step 1: nginx vhost ko ports.json se sync =="
cp "$CONF" "$CONF.bak"
ACP_CONF="$CONF" ACP_PORTS="$PORTS" python3 - <<'PY'
import json, os, re
conf_path = os.environ['ACP_CONF']; ports_path = os.environ['ACP_PORTS']
try:
    cfg = json.load(open(ports_path))
except Exception:
    cfg = {'ssl': [8090], 'http': []}
ssl  = sorted(set(int(p) for p in cfg.get('ssl', [8090])))
http = sorted(set(int(p) for p in cfg.get('http', [])))
conf = open(conf_path).read()
# purane blocks hatao
conf = re.sub(r"\n# ACP_PORTS_START.*?# ACP_PORTS_END\n", "\n", conf, flags=re.S)
conf = re.sub(r"\n# ACP_HTTP_START.*?# ACP_HTTP_END\n", "\n", conf, flags=re.S)
# ssl listen block (8090 ke baad)
lines = []
for p in ssl:
    if p == 8090:
        continue
    lines.append(f"    listen {p} ssl;\n    listen [::]:{p} ssl;\n")
ports_block = "\n# ACP_PORTS_START\n" + "".join(lines) + "# ACP_PORTS_END\n"
conf = conf.replace("    listen 8090 ssl;\n", "    listen 8090 ssl;\n" + ports_block, 1)
# http redirect block
if http:
    maps = "\n".join(f"    {h}    {h+1};" for h in http)
    listens = " ".join(f"listen {h};" for h in http)
    listens6 = " ".join(f"listen [::]:{h};" for h in http)
    http_block = (
        "\n# ACP_HTTP_START\n"
        "map $server_port $acp_ssl_port {\n    default 2083;\n" + maps + "\n}\n"
        "server {\n    " + listens + "\n    " + listens6 + "\n    server_name _;\n"
        "    return 301 https://$host:$acp_ssl_port$request_uri;\n}\n"
        "# ACP_HTTP_END\n"
    )
    conf += http_block
open(conf_path, 'w').write(conf)
print("[OK] vhost synced with ports.json ->", ssl, http)
PY

echo "== Step 2: ufw ports allow =="
for p in $(ACP_PORTS="$PORTS" python3 -c "import json,os; c=json.load(open(os.environ['ACP_PORTS'])); print(' '.join(map(str, sorted(set(c.get('ssl',[8090]))|set(c.get('http',[]))))))" 2>/dev/null || echo 8090); do
  ufw allow "$p/tcp" >/dev/null 2>&1 || true
done
echo "[OK] ufw updated"

echo "== Step 3: validate + reload (fail par revert) =="
if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
  echo "[OK] nginx valid + reloaded"
else
  cp "$CONF.bak" "$CONF"; echo "[FAIL] nginx -t failed — AUTO-REVERT (panel safe)"; exit 1
fi

echo "=================================================="
echo " ==> APPLY PORTS v1.0 DONE"
echo "=================================================="
alphacp-sync || true
