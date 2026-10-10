#!/usr/bin/env python3
"""Build installer/theme-fix.sh — self-contained live-panel theme hotfix.

Embeds (base64) the 3 fixed files from refs/live-theme-fix/:
  panel.css                   -> public/assets/panel.css
  dashboard-cpanel.blade.php  -> resources/views/dashboard-cpanel.blade.php
  whm-sidebar.blade.php       -> resources/views/partials/whm-sidebar.blade.php

The generated script: backup -> install -> view:clear -> health check
(https 8090/2083/2087 == 200) -> auto-rollback on failure.
"""
from __future__ import annotations

import base64
import hashlib
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "refs" / "live-theme-fix"
OUT = ROOT / "installer" / "theme-fix.sh"
VERSION = "1.2"

FILES = [
    ("panel.css", "public/assets/panel.css"),
    ("panel.js", "public/assets/panel.js"),
    ("panel-layout.blade.php", "resources/views/layouts/panel.blade.php"),
    ("dashboard-cpanel.blade.php", "resources/views/dashboard-cpanel.blade.php"),
    ("whm-sidebar.blade.php", "resources/views/partials/whm-sidebar.blade.php"),
    ("icons.blade.php", "resources/views/partials/icons.blade.php"),
    ("tile.blade.php", "resources/views/partials/tile.blade.php"),
]

blocks = []
for local, remote in FILES:
    raw = (SRC / local).read_bytes()
    b64 = base64.b64encode(raw).decode()
    sha = hashlib.sha256(raw).hexdigest()
    # wrap base64 at 76 cols
    wrapped = "\n".join(b64[i:i + 76] for i in range(0, len(b64), 76))
    blocks.append((local, remote, wrapped, sha))

payload_vars = []
for i, (local, remote, b64, sha) in enumerate(blocks):
    payload_vars.append(f'F{i}_PATH="{remote}"\nF{i}_SHA="{sha}"\nF{i}_B64="$(cat <<\'B64EOF_{i}\'\n{b64}\nB64EOF_{i}\n)"')

script = r'''#!/usr/bin/env bash
# =============================================================================
# AlphaCP — THEME FIX v{VER}  (live panel 0.75.x line · 09 Oct 2026)
#
# Kya karta hai (7 files — koi DB/composer/migration NAHI):
#   1. public/assets/panel.css                    -> DESIGN-PARITY v3 (clean cPanel-company theme:
#                                                    dark charcoal sidenav + orange #FF6C2C + white cards;
#                                                    5 purani conflicting CSS layers ki jagah EK coherent file)
#   2. resources/views/dashboard-cpanel.blade.php -> stray <div class="grid cols-4"> wrapper remove
#                                                    (div 16/15 imbalance — client dashboard layout tootta tha)
#   3. resources/views/partials/whm-sidebar.blade.php -> galat outer <nav class="side-tree"> wrapper remove
#                                                    (WHM sidebar dark panel me white box dikhta tha)
#
#   4. resources/views/layouts/panel.blade.php  -> body.mode-cpanel/mode-whm class (cPanel=LIGHT,
#                                                    WHM=DARK — ab dono alag dikhte hain) + hamburger
#                                                    menu JS capture-phase rewrite (ab pakka chalega)
#   v1.2 ROOT-CAUSE FIX: CSP `script-src 'self'` saara INLINE JS block karta tha
#   (isliye hamburger/menu/search kabhi nahi chalte the) -> saara JS ab EXTERNAL
#   public/assets/panel.js me (CSP-safe, security strict hi rehti hai).
#   + icons.blade.php v2: 36 distinct product icons + naam-se mapping
#   + tile.blade.php: tool ke naam/route se sahi icon (pehle sab 'folder' the)
# Safety: har file ka backup (.bak-themefix-<stamp>) -> install -> view:clear ->
#         health check https 8090/2083/2087 == 200 -> fail par AUTO-ROLLBACK.
# =============================================================================
set -Eeuo pipefail

THEME_FIX_VERSION="{VER}"
PANEL_ROOT="${{PANEL_ROOT:-/usr/local/alphacp/panel}}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-theme-fix.log"

C_B=$'\033[1m'; C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_0=$'\033[0m'
say() {{ printf '%s\n' "$*"; }}
log() {{ printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${{LOG_FILE}}" 2>/dev/null || true; }}
ok()  {{ log "OK   $*"; say "${{C_G}}[OK]${{C_0}} $*"; }}
warn(){{ log "WARN $*"; say "${{C_Y}}[!]${{C_0}} $*"; }}
die() {{ log "FAIL $*"; say "${{C_R}}[x]${{C_0}} $*"; say "Log: ${{LOG_FILE}}"; exit 1; }}

say ""
say "${{C_B}}== AlphaCP THEME FIX v${{THEME_FIX_VERSION}} ==${{C_0}}"
[[ "${{EUID}}" -eq 0 ]] || die "root chahiye: sudo bash $0"
[[ -d "${{PANEL_ROOT}}/public/assets" ]] || die "panel nahi mila: ${{PANEL_ROOT}} (ye script sirf installed AlphaCP ke liye hai)"

__PAYLOADS__

install_one() {{
  local rel="$1" sha="$2" b64="$3"
  local dst="${{PANEL_ROOT}}/${{rel}}"
  local is_new=0
  [[ -f "${{dst}}" ]] || is_new=1
  local tmp="${{dst}}.new-${{STAMP}}"
  printf '%s' "${{b64}}" | base64 -d > "${{tmp}}" || die "decode fail: ${{rel}}"
  local got; got="$(sha256sum "${{tmp}}" | awk '{{print $1}}')"
  [[ "${{got}}" == "${{sha}}" ]] || {{ rm -f "${{tmp}}"; die "sha256 mismatch: ${{rel}} (${{got}})"; }}
  if [[ "${{is_new}}" -eq 0 ]]; then
    cp -a "${{dst}}" "${{dst}}.bak-themefix-${{STAMP}}"
    chown --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
    chmod --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
  else
    chown --reference="${{PANEL_ROOT}}/public/assets/panel.css" "${{tmp}}" 2>/dev/null || true
    chmod 0644 "${{tmp}}" 2>/dev/null || true
  fi
  mv -f "${{tmp}}" "${{dst}}"
  if [[ "${{is_new}}" -eq 0 ]]; then
    ok "installed: ${{rel}}  (backup: $(basename "${{dst}}").bak-themefix-${{STAMP}})"
  else
    ok "installed (NEW): ${{rel}}"
  fi
}}

rollback() {{
  warn "ROLLBACK shuru..."
  local rel dst
  for rel in __ROLLBACK_LIST__; do
    dst="${{PANEL_ROOT}}/${{rel}}"
    if [[ -f "${{dst}}.bak-themefix-${{STAMP}}" ]]; then
      mv -f "${{dst}}.bak-themefix-${{STAMP}}" "${{dst}}"
      warn "restored: ${{rel}}"
    elif [[ "${{rel}}" == "public/assets/panel.js" && -f "${{dst}}" ]]; then
      rm -f "${{dst}}"
      warn "removed (was new): ${{rel}}"
    fi
  done
  clear_views
  warn "ROLLBACK done — panel pehle jaisa."
}}

clear_views() {{
  local php=""
  for c in php8.4 php8.3 php; do command -v "$c" >/dev/null 2>&1 && {{ php="$c"; break; }}; done
  [[ -n "${{php}}" ]] || {{ warn "php nahi mila — view cache manually clear karo"; return 0; }}
  local runas=""
  if id alphacp >/dev/null 2>&1; then runas="sudo -u alphacp"; fi
  ( cd "${{PANEL_ROOT}}" && ${{runas}} "${{php}}" artisan view:clear >>"${{LOG_FILE}}" 2>&1 ) \
    && ok "view cache cleared" || warn "view:clear fail (non-fatal) — compiled views khud refresh ho jayenge"
}}

health() {{
  local port code bad=0
  for port in 8090 2083 2087; do
    code="$(curl -k -s -o /dev/null -w '%{{http_code}}' -m 15 "https://127.0.0.1:${{port}}/" 2>/dev/null || echo 000)"
    if [[ "${{code}}" == "200" || "${{code}}" == "302" ]]; then
      ok "health :${{port}} -> HTTP ${{code}}"
    else
      warn "health :${{port}} -> HTTP ${{code}}"
      bad=1
    fi
  done
  return "${{bad}}"
}}

say ""
say "${{C_B}}-- Step 1: pre-check --${{C_0}}"
health || die "panel pehle se unhealthy hai — pehle panel-doctor chalao, phir theme fix"

say ""
say "${{C_B}}-- Step 2: install (7 files, backup ke saath) --${{C_0}}"
__INSTALL_LINES__

say ""
say "${{C_B}}-- Step 3: view cache clear --${{C_0}}"
clear_views

say ""
say "${{C_B}}-- Step 4: health check --${{C_0}}"
if ! health; then
  rollback
  die "health check fail — sab files wapas purani (rollback ho gaya)"
fi

say ""
say "${{C_G}}${{C_B}}==> THEME FIX COMPLETE ✅${{C_0}}"
say "    Browser me hard-refresh karo (Ctrl+Shift+R):"
say "      cPanel : https://<server-ip>:2083"
say "      WHM    : https://<server-ip>:2087"
say "    Rollback kabhi bhi: *.bak-themefix-${{STAMP}} files PANEL_ROOT me hain"
say "    — theme-fix v${{THEME_FIX_VERSION}}"
'''.replace("{VER}", VERSION).replace("{{", "\x00").replace("}}", "\x01")
script = script.replace("{", "{").replace("}", "}")
script = script.replace("\x00", "{").replace("\x01", "}")
script = script.replace("__PAYLOADS__", "\n\n".join(payload_vars))
install_lines = "\n".join(f'install_one "${{F{i}_PATH}}" "${{F{i}_SHA}}" "${{F{i}_B64}}"' for i in range(len(FILES)))
rollback_list = " ".join(f'"${{F{i}_PATH}}"' for i in range(len(FILES)))
script = script.replace("__INSTALL_LINES__", install_lines)
script = script.replace("__ROLLBACK_LIST__", rollback_list)

OUT.write_text(script)
OUT.chmod(0o755)

check = subprocess.run(["bash", "-n", str(OUT)], capture_output=True, text=True)
if check.returncode != 0:
    raise SystemExit(f"bash -n FAILED:\n{check.stderr}")

sha = hashlib.sha256(OUT.read_bytes()).hexdigest()
print(f"built: {OUT.relative_to(ROOT)} ({OUT.stat().st_size // 1024} KB) — bash -n OK")
print(f"sha256: {sha}")
