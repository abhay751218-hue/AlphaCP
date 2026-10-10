#!/usr/bin/env bash
# =============================================================================
#  AlphaCP Updater (B5) — cPanel-style release-channel update system
# -----------------------------------------------------------------------------
#  Kya karta hai:
#    release/MANIFEST.json (signed-sha256 file list) ko live server se compare
#    karta hai aur sirf CHANGED/NEW files apply karta hai — backup, cache clear,
#    paneld gate, health gate, auto-rollback ke saath.
#
#  Usage (root):
#    bash alphacp-update.sh --check          # sirf dekho kya update hoga (safe)
#    bash alphacp-update.sh                  # update apply karo (stable channel)
#    bash alphacp-update.sh --channel edge   # edge channel
#
#  Env overrides: PANEL_ROOT AGENT_ROOT REPO_URL SRC_DIR (SRC_DIR = local repo,
#  clone skip — sim/testing ke liye).
#  Safety: .env / storage / keys ko KABHI touch nahi karta (manifest me code-only
#  files hain). Koi file delete nahi hoti (v1 = add/replace only).
# =============================================================================
set -u -o pipefail

PANEL_ROOT="${PANEL_ROOT:-/usr/local/alphacp/panel}"
AGENT_ROOT="${AGENT_ROOT:-/usr/local/alphacp/agent}"
REPO_URL="${REPO_URL:-https://github.com/abhay751218-hue/AlphaCP.git}"
CHANNEL="stable"
CHECK_ONLY=0
STAMP="$(date +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-update.log"
touch "${LOG_FILE}" 2>/dev/null || LOG_FILE="/tmp/alphacp-update.log"

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*" | tee -a "${LOG_FILE}"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[WARN]${C_0} $*"; }
die()  { say "${C_R}[FAIL]${C_0} $*"; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check) CHECK_ONLY=1 ;;
    --channel) CHANNEL="${2:-stable}"; shift ;;
    --channel=*) CHANNEL="${1#*=}" ;;
    *) die "unknown option: $1" ;;
  esac
  shift
done

say ""
say "${C_B}== AlphaCP Updater (channel: ${CHANNEL}) ==${C_0}"

[[ -d "${PANEL_ROOT}" ]] || die "panel nahi mila: ${PANEL_ROOT}"
[[ -d "${AGENT_ROOT}" ]] || die "agent nahi mila: ${AGENT_ROOT}"
command -v python3 >/dev/null 2>&1 || die "python3 chahiye"

# ---- Step 1: release source (clone ya SRC_DIR) ------------------------------
WORK=""
if [[ -n "${SRC_DIR:-}" ]]; then
  SRC="${SRC_DIR}"
  ok "SRC_DIR local repo use ho raha: ${SRC}"
else
  command -v git >/dev/null 2>&1 || die "git chahiye"
  WORK="$(mktemp -d /tmp/alphacp-update.XXXXXX)"
  trap '[[ -n "${WORK}" ]] && rm -rf "${WORK}"' EXIT
  REF="arena/009c72b2-alphacp"
  say "-- Step 1: release fetch (${REF}) --"
  git clone --depth 1 --branch "${REF}" "${REPO_URL}" "${WORK}/repo" >>"${LOG_FILE}" 2>&1 \
    || die "git clone fail — network/branch check karo (log: ${LOG_FILE})"
  SRC="${WORK}/repo"
  # channel ref resolve (CHANNELS.json)
  if [[ -f "${SRC}/release/CHANNELS.json" ]]; then
    CREF="$(python3 -c "import json,sys;print(json.load(open('${SRC}/release/CHANNELS.json')).get('${CHANNEL}',{}).get('ref',''))" 2>/dev/null || true)"
    [[ -n "${CREF}" ]] || die "channel '${CHANNEL}' CHANNELS.json me nahi mila"
    if [[ "${CREF}" != "${REF}" ]]; then
      ( cd "${SRC}" && git fetch --depth 1 origin "${CREF}" >>"${LOG_FILE}" 2>&1 \
          && git checkout FETCH_HEAD >>"${LOG_FILE}" 2>&1 ) || die "channel ref '${CREF}' fetch fail"
    fi
  fi
fi

MANIFEST="${SRC}/release/MANIFEST.json"
TREE="${SRC}/release/tree"
[[ -f "${MANIFEST}" && -d "${TREE}" ]] || die "release/MANIFEST.json ya release/tree nahi mila"

NEWVER="$(python3 -c "import json;print(json.load(open('${MANIFEST}'))['version'])")"
say "release version: ${C_B}${NEWVER}${C_0}"

# ---- Step 2: diff (manifest vs live) ----------------------------------------
say ""
say "-- Step 2: compare (manifest sha256 vs live) --"
DIFF_LIST="$(mktemp /tmp/alphacp-update-diff.XXXXXX)"
python3 - "$MANIFEST" "$PANEL_ROOT" "$AGENT_ROOT" > "${DIFF_LIST}" <<'PYEOF'
import hashlib, json, pathlib, sys
manifest, panel, agent = sys.argv[1], pathlib.Path(sys.argv[2]), pathlib.Path(sys.argv[3])
tree = json.load(open(manifest))['tree_sha256']
for rel, want in sorted(tree.items()):
    if rel.endswith('.env.example') is False and '/.env' in rel:
        continue  # safety: koi .env kabhi nahi
    if rel.startswith('panel/'):
        live = panel / rel[6:]
    elif rel.startswith('agent/'):
        live = agent / rel[6:]
    else:
        continue
    if not live.is_file():
        print(f"NEW\t{rel}")
    elif hashlib.sha256(live.read_bytes()).hexdigest() != want:
        print(f"CHANGED\t{rel}")
PYEOF
[[ $? -eq 0 ]] || die "compare fail"

N_TOTAL="$(wc -l < "${DIFF_LIST}" | tr -d ' ')"
if [[ "${N_TOTAL}" -eq 0 ]]; then
  ok "sab up-to-date hai — kuch apply karne ko nahi (version ${NEWVER})"
  rm -f "${DIFF_LIST}"
  exit 0
fi
say "update me ${C_B}${N_TOTAL}${C_0} files:"
sed 's/^/    /' "${DIFF_LIST}" | head -40 | tee -a "${LOG_FILE}"
[[ "${N_TOTAL}" -gt 40 ]] && say "    … aur $((N_TOTAL - 40)) files (log me puri list)"

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
  say ""
  ok "--check mode: kuch apply NahI hua. Apply karne ke liye bina --check chalao."
  rm -f "${DIFF_LIST}"
  exit 0
fi

[[ "$(id -u)" -eq 0 ]] || die "apply ke liye root chahiye (sudo -i)"

# ---- Step 3: backup + install ------------------------------------------------
say ""
say "-- Step 3: backup + install (${N_TOTAL} files) --"
BACKUPS="$(mktemp /tmp/alphacp-update-bk.XXXXXX)"
ADDED="$(mktemp /tmp/alphacp-update-new.XXXXXX)"
AGENT_TOUCHED=0

rollback() {
  warn "ROLLBACK: backups wapas..."
  while IFS=$'\t' read -r live bak; do
    [[ -f "${bak}" ]] && mv -f "${bak}" "${live}"
  done < "${BACKUPS}"
  while IFS= read -r f; do
    rm -f "${f}"
  done < "${ADDED}"
  ok "rollback complete — sab files purani jagah par"
}

while IFS=$'\t' read -r kind rel; do
  src="${TREE}/${rel}"
  case "${rel}" in
    panel/*) live="${PANEL_ROOT}/${rel#panel/}" ;;
    agent/*) live="${AGENT_ROOT}/${rel#agent/}"; AGENT_TOUCHED=1 ;;
    *) continue ;;
  esac
  mkdir -p "$(dirname "${live}")"
  if [[ -f "${live}" ]]; then
    bak="${live}.bak-update-${STAMP}"
    cp -a "${live}" "${bak}" || { rollback; die "backup fail: ${live}"; }
    printf '%s\t%s\n' "${live}" "${bak}" >> "${BACKUPS}"
  else
    printf '%s\n' "${live}" >> "${ADDED}"
  fi
  cp "${src}" "${live}" || { rollback; die "install fail: ${live}"; }
  case "${rel}" in
    agent/*) chown root:root "${live}" 2>/dev/null || true ;;
    panel/*) chown --reference="${PANEL_ROOT}/public/assets/panel.css" "${live}" 2>/dev/null || true ;;
  esac
  chmod 0644 "${live}" 2>/dev/null || true
done < "${DIFF_LIST}"
ok "${N_TOTAL} files installed (backup: .bak-update-${STAMP})"

# ---- Step 4: caches + services -----------------------------------------------
say ""
say "-- Step 4: caches + services --"
PHPBIN=""
for c in php8.4 php8.3 php; do command -v "$c" >/dev/null 2>&1 && { PHPBIN="$c"; break; }; done
RUNAS=""
id alphacp >/dev/null 2>&1 && RUNAS="sudo -u alphacp"
if [[ -n "${PHPBIN}" ]]; then
  for art in view:clear route:clear config:clear; do
    ( cd "${PANEL_ROOT}" && ${RUNAS} "${PHPBIN}" artisan "${art}" >>"${LOG_FILE}" 2>&1 ) \
      && ok "${art} done" || warn "${art} fail (non-fatal)"
  done
  ( cd "${PANEL_ROOT}" && ${RUNAS} "${PHPBIN}" artisan migrate --force >>"${LOG_FILE}" 2>&1 ) \
    && ok "migrations up-to-date" || warn "migrate fail (log dekho — schema changes manual review)"
fi
for fpm in php8.4-fpm php8.3-fpm; do
  systemctl is-active --quiet "${fpm}" 2>/dev/null && { systemctl reload "${fpm}" >>"${LOG_FILE}" 2>&1 && ok "${fpm} reloaded"; break; }
done

if [[ "${AGENT_TOUCHED}" -eq 1 ]] && systemctl is-active --quiet paneld 2>/dev/null; then
  if systemctl restart paneld >>"${LOG_FILE}" 2>&1 && sleep 2 && systemctl is-active --quiet paneld; then
    ok "paneld restarted"
  else
    rollback
    systemctl restart paneld >>"${LOG_FILE}" 2>&1 || true
    die "paneld naye code ke saath start nahi hua — rollback ho gaya"
  fi
fi

# ---- Step 5: health gate -------------------------------------------------------
say ""
say "-- Step 5: health check --"
HFAIL=0
for port in 8090 2083 2087; do
  code="$(curl -k -s -o /dev/null -w '%{http_code}' --max-time 8 "https://127.0.0.1:${port}/" 2>/dev/null || echo 000)"
  [[ "${code}" == "000" ]] && code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "http://127.0.0.1:${port}/" 2>/dev/null || echo 000)"
  if [[ "${code}" =~ ^(200|301|302|401|403)$ ]]; then
    ok "health :${port} -> HTTP ${code}"
  else
    warn "health :${port} -> HTTP ${code}"
    HFAIL=1
  fi
done
if [[ "${HFAIL}" -eq 1 ]]; then
  rollback
  [[ -n "${PHPBIN}" ]] && ( cd "${PANEL_ROOT}" && ${RUNAS} "${PHPBIN}" artisan view:clear >>"${LOG_FILE}" 2>&1; ${RUNAS} "${PHPBIN}" artisan config:clear >>"${LOG_FILE}" 2>&1 )
  [[ "${AGENT_TOUCHED}" -eq 1 ]] && systemctl restart paneld >>"${LOG_FILE}" 2>&1
  die "health fail — update wapas rollback ho gaya (panel pehle jaisa chal raha hai)"
fi

say ""
say "${C_G}==> UPDATE COMPLETE ✅  (version ${NEWVER}, ${N_TOTAL} files)${C_0}"
say "    Rollback kabhi bhi: *.bak-update-${STAMP} files apni jagah par hain"
rm -f "${DIFF_LIST}" "${BACKUPS}" "${ADDED}"
exit 0
