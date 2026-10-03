#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — Step 3 provisioning engine tests (sandbox, no system PHP needed)
#
#  * Agent handlers: php-wasm PHP 8.5 + FakeCommandExecutor (no real useradd)
#  * Covers create / suspend / unsuspend / terminate / quota / rollback
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
  mkdir -p "${PHPWASM_DIR}"
  (cd "${PHPWASM_DIR}" && npm init -y >/dev/null && npm i @php-wasm/cli >/dev/null)
fi
PHPW=(node "${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js" -d memory_limit=512M)

echo "=== provision-sim: agent account tasks (php-wasm) ==="
echo "PHP : $(PHP=8.5 "${PHPW[@]}" -r 'echo PHP_VERSION;')  (php-wasm)"
echo

OUT="$(PHP=8.5 "${PHPW[@]}" "${REPO}/agent/tests/run-tests.php" 2>&1)" || true
printf '%s\n' "${OUT}"
if grep -qE 'failed: 0$' <<< "${OUT}"; then
  echo
  echo "=== PROVISION-SIM: PASS ==="
  exit 0
fi
echo
echo "=== PROVISION-SIM: FAIL ==="
exit 1
