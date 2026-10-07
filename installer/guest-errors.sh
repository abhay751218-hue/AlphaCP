#!/usr/bin/env bash
# ============================================================================
# AlphaCP — GUEST-SAFE ERROR PAGES installer  v1.0
# Bug: resources/views/errors/404.blade.php → layouts.panel extend karta hai,
# aur uske header me `auth()->user()->username` tha. Logged-out visitor koi
# galat/404/405 URL khole to panel 500 crash deta tha (customer-facing!).
# Fix: header ko null-safe banao — logged-in behaviour bilkul same rehta hai.
# Idempotent; pehle backup (.bak) leta hai.
# ============================================================================
set -euo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
LAYOUT="$PANEL/resources/views/layouts/panel.blade.php"
echo "=================================================="
echo " AlphaCP Guest-Safe Error Pages installer  v1.0"
echo "=================================================="

if [[ ! -f "$LAYOUT" ]]; then
  echo "[FAIL] layout nahi mila: $LAYOUT"
  exit 1
fi

echo "== Step 1: backup =="
if [[ ! -f "$LAYOUT.bak" ]]; then
  cp "$LAYOUT" "$LAYOUT.bak"
  echo "  + $LAYOUT.bak"
else
  echo "  [OK] backup pehle se hai"
fi

echo "== Step 2: header ko null-safe banao (idempotent) =="
ACP_LAYOUT="$LAYOUT" python3 - <<'PY'
import os, re, sys

path = os.environ['ACP_LAYOUT']
src = open(path).read()

if "auth()->user()?->username" in src:
    print("[OK] already guest-safe — kuch nahi kiya")
    sys.exit(0)

before = src
pairs = [
    ("strtoupper(substr(auth()->user()->username, 0, 1))",
     "strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1))"),
    ("{{ auth()->user()->username }}<br>",
     "{{ auth()->user()?->username ?? 'Guest' }}<br>"),
    ("auth()->user()->role?->label ?? 'user'",
     "auth()->user()?->role?->label ?? 'user'"),
]
for old, new in pairs:
    if old not in src:
        print("[WARN] pattern nahi mila (base layout badla?):", old[:48])
    src = src.replace(old, new)

if src == before:
    print("[FAIL] koi patch apply nahi hua — layout manual check karo")
    sys.exit(1)

open(path, 'w').write(src)
leftover = re.findall(r"auth\(\)->user\(\)->", src)
print("[OK] header null-safe ban gaya  (bache hue unsafe calls: %d)" % len(leftover))
PY

echo "== Step 3: compiled views clear =="
cd "$PANEL"
php artisan view:clear || true

echo "=================================================="
echo " ==> GUEST-SAFE ERROR PAGES v1.0 APPLIED  (404/405 par ab crash nahi)"
echo "=================================================="
alphacp-sync || true
