#!/usr/bin/env bash
# =============================================================================
# AlphaCP — COMMANDS.md release guard (offline, root ki zaroorat nahi)
#
# Ye check sabse mehngi galti pakadta hai: "NEXT STEP" command me aisa commit/raw
# pin hona jo (a) uska file us commit me maujood hi nahi, ya (b) us commit par
# purani version ki file hai, ya (c) andar ke artifact pins (BUNDLE/AGENT sha)
# us commit ke artifacts se match nahi karte.
#
# Chalane ka tarika:  bash tools/sim/commands-check.sh
# =============================================================================
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }

CMD_FILE="COMMANDS.md"
[[ -f "${CMD_FILE}" ]] || { echo "COMMANDS.md nahi mila"; exit 1; }

# ---------------------------------------------------------------- parse -------
# Us section ka pehla alphacp-sync get line jo "NEXT STEP" ke baad aata hai.
NEXT_BLOCK="$(awk '/NEXT STEP/{f=1;next} /^## /{f=0} f' "${CMD_FILE}")"
CMD_LINE="$(grep -m1 'alphacp-sync get' <<<"${NEXT_BLOCK}" || true)"
[[ -n "${CMD_LINE}" ]] || { echo "NEXT STEP me koi alphacp-sync get command nahi mili"; exit 1; }

read -r COMMIT PATH_IN_REPO DEST SHA <<<"$(sed -n 's/.*alphacp-sync get \([0-9a-f]\{40\}\) \([^ ]*\) \([^ ]*\) \([0-9a-f]\{64\}\).*/\1 \2 \3 \4/p' <<<"${CMD_LINE}")"
[[ -n "${COMMIT:-}" && -n "${PATH_IN_REPO:-}" && -n "${SHA:-}" ]] || { echo "command parse nahi hui: ${CMD_LINE}"; exit 1; }

echo "=== COMMANDS-CHECK: ${DEST##*/} @ ${COMMIT:0:12} ==="

# dest file naam se expected updater version nikaalo (…-0.70.0.sh)
EXPECT_VERSION="$(sed -n 's/.*-\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)\.sh$/\1/p' <<<"${DEST}")"

# ---------------------------------------------------------------- commit ------
if ! git cat-file -e "${COMMIT}^{commit}" 2>/dev/null; then
  bad "commit ${COMMIT:0:12} local repo me nahi (shallow clone? git fetch karo)"
  echo; echo "=== COMMANDS-CHECK: ${PASS} pass, ${FAIL} fail ==="; exit 1
fi

if git merge-base --is-ancestor "${COMMIT}" origin/arena/01a10111-alphacp 2>/dev/null; then
  ok "commit push ho chuka hai (origin/arena/01a10111-alphacp me shaamil)"
else
  bad "commit ${COMMIT:0:12} origin par nahi — user ka alphacp-sync 404 dega"
fi

if ! git cat-file -e "${COMMIT}:${PATH_IN_REPO}" 2>/dev/null; then
  bad "us commit me '${PATH_IN_REPO}' maujood nahi — galat commit pin hua hai"
  echo; echo "=== COMMANDS-CHECK: ${PASS} pass, ${FAIL} fail ==="; exit 1
fi
ok "commit me '${PATH_IN_REPO}' maujood hai"

# ---------------------------------------------------------------- bytes -------
GOT="$(git cat-file -p "${COMMIT}:${PATH_IN_REPO}" | sha256sum | cut -d' ' -f1)"
if [[ "${GOT}" == "${SHA}" ]]; then
  ok "sha256 match (${SHA:0:16}…)"
else
  bad "sha256 mismatch: COMMANDS.md me ${SHA:0:16}…, par commit ke file me ${GOT:0:16}… — purana file pin hua hai"
fi

# file ko temp me rakho (aage ke andar ke pins check karne ke liye)
WORK="$(mktemp -d)"; trap 'rm -rf "${WORK}"' EXIT
git cat-file -p "${COMMIT}:${PATH_IN_REPO}" > "${WORK}/updater.sh"

# ---------------------------------------------------------------- version -----
FILE_VER="$(sed -n 's/^UPDATER_VERSION="\(.*\)"$/\1/p' "${WORK}/updater.sh" | head -1)"
if [[ -n "${EXPECT_VERSION}" && "${FILE_VER}" == "${EXPECT_VERSION}" ]]; then
  ok "updater version banner = ${FILE_VER}"
else
  bad "version mismatch: command ka naam '${EXPECT_VERSION}', file ke andar '${FILE_VER:-<none>}'"
fi

banner="$(grep -m1 '^# updater ' "${WORK}/updater.sh" || true)"
grep -q "updater ${FILE_VER}" <<<"${banner}" && ok "banner comment = ${banner#\# }" || bad "banner comment galat: ${banner}"

# --------------------------------------------------- embedded artifact pins ---
PANEL_VERSION="$(sed -n 's/^PANEL_VERSION="\${ACP_PANEL_VERSION:-\(.*\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"
AGENT_VERSION="$(sed -n 's/^AGENT_VERSION="\${ACP_AGENT_VERSION:-\(.*\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"
BUNDLE_COMMIT="$(sed -n 's/^BUNDLE_COMMIT="\${ACP_PANEL_BUNDLE_COMMIT:-\([0-9a-f]\{40\}\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"
BUNDLE_SHA="$(sed -n 's/^BUNDLE_SHA256="\${ACP_PANEL_BUNDLE_SHA256:-\([0-9a-f]\{64\}\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"
AGENT_COMMIT="$(sed -n 's/^AGENT_COMMIT="\${ACP_AGENT_BUNDLE_COMMIT:-\([0-9a-f]\{40\}\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"
AGENT_SHA="$(sed -n 's/^AGENT_SHA256="\${ACP_AGENT_BUNDLE_SHA256:-\([0-9a-f]\{64\}\)}"$/\1/p' "${WORK}/updater.sh" | head -1)"

check_artifact() {  # name commit path sha
  local name="$1" commit="$2" path="$3" sha="$4"
  if [[ -z "${commit}" || -z "${sha}" ]]; then bad "${name}: pin missing"; return; fi
  if ! git cat-file -e "${commit}:${path}" 2>/dev/null; then
    bad "${name}: ${path} commit ${commit:0:12} me nahi hai"; return
  fi
  local got; got="$(git cat-file -p "${commit}:${path}" | sha256sum | cut -d' ' -f1)"
  if [[ "${got}" == "${sha}" ]]; then
    ok "${name}: ${path} @ ${commit:0:12} sha256 match"
  else
    bad "${name}: sha mismatch (pin ${sha:0:16}…, file ${got:0:16}…)"
  fi
  if git merge-base --is-ancestor "${commit}" origin/arena/01a10111-alphacp 2>/dev/null; then
    ok "${name}: artifact commit push ho chuka hai"
  else
    bad "${name}: artifact commit ${commit:0:12} origin par nahi"
  fi
}

check_artifact "panel ${PANEL_VERSION:-?}" "${BUNDLE_COMMIT}" "artifacts/panel-code-${PANEL_VERSION}.tar.gz" "${BUNDLE_SHA}"
check_artifact "agent ${AGENT_VERSION:-?}" "${AGENT_COMMIT}" "artifacts/agent-${AGENT_VERSION}.tar.gz" "${AGENT_SHA}"

# ------------------------------------------------ measured numbers nahi khaali -
if grep -q 'update-sim \*\*—' <<<"${NEXT_BLOCK}" || grep -q 'panel \*\*—' <<<"${NEXT_BLOCK}"; then
  bad "NEXT section me abhi khaali (—) test numbers hain"
else
  ok "test numbers bhare hue hain"
fi

echo
echo "=== COMMANDS-CHECK: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
