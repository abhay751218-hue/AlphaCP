#!/usr/bin/env bash
# ============================================================================
# BRAND GUARD — user-facing views me "cPanel"/"WHM" (company names) allowed NAHI.
# Brand = AlphaCP · admin side = "Server Manager" · user side = "Account Panel".
# Ye script fail hoti hai agar koi feature view me ye words hon — taaki rebrand
# dobara kabhi na chalana pade (koi bhi AI naya feature banaye to bhi).
# Sirf *.blade.php (user-visible) scan hote hain; code comments/technical API nahi.
# ============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
BAD=0
while IFS=: read -r file line match; do
  echo "BRAND-VIOLATION  ${file}:${line}: ${match}"
  BAD=1
done < <(grep -rnE "\bcPanel\b|\bWHM\b" "${REPO}/features" --include='*.blade.php' 2>/dev/null)

if [[ ${BAD} -eq 0 ]]; then
  echo "BRAND GUARD: PASS — koi cPanel/WHM string views me nahi."
  exit 0
else
  echo "BRAND GUARD: FAIL — upar ke words hatao (AlphaCP / Server Manager / Account Panel use karo)."
  exit 1
fi
