#!/usr/bin/env bash
# =============================================================================
#  AlphaCP GO-LIVE — domain connect hone par website PUBLIC karna (B2b part 2)
# -----------------------------------------------------------------------------
#  KAB CHALANA: jab domain kharid lo aur uska A-record is server ke IP par
#  point kar do (DNS propagate hone ke 10-30 min baad).
#
#  Usage (root):  bash alphacp-go-live.sh --domain alphacp.com --email tum@mail.com
#
#  Kya karta hai:
#    1. nginx :80 vhost (domain) — Let's Encrypt verification ke liye
#    2. certbot se FREE asli SSL certificate (auto-renew included)
#    3. :443 public vhost — website bina password ke (customers ke liye)
#    4. :2096 private preview waisa hi rehta hai (owner backup access)
#  Tab tak ye script chalane ki zaroorat NAHI hai.
# =============================================================================
set -u -o pipefail

WEB_ROOT="${WEB_ROOT:-/usr/local/alphacp/website}"
NGX_AVAIL="${NGX_AVAIL:-/etc/nginx/sites-available}"
NGX_ENABLED="${NGX_ENABLED:-/etc/nginx/sites-enabled}"
DOMAIN=""; EMAIL=""
LOG_FILE="/var/log/alphacp-go-live.log"; touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-go-live.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift ;;
    --domain=*) DOMAIN="${1#*=}" ;;
    --email) EMAIL="${2:-}"; shift ;;
    --email=*) EMAIL="${1#*=}" ;;
    *) die "unknown option: $1" ;;
  esac
  shift
done

say ""
say "${C_B}== AlphaCP GO-LIVE ==${C_0}"
[[ "$(id -u)" -eq 0 ]] || die "root chahiye (sudo -i)"
[[ -n "${DOMAIN}" ]] || die "usage: bash alphacp-go-live.sh --domain alphacp.com --email tum@mail.com"
[[ -n "${EMAIL}" ]] || die "--email chahiye (Let's Encrypt notices ke liye)"
[[ "${DOMAIN}" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "domain format galat: ${DOMAIN}"
[[ -d "${WEB_ROOT}" ]] || die "website deploy nahi hai — pehle d20-website-preview.sh chalao"

# DNS check
IP="$(curl -s --max-time 5 https://checkip.amazonaws.com 2>/dev/null | tr -d '\n' || true)"
DNSIP="$(getent hosts "${DOMAIN}" 2>/dev/null | awk '{print $1; exit}')"
if [[ -n "${IP}" && -n "${DNSIP}" && "${IP}" != "${DNSIP}" ]]; then
  warn "DNS abhi is server par point nahi (domain=${DNSIP}, server=${IP}) — A record check karke 15-30 min baad dubara chalao"
  die "DNS mismatch"
fi
ok "DNS theek lag raha hai (${DOMAIN} -> ${DNSIP:-unknown})"

# certbot
if ! command -v certbot >/dev/null 2>&1; then
  say "-- certbot install --"
  apt-get update -qq >>"${LOG_FILE}" 2>&1 || true
  apt-get install -y -qq certbot python3-certbot-nginx >>"${LOG_FILE}" 2>&1 || die "certbot install fail"
fi

# :80 vhost (ACME + redirect)
VH80="${NGX_AVAIL}/alphacp-website-80.conf"
cat > "${VH80}" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${WEB_ROOT};
    location /.well-known/acme-challenge/ { try_files \$uri =404; }
    location / { return 301 https://${DOMAIN}\$request_uri; }
}
NGINX
ln -sf "${VH80}" "${NGX_ENABLED}/alphacp-website-80.conf"
nginx -t >>"${LOG_FILE}" 2>&1 || { rm -f "${NGX_ENABLED}/alphacp-website-80.conf" "${VH80}"; die "nginx -t fail (:80 vhost)"; }
systemctl reload nginx >>"${LOG_FILE}" 2>&1 || die "nginx reload fail"
ok ":80 vhost ready"

# cert
say "-- Let's Encrypt certificate --"
certbot certonly --webroot -w "${WEB_ROOT}" -d "${DOMAIN}" -d "www.${DOMAIN}" \
  -m "${EMAIL}" --agree-tos --no-eff-email -n >>"${LOG_FILE}" 2>&1 \
  || certbot certonly --webroot -w "${WEB_ROOT}" -d "${DOMAIN}" \
       -m "${EMAIL}" --agree-tos --no-eff-email -n >>"${LOG_FILE}" 2>&1 \
  || die "certificate issue fail — log dekho: ${LOG_FILE}"
CRT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
[[ -f "${CRT}" && -f "${KEY}" ]] || die "cert files nahi mile"
ok "asli SSL certificate mil gaya (auto-renew certbot timer se)"

# :443 public vhost
VH443="${NGX_AVAIL}/alphacp-website-443.conf"
cat > "${VH443}" <<NGINX
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${DOMAIN} www.${DOMAIN};

    ssl_certificate     ${CRT};
    ssl_certificate_key ${KEY};

    root ${WEB_ROOT};
    index index.html;

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    location / { try_files \$uri \$uri/ =404; }
}
NGINX
ln -sf "${VH443}" "${NGX_ENABLED}/alphacp-website-443.conf"
if nginx -t >>"${LOG_FILE}" 2>&1; then
  systemctl reload nginx >>"${LOG_FILE}" 2>&1 && ok "nginx reloaded" || die "nginx reload fail"
else
  rm -f "${NGX_ENABLED}/alphacp-website-443.conf" "${VH443}"
  die "nginx -t fail (:443 vhost) — public vhost hata diya"
fi
command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active" && { ufw allow 80/tcp >>"${LOG_FILE}" 2>&1; ufw allow 443/tcp >>"${LOG_FILE}" 2>&1; ok "ufw: 80/443 open"; }

say ""
say "${C_G}==> GO-LIVE COMPLETE ✅${C_0}"
say "    Website ab PUBLIC hai: ${C_B}https://${DOMAIN}/${C_0}  (asli SSL, koi warning nahi)"
say "    Owner preview :2096 pehle jaisa private hai."
exit 0
