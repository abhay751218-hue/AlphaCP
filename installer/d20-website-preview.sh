#!/usr/bin/env bash
# =============================================================================
#  AlphaCP D20 (B2b) — Company website PRIVATE preview on port 2096
# -----------------------------------------------------------------------------
#  Kya karta hai:
#    * repo ki website/ ko /usr/local/alphacp/website par deploy karta hai
#    * nginx vhost :2096 (SSL) + HTTP Basic Auth (sirf owner dekh sake)
#    * password random generate hota hai aur END ME EK BAAR print hota hai
#  Domain aane par: installer/alphacp-go-live.sh chalana (public ho jayegi).
#  Rollback: vhost file hatao, nginx reload — website band, panel untouched.
# =============================================================================
set -u -o pipefail

WEB_ROOT="${WEB_ROOT:-/usr/local/alphacp/website}"
NGX_AVAIL="${NGX_AVAIL:-/etc/nginx/sites-available}"
NGX_ENABLED="${NGX_ENABLED:-/etc/nginx/sites-enabled}"
CERT_DIR="${CERT_DIR:-/etc/ssl/alphacp-website}"
HTPASS="${HTPASS:-/etc/nginx/alphacp-website.htpasswd}"
PORT=2096
STAMP="$(date +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-d20-website.log"; touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-d20-website.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; exit 1; }

say ""
say "${C_B}== AlphaCP D20 WEBSITE PREVIEW v1.0 (port ${PORT}, private) ==${C_0}"
[[ "$(id -u)" -eq 0 ]] || die "root chahiye (sudo -i)"

SRC="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "${SRC}/website/index.html" ]] || die "website/ source nahi mila (${SRC})"
command -v openssl >/dev/null 2>&1 || die "openssl chahiye"
command -v nginx >/dev/null 2>&1 || die "nginx chahiye"

# ---- Step 1: website files ---------------------------------------------------
say ""
say "-- Step 1: website files -> ${WEB_ROOT} --"
mkdir -p "${WEB_ROOT}"
cp -r "${SRC}/website/." "${WEB_ROOT}/" || die "copy fail"
rm -f "${WEB_ROOT}/alphacp-website-demo.html"
find "${WEB_ROOT}" -type d -exec chmod 755 {} \; && find "${WEB_ROOT}" -type f -exec chmod 644 {} \;
ok "website deployed ($(find "${WEB_ROOT}" -type f | wc -l | tr -d ' ') files)"

# ---- Step 2: owner password (basic auth) --------------------------------------
say ""
say "-- Step 2: private access (basic auth) --"
OWNER_PASS="$(openssl rand -base64 12 | tr -d '/+=' | head -c 14)"
printf 'owner:%s\n' "$(openssl passwd -apr1 "${OWNER_PASS}")" > "${HTPASS}"
chmod 640 "${HTPASS}" && chown root:www-data "${HTPASS}" 2>/dev/null || true
ok "basic-auth user 'owner' set"

# ---- Step 3: SSL cert (reuse panel ka ya self-signed) --------------------------
say ""
say "-- Step 3: SSL cert --"
SSL_CRT=""; SSL_KEY=""
for v in "${NGX_AVAIL}/alphacp-cpanel.conf" "${NGX_AVAIL}"/alphacp-*.conf; do
  [[ -f "$v" ]] || continue
  c="$(grep -m1 -oP 'ssl_certificate\s+\K[^;]+' "$v" 2>/dev/null || true)"
  k="$(grep -m1 -oP 'ssl_certificate_key\s+\K[^;]+' "$v" 2>/dev/null || true)"
  if [[ -n "$c" && -n "$k" && -f "$c" && -f "$k" ]]; then SSL_CRT="$c"; SSL_KEY="$k"; break; fi
done
if [[ -z "${SSL_CRT}" ]]; then
  mkdir -p "${CERT_DIR}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "${CERT_DIR}/website.key" -out "${CERT_DIR}/website.crt" \
    -subj "/CN=alphacp-website" >>"${LOG_FILE}" 2>&1 || die "self-signed cert fail"
  SSL_CRT="${CERT_DIR}/website.crt"; SSL_KEY="${CERT_DIR}/website.key"
  ok "self-signed cert generated"
else
  ok "panel ka existing cert reuse: ${SSL_CRT}"
fi

# ---- Step 4: nginx vhost -------------------------------------------------------
say ""
say "-- Step 4: nginx vhost :${PORT} --"
VHOST="${NGX_AVAIL}/alphacp-website.conf"
[[ -f "${VHOST}" ]] && cp -a "${VHOST}" "${VHOST}.bak-d20-${STAMP}"
cat > "${VHOST}" <<NGINX
# AlphaCP company website — PRIVATE preview (D20). Public go-live:
# installer/alphacp-go-live.sh (domain ke saath). Ye block hatao to site band.
server {
    listen ${PORT} ssl;
    listen [::]:${PORT} ssl;
    server_name _;

    ssl_certificate     ${SSL_CRT};
    ssl_certificate_key ${SSL_KEY};

    root ${WEB_ROOT};
    index index.html;

    auth_basic "AlphaCP Owner Preview";
    auth_basic_user_file ${HTPASS};

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / { try_files \$uri \$uri/ =404; }
}
NGINX
ln -sf "${VHOST}" "${NGX_ENABLED}/alphacp-website.conf"
if nginx -t >>"${LOG_FILE}" 2>&1; then
  systemctl reload nginx >>"${LOG_FILE}" 2>&1 && ok "nginx reloaded — website :${PORT} par live (private)" || die "nginx reload fail"
else
  rm -f "${NGX_ENABLED}/alphacp-website.conf" "${VHOST}"
  die "nginx -t fail — vhost wapas hata diya (panel untouched). Log: ${LOG_FILE}"
fi
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "${PORT}/tcp" >>"${LOG_FILE}" 2>&1 && ok "ufw: port ${PORT} open" || warn "ufw allow fail"
fi

IP="$(curl -s --max-time 5 https://checkip.amazonaws.com 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}')"
say ""
say "${C_G}==> D20 WEBSITE PREVIEW COMPLETE ✅${C_0}"
say "    URL      : ${C_B}https://${IP:-<server-ip>}:${PORT}/${C_0}"
say "    Username : ${C_B}owner${C_0}"
say "    Password : ${C_B}${OWNER_PASS}${C_0}   <- ABHI NOTE KAR LO (dubara nahi dikhega)"
say "    (Browser self-signed warning dega — Advanced -> Proceed)"
say "    Customer ko kuch nahi dikhta — password ke bina 401. Domain aane par"
say "    alphacp-go-live.sh chalana, public ho jayegi."
exit 0
