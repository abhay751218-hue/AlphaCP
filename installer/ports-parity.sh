#!/usr/bin/env bash
# ============================================================================
# AlphaCP — cPanel PORTS PARITY installer  v1.0
# cPanel jaise ports: 2082/2083 (cPanel), 2086/2087 (WHM), 2095/2096 (Webmail).
# SSL ports (2083/2087/2096) same panel vhost par; HTTP (2082/2086/2095) redirect.
# nginx -t fail ho to config AUTO-REVERT — panel kabhi nahi tootega. Idempotent.
# ============================================================================
set -euo pipefail
CONF=/etc/nginx/sites-available/alphacp-panel.conf
echo "=================================================="
echo " AlphaCP cPanel Ports Parity installer  v1.0"
echo "=================================================="

if grep -q "listen 2083 ssl" "$CONF" 2>/dev/null; then
  echo "[OK] cPanel ports pehle se configured — skipping"
else
  echo "== Step 1: vhost me cPanel SSL ports add =="
  cp "$CONF" "$CONF.bak"
  sed -i 's|^    listen 8090 ssl;|    listen 8090 ssl;\n    listen 2083 ssl;\n    listen 2087 ssl;\n    listen 2096 ssl;|' "$CONF"
  sed -i 's|^    listen \[::\]:8090 ssl;|    listen [::]:8090 ssl;\n    listen [::]:2083 ssl;\n    listen [::]:2087 ssl;\n    listen [::]:2096 ssl;|' "$CONF"
  cat >> "$CONF" <<'ACP_PORTS_EOF'

# ---- cPanel ports parity: HTTP -> HTTPS redirect (2082/2086/2095) ----
map $server_port $acp_ssl_port {
    default 2083;
    2082    2083;
    2086    2087;
    2095    2096;
}
server {
    listen 2082; listen 2086; listen 2095;
    listen [::]:2082; listen [::]:2086; listen [::]:2095;
    server_name _;
    return 301 https://$host:$acp_ssl_port$request_uri;
}
# ---- /cPanel ports parity ----
ACP_PORTS_EOF
  echo "[OK] cPanel ports added to vhost"
fi

echo "== Step 2: firewall (ufw) ports allow =="
for p in 2082 2083 2086 2087 2095 2096; do
  ufw allow "$p/tcp" >/dev/null 2>&1 || true
done
echo "[OK] ufw: 2082 2083 2086 2087 2095 2096 allowed"

echo "== Step 3: validate + reload (fail par revert) =="
if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
  echo "[OK] nginx config valid + reloaded"
else
  if [[ -f "$CONF.bak" ]]; then cp "$CONF.bak" "$CONF"; fi
  echo "[FAIL] nginx -t failed — config AUTO-REVERT ho gaya (panel safe)"
  exit 1
fi

echo "=================================================="
echo " ==> PORTS PARITY v1.0 APPLIED"
echo "   cPanel: 2083(https)/2082  WHM: 2087/2086  Webmail: 2096/2095"
echo "=================================================="
alphacp-sync || true
