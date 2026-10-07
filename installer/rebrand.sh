#!/usr/bin/env bash
# ============================================================================
# AlphaCP — REBRAND (cPanel/WHM company-words hatao, AlphaCP branding lagao) v1.0
# Sirf resources/views par targeted sed; backend/API (json-api, WHM API) untouched.
# Idempotent: REBRAND_DONE marker se dobara nahi chalta.
# ============================================================================
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Rebrand installer  v1.0 (cPanel/WHM -> AlphaCP)"
echo "=================================================="

if grep -q "REBRAND_DONE" "$PANEL/resources/views/layouts/panel.blade.php" 2>/dev/null; then
  echo "[OK] rebrand pehle se applied — skipping"
else
  echo "== Step 1: views rebrand (targeted sed) =="
  find "$PANEL/resources/views" -name '*.blade.php' -print0 | xargs -0 sed -i \
    -e 's/AlphaCP WHM/AlphaCP/g' \
    -e 's/cPanel parity progress/AlphaCP feature progress/g' \
    -e 's/cPanel-parity control panel/AlphaCP control panel/g' \
    -e 's/customer cPanel/customer account panel/g' \
    -e 's/use cPanel/use the account panel/g' \
    -e 's/WHM (admin)/Server Manager (admin)/g' \
    -e 's/Web Host Manager/Server Manager/g' \
    -e 's/WHM/Server Manager/g' \
    -e 's/cPanel/Account Panel/g'
  sed -i '1i {{-- REBRAND_DONE --}}' "$PANEL/resources/views/layouts/panel.blade.php"
  echo "[OK] views rebranded"
fi

echo "== Step 2: view cache clear =="
cd "$PANEL"
php artisan view:clear || true
echo "[OK] view cache cleared"

echo "=================================================="
echo " ==> REBRAND v1.0 APPLIED  (WHM->Server Manager, cPanel->Account Panel)"
echo "=================================================="
alphacp-sync || true
