#!/usr/bin/env bash
# =============================================================================
#  AlphaCP Server Update (B5b) — AWS GET-SERVER se update (GitHub se NAHI)
# -----------------------------------------------------------------------------
#  Kya karta hai:
#    AWS get-server ka pack (alphacp-server.tar.gz) live server par apply karta
#    hai — SIRF code (panel/agent/bin/share). .env, storage, license, DB kabhi
#    touch nahi hote. Backup + migrate + health-gate + AUTO-ROLLBACK ke saath.
#
#  Usage (root):
#    bash <(curl -fsSLk https://AWS-IP:2096/SECRET/update)      # one-liner
#    bash alphacp-server-update.sh --pack /path/alphacp-server.tar.gz
#
#  Flow (cPanel-company style):
#    GitHub push -> AWS repo-updater -> d21 pack refresh -> SAB SERVER is
#    script se AWS se update lete hain.
# =============================================================================
set -u -o pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
PANEL_ROOT="${ACP_HOME}/panel"
PANEL_USER="${PANEL_USER:-alphacp}"
STAMP="$(date +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-server-update.log"
touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-server-update.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; exit 1; }

PACK=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pack) PACK="${2:-}"; shift ;;
    *) die "unknown arg: $1 (sirf --pack /path/pack.tar.gz)" ;;
  esac
  shift
done

[[ "$(id -u)" -eq 0 ]] || die "root chahiye (sudo -i karke chalao)"
[[ -d /var/www/alphacp-get ]] && die "ye GET-SERVER (AWS license server) hai — iska update repo-updater (alphacp-update.sh) se hota hai, is script se NAHI"
[[ -f "${PANEL_ROOT}/artisan" && -d "${PANEL_ROOT}/vendor" ]] || die "AlphaCP installed nahi hai (${PANEL_ROOT}) — pehle install karo"
[[ -n "${PACK}" && -f "${PACK}" ]] || die "--pack /path/alphacp-server.tar.gz dena zaroori hai"

PHPBIN=""
for c in php8.4 php8.3 php; do command -v "$c" >/dev/null 2>&1 && { PHPBIN="$c"; break; }; done
[[ -n "${PHPBIN}" ]] || die "php nahi mila"
RUNAS=(sudo -u "${PANEL_USER}" env "ACP_HOME=${ACP_HOME}")

OLD_VER="$(grep -m1 '^ACP_VERSION=' "${PANEL_ROOT}/.env" 2>/dev/null | cut -d= -f2- || true)"

say ""
say "${C_B}== AlphaCP Server Update (${STAMP}) ==${C_0}"

# ---- 1. extract pack ---------------------------------------------------------
STAGE="$(mktemp -d /tmp/alphacp-srvupd.XXXXXX)"
trap 'rm -rf "${STAGE}"' EXIT
tar -xzf "${PACK}" -C "${STAGE}" || die "pack extract fail"
[[ -f "${STAGE}/alphacp/panel/artisan" && -d "${STAGE}/alphacp/panel/vendor" ]] || die "pack me panel/vendor nahi — pack kharab hai"
NEW_VER="$(tr -d '[:space:]' < "${STAGE}/alphacp/share/VERSION" 2>/dev/null || true)"
ok "pack extract OK (new version: ${NEW_VER:-unknown})"

# ---- 2. rollback backup (code-only) -------------------------------------------
ROLLBACK="${ACP_HOME}/update-rollback-${STAMP}.tar.gz"
tar -C "${ACP_HOME}" -czf "${ROLLBACK}" \
  --exclude='panel/.env' \
  --exclude='panel/storage' \
  --exclude='panel/bootstrap/cache' \
  --exclude='*.bak-*' \
  --exclude='update-rollback-*' \
  panel agent bin share 2>>"${LOG_FILE}" || die "rollback backup fail"
ok "rollback backup: ${ROLLBACK}"

do_rollback() {
  warn "ROLLBACK shuru — purana code wapas..."
  tar -C "${ACP_HOME}" -xzf "${ROLLBACK}" 2>>"${LOG_FILE}" || { say "${C_R}[FAIL]${C_0} rollback extract fail — manually: tar -C ${ACP_HOME} -xzf ${ROLLBACK}"; return 1; }
  ( cd "${PANEL_ROOT}" && "${RUNAS[@]}" "${PHPBIN}" artisan config:clear >>"${LOG_FILE}" 2>&1; "${RUNAS[@]}" "${PHPBIN}" artisan view:clear >>"${LOG_FILE}" 2>&1 ) || true
  systemctl reload php8.4-fpm 2>/dev/null || systemctl restart php8.4-fpm 2>/dev/null || true
  systemctl restart paneld 2>/dev/null || true
  warn "rollback done — server purane version par wapas hai"
}

# ---- 3. apply (code-only; .env/storage untouched) ------------------------------
for d in panel agent bin share; do
  [[ -d "${STAGE}/alphacp/${d}" ]] || { warn "pack me ${d}/ nahi — skip"; continue; }
  tar -C "${STAGE}/alphacp" -cf - \
    --exclude='panel/.env' \
    --exclude='panel/storage' \
    --exclude='panel/bootstrap/cache' \
    "${d}" | tar -C "${ACP_HOME}" -xf - 2>>"${LOG_FILE}" || { do_rollback; die "apply fail (${d}) — rollback ho gaya"; }
done
chown -R root:root "${ACP_HOME}/agent" "${ACP_HOME}/bin" "${ACP_HOME}/share" 2>/dev/null || true
chmod +x "${ACP_HOME}"/bin/* "${ACP_HOME}/agent/bin/paneld" 2>/dev/null || true
if [[ -f "${ACP_HOME}/bin/alphacp" ]]; then
  install -m 0755 "${ACP_HOME}/bin/alphacp" /usr/local/bin/alphacp 2>/dev/null || true
fi
ok "naya code applied (.env/storage/license untouched)"

# ---- 4. version sync in .env ----------------------------------------------------
if [[ -n "${NEW_VER}" ]]; then
  for k in ACP_VERSION ACP_AGENT_VERSION; do
    if grep -q "^${k}=" "${PANEL_ROOT}/.env"; then
      sed -i "s/^${k}=.*/${k}=${NEW_VER}/" "${PANEL_ROOT}/.env"
    else
      printf '%s=%s\n' "${k}" "${NEW_VER}" >> "${PANEL_ROOT}/.env"
    fi
  done
  ok "version set: ${NEW_VER}"
fi

# ---- 5. migrate + caches + services ----------------------------------------------
( cd "${PANEL_ROOT}" && "${RUNAS[@]}" "${PHPBIN}" artisan migrate --force >>"${LOG_FILE}" 2>&1 ) \
  || { do_rollback; die "migrate fail — rollback ho gaya (log: ${LOG_FILE})"; }
ok "DB migrations OK"
for art in config:clear view:clear; do
  ( cd "${PANEL_ROOT}" && "${RUNAS[@]}" "${PHPBIN}" artisan "${art}" >>"${LOG_FILE}" 2>&1 ) || warn "artisan ${art} warning"
done
systemctl reload php8.4-fpm 2>/dev/null || systemctl restart php8.4-fpm 2>/dev/null || warn "fpm reload warning"
systemctl restart paneld 2>/dev/null || warn "paneld restart warning"

# ---- 6. health gate (fail => auto-rollback) ----------------------------------------
HEALTH_OK=1
for p in 2083 2087; do
  P_OK=0
  for i in 1 2 3 4 5 6; do
    code="$(curl -skI -m 8 -o /dev/null -w '%{http_code}' "https://127.0.0.1:${p}/login" 2>/dev/null || true)"
    [[ "${code}" == "200" ]] && { P_OK=1; break; }
    sleep 2
  done
  if [[ "${P_OK}" -eq 1 ]]; then ok "health :${p} = 200"; else warn "health :${p} FAIL"; HEALTH_OK=0; fi
done
if [[ "${HEALTH_OK}" -ne 1 ]]; then
  do_rollback
  die "health gate fail — AUTO-ROLLBACK ho gaya, panel purane version par chal raha hai"
fi

say ""
say "${C_G}==> UPDATE COMPLETE ✅  ${OLD_VER:-?} -> ${NEW_VER:-updated}${C_0}"
say "    rollback file (sab theek ho to delete kar sakte ho):"
say "    rm -f ${ROLLBACK}"
exit 0
