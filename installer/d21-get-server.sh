#!/usr/bin/env bash
# =============================================================================
#  AlphaCP D21 (B4 part 1) — AWS ko "get server" banao (cPanel-style distribution)
# -----------------------------------------------------------------------------
#  AWS SERVER PAR CHALANA HAI (jahan AlphaCP live hai).
#  Kya karta hai:
#    1. LIVE /usr/local/alphacp se product pack banata hai (panel+vendor+agent+bin
#       +share + /etc configs) — secrets EXCLUDE (env, license signer key, logs)
#    2. /var/www/alphacp-get/<SECRET>/ me rakhta hai: pack + step1 + fresh-install
#       + bootstrap `install` + SHA256SUMS
#    3. nginx vhost :2096 (SSL) se serve karta hai — path SECRET hai, isliye
#       sirf tum (aur tumhare servers) download kar sakte ho
#    4. End me BADE SERVER ke liye one-line install command print karta hai
#  Dobara chalana SAFE hai (pack refresh ho jata hai, SECRET wahi rehta hai).
# =============================================================================
set -u -o pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
GET_ROOT="${GET_ROOT:-/var/www/alphacp-get}"
NGX_AVAIL="${NGX_AVAIL:-/etc/nginx/sites-available}"
NGX_ENABLED="${NGX_ENABLED:-/etc/nginx/sites-enabled}"
ETC_NGX="${ETC_NGX:-/etc/nginx/sites-available}"
ETC_POOL="${ETC_POOL:-/etc/php/8.4/fpm/pool.d}"
ETC_SYSD="${ETC_SYSD:-/etc/systemd/system}"
ETC_CRON="${ETC_CRON:-/etc/cron.d}"
DEF_WWW="${DEF_WWW:-/var/www/alphacp-default}"
CERT_DIR="${CERT_DIR:-/etc/ssl/alphacp}"
PORT=2099  # 2096 = Webmail (cPanel-standard) — get-server ka apna port
STAMP="$(date +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-d21-get.log"; touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-d21-get.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; exit 1; }

say ""
say "${C_B}== AlphaCP D21 GET-SERVER v1.0 (distribution :${PORT}) ==${C_0}"
[[ "$(id -u)" -eq 0 ]] || die "root chahiye (sudo -i)"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
[[ -d "${ACP_HOME}/panel/vendor" ]] || die "live panel vendor nahi mila (${ACP_HOME}/panel/vendor) — ye script AWS live server par chalti hai"
[[ -f "${SRC}/installer/install.sh" ]] || die "repo installer/install.sh nahi mila"
[[ -f "${SRC}/installer/alphacp-fresh-install.sh" ]] || die "repo installer/alphacp-fresh-install.sh nahi mila"
command -v nginx >/dev/null 2>&1 || die "nginx chahiye"

# ---- Step 1: secret path --------------------------------------------------
say ""
say "-- Step 1: secret path --"
SECRET_FILE="${GET_ROOT}/.secret"
if [[ -f "${SECRET_FILE}" ]]; then
  SECRET="$(cat "${SECRET_FILE}")"
  ok "purana SECRET reuse (command same rahegi)"
else
  SECRET="$(openssl rand -hex 12)"
  mkdir -p "${GET_ROOT}" && printf '%s' "${SECRET}" > "${SECRET_FILE}" && chmod 600 "${SECRET_FILE}"
  ok "naya SECRET generate"
fi
PKG="${GET_ROOT}/${SECRET}"
mkdir -p "${PKG}"

# ---- Step 2: product pack from LIVE ----------------------------------------
say ""
say "-- Step 2: live server se product pack (vendor samet) --"
STAGE="$(mktemp -d /tmp/alphacp-pack.XXXXXX)"
trap 'rm -rf "${STAGE}"' EXIT
mkdir -p "${STAGE}/alphacp" "${STAGE}/etc-bundle/nginx" "${STAGE}/etc-bundle/pool.d" "${STAGE}/etc-bundle/systemd" "${STAGE}/etc-bundle/cron.d" "${STAGE}/etc-bundle/default-www"

# panel (secrets/logs/caches/backups EXCLUDE)
tar -C "${ACP_HOME}" -cf - \
  --exclude='panel/.env' \
  --exclude='panel/storage/logs' \
  --exclude='panel/storage/framework/cache' \
  --exclude='panel/storage/framework/sessions' \
  --exclude='panel/storage/framework/views' \
  --exclude='panel/storage/app/private' \
  --exclude='panel/storage/app/backups' \
  --exclude='panel/bootstrap/cache' \
  --exclude='*.bak-*' \
  panel agent bin share 2>>"${LOG_FILE}" | tar -C "${STAGE}/alphacp" -xf - || die "live copy fail"
ok "panel+agent+bin+share staged (secrets excluded: .env, license signer, logs, backups)"

# /etc configs from LIVE
cp "${ETC_NGX}"/alphacp-cpanel.conf "${ETC_NGX}"/alphacp-whm.conf "${ETC_NGX}"/alphacp-webmail.conf "${ETC_NGX}"/alphacp-link.conf "${STAGE}/etc-bundle/nginx/" 2>>"${LOG_FILE}" || die "nginx vhosts copy fail"
[[ -f "${ETC_NGX}/alphacp-panel.conf" ]] && cp "${ETC_NGX}/alphacp-panel.conf" "${STAGE}/etc-bundle/nginx/"
[[ -f "${ETC_NGX}/alphacp-pma.conf" ]] && cp "${ETC_NGX}/alphacp-pma.conf" "${STAGE}/etc-bundle/nginx/" || warn "alphacp-pma.conf live par nahi mila (phpMyAdmin vhost pack me nahi jayega)"
cp "${ETC_POOL}/alphacp.conf" "${STAGE}/etc-bundle/pool.d/" 2>>"${LOG_FILE}" || die "fpm pool copy fail"
cp "${ETC_SYSD}/paneld.service" "${STAGE}/etc-bundle/systemd/" 2>>"${LOG_FILE}" || die "paneld.service copy fail"
[[ -d "${ETC_SYSD}/php8.4-fpm.service.d" ]] && cp -r "${ETC_SYSD}/php8.4-fpm.service.d" "${STAGE}/etc-bundle/systemd/"
cp "${ETC_CRON}"/alphacp-* "${STAGE}/etc-bundle/cron.d/" 2>>"${LOG_FILE}" || warn "cron.d copy partial"
[[ -d "${DEF_WWW}" ]] && cp -r "${DEF_WWW}/." "${STAGE}/etc-bundle/default-www/"
ok "/etc configs staged"

# License PUBLIC key -> pack (customer panels isi se signature verify karte hain;
# private signer kabhi pack me nahi jata)
SIGNER_JSON="${ACP_HOME}/panel/storage/app/private/license_signer.json"
if [[ -f "${SIGNER_JSON}" ]]; then
  python3 - "${SIGNER_JSON}" "${STAGE}/alphacp/panel/config/license_public.pem" <<'PYPEM' || die "license public key export fail"
import json, base64, sys
kp = json.load(open(sys.argv[1]))
raw = base64.b64decode(kp['public'])
assert len(raw) == 32, 'public key 32 bytes nahi'
der = bytes.fromhex('302a300506032b6570032100') + raw
b64 = base64.b64encode(der).decode()
open(sys.argv[2], 'w').write('-----BEGIN PUBLIC KEY-----\n' + b64 + '\n-----END PUBLIC KEY-----\n')
PYPEM
  ok "license PUBLIC key pack me shamil (config/license_public.pem)"
else
  warn "license_signer.json nahi mila — pack bina public key ke (activate verify fail hoga)"
fi

# AWS live version -> pack (naye installs/updates isi se version dikhate hain)
AWSV="$(grep -m1 '^ACP_VERSION=' "${ACP_HOME}/panel/.env" 2>/dev/null | cut -d= -f2- || true)"
mkdir -p "${STAGE}/alphacp/share"
printf '%s\n' "${AWSV:-0.83.0}" > "${STAGE}/alphacp/share/VERSION"
tar -C "${STAGE}" -czf "${PKG}/alphacp-server.tar.gz" alphacp etc-bundle || die "pack tar fail"
PACK_SZ="$(du -h "${PKG}/alphacp-server.tar.gz" | cut -f1)"
ok "pack ready: alphacp-server.tar.gz (${PACK_SZ})"

# ---- Step 3: scripts + bootstrap -------------------------------------------
say ""
say "-- Step 3: install scripts + bootstrap --"
cp "${SRC}/installer/install.sh" "${PKG}/alphacp-step1.sh"
cp "${SRC}/installer/alphacp-fresh-install.sh" "${PKG}/alphacp-fresh-install.sh"
[[ -f "${SRC}/installer/alphacp-server-update.sh" ]] || die "repo installer/alphacp-server-update.sh nahi mila"
cp "${SRC}/installer/alphacp-server-update.sh" "${PKG}/alphacp-server-update.sh"

MYIP="$(curl -s --max-time 5 https://checkip.amazonaws.com 2>/dev/null | tr -d '\n' || true)"
[[ -n "${MYIP}" ]] || MYIP="$(hostname -I 2>/dev/null | awk '{print $1}')"
BASE_URL="https://${MYIP}:${PORT}/${SECRET}"

cat > "${PKG}/install" <<BOOTSTRAP
#!/usr/bin/env bash
# AlphaCP bootstrap — naya server install (AlphaCP get-server se, GitHub se NAHI)
set -u -o pipefail
BASE="${BASE_URL}"
[[ "\$(id -u)" -eq 0 ]] || { echo "root chahiye: sudo -i karke chalao"; exit 1; }
W="/root/alphacp-install"; mkdir -p "\$W"; cd "\$W"
echo "== AlphaCP installer download (\${BASE}) =="
for f in SHA256SUMS alphacp-step1.sh alphacp-fresh-install.sh alphacp-server.tar.gz; do
  echo "  -> \$f"
  curl -fsSLk "\${BASE}/\$f" -o "\$f" || { echo "[FAIL] \$f download fail"; exit 1; }
done
grep -E ' (alphacp-step1\\.sh|alphacp-fresh-install\\.sh|alphacp-server\\.tar\\.gz)\$' SHA256SUMS | sha256sum -c - || { echo "[FAIL] checksum mismatch — dobara try karo"; exit 1; }
echo "[OK] sab files verified"
echo ""
echo "== Phase 1: base hosting stack (10-20 min lag sakte hain) =="
bash alphacp-step1.sh --yes --profile=prod || bash alphacp-step1.sh --yes || { echo "[FAIL] step1 fail — /var/log/alphacp-install.log dekho"; exit 1; }
echo ""
echo "== Phase 2: AlphaCP panel + agent + license =="
ACP_LICENSE_API_URL="\${ACP_LICENSE_API_URL:-https://${MYIP}:2083/api/v1}" \
ACP_LICENSE_KEY="\${ACP_LICENSE_KEY:-}" \
bash alphacp-fresh-install.sh --pack "\$W/alphacp-server.tar.gz" --yes
BOOTSTRAP
cat > "${PKG}/update" <<BOOTSTRAP2
#!/usr/bin/env bash
# AlphaCP server update — get-server se (GitHub se NAHI). Code-only, auto-rollback.
set -u -o pipefail
BASE="${BASE_URL}"
[[ "\$(id -u)" -eq 0 ]] || { echo "root chahiye: sudo -i karke chalao"; exit 1; }
W="/root/alphacp-update"; mkdir -p "\$W"; cd "\$W"
echo "== AlphaCP update download (\${BASE}) =="
for f in SHA256SUMS alphacp-server-update.sh alphacp-server.tar.gz; do
  echo "  -> \$f"
  curl -fsSLk "\${BASE}/\$f" -o "\$f" || { echo "[FAIL] \$f download fail"; exit 1; }
done
grep -E ' (alphacp-server-update\.sh|alphacp-server\.tar\.gz)\$' SHA256SUMS | sha256sum -c - || { echo "[FAIL] checksum mismatch — dobara try karo"; exit 1; }
echo "[OK] sab files verified"
bash alphacp-server-update.sh --pack "\$W/alphacp-server.tar.gz"
BOOTSTRAP2

chmod 644 "${PKG}/install" "${PKG}/update" "${PKG}"/*.sh "${PKG}/alphacp-server.tar.gz"

( cd "${PKG}" && sha256sum alphacp-step1.sh alphacp-fresh-install.sh alphacp-server.tar.gz alphacp-server-update.sh install update > SHA256SUMS )
ok "bootstrap + SHA256SUMS ready"

# ---- Step 4: nginx vhost :2096 ----------------------------------------------
say ""
say "-- Step 4: nginx vhost :${PORT} --"
SSL_CRT="${CERT_DIR}/panel.crt"; SSL_KEY="${CERT_DIR}/panel.key"
if [[ ! -f "${SSL_CRT}" || ! -f "${SSL_KEY}" ]]; then
  mkdir -p "${CERT_DIR}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout "${SSL_KEY}" -out "${SSL_CRT}" -subj "/CN=alphacp-get" >>"${LOG_FILE}" 2>&1 || die "cert fail"
fi
VHOST="${NGX_AVAIL}/alphacp-get.conf"
[[ -f "${VHOST}" ]] && cp -a "${VHOST}" "${VHOST}.bak-d21-${STAMP}"
cat > "${VHOST}" <<NGINX
# AlphaCP get-server (D21) — installer distribution, secret path se protected.
server {
    listen ${PORT} ssl;
    listen [::]:${PORT} ssl;
    server_name _;
    ssl_certificate     ${SSL_CRT};
    ssl_certificate_key ${SSL_KEY};
    root ${GET_ROOT};
    autoindex off;
    add_header X-Robots-Tag "noindex, nofollow" always;
    location = / { return 404; }
    location = /.secret { deny all; }
    location / { try_files \$uri =404; }
}
NGINX
ln -sf "${VHOST}" "${NGX_ENABLED}/alphacp-get.conf"
if nginx -t >>"${LOG_FILE}" 2>&1; then
  systemctl reload nginx >>"${LOG_FILE}" 2>&1 && ok "nginx reloaded — get-server :${PORT} live" || die "nginx reload fail"
else
  rm -f "${NGX_ENABLED}/alphacp-get.conf" "${VHOST}"
  die "nginx -t fail — vhost hata diya (baaki sab untouched)"
fi
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "${PORT}/tcp" >>"${LOG_FILE}" 2>&1 && ok "ufw: ${PORT} open" || warn "ufw allow fail"
fi

say ""
say "${C_G}==> D21 GET-SERVER COMPLETE ✅${C_0}"
say ""
say "   ${C_Y}ZAROORI:${C_0} AWS Lightsail console -> Networking -> Firewall me"
say "   port ${C_B}${PORT}${C_0} (TCP) add karna (agar pehle se nahi hai)."
say ""
say "   BADE SERVER par (root / sudo -i) ye ONE-LINE command:"
say ""
say "   ${C_B}bash <(curl -fsSLk ${BASE_URL}/install)${C_0}"
say ""
say "   KISI BHI INSTALLED SERVER ko UPDATE karne ke liye (root):"
say ""
say "   ${C_B}bash <(curl -fsSLk ${BASE_URL}/update)${C_0}"
say ""
say "   License key ke saath (AWS :2087 License Server page se banao):"
say "   ${C_B}ACP_LICENSE_KEY=ACP-xxxxxxxxxxxx bash <(curl -fsSLk ${BASE_URL}/install)${C_0}"
exit 0
