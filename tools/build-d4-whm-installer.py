#!/usr/bin/env python3
"""Build installer/d4-whm-fix.sh — Depth Wave D4: WHM Create Account + List Accounts + Packages.

Embeds (base64) 4 files from refs/live-theme-fix/ for the live 0.75.x panel:
  accounts-create.blade.php -> resources/views/accounts/create.blade.php (WHM-order + autofill + generator)
  accounts-index.blade.php  -> resources/views/accounts/index.blade.php (stats + search + quick suspend)
  packages-index.blade.php  -> resources/views/packages/index.blade.php (stats + search + feature badges)
  panel.js (v1.4)           -> public/assets/panel.js (domain -> username autofill)

Generated script: backup -> install -> view:clear + route:clear + php-fpm
reload -> health (https 8090/2083/2087 in 200/302) -> auto-rollback on fail.
"""
from __future__ import annotations

import base64
import hashlib
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "refs" / "live-theme-fix"
OUT = ROOT / "installer" / "d4-whm-fix.sh"
VERSION = "1.0"

FILES = [
    ("accounts-create.blade.php", "resources/views/accounts/create.blade.php"),
    ("accounts-index.blade.php", "resources/views/accounts/index.blade.php"),
    ("packages-index.blade.php", "resources/views/packages/index.blade.php"),
    ("panel.js", "public/assets/panel.js"),
]

blocks = []
for local, remote in FILES:
    raw = (SRC / local).read_bytes()
    b64 = base64.b64encode(raw).decode()
    sha = hashlib.sha256(raw).hexdigest()
    wrapped = "\n".join(b64[i:i + 76] for i in range(0, len(b64), 76))
    blocks.append((local, remote, wrapped, sha))

payload_vars = []
for i, (local, remote, b64, sha) in enumerate(blocks):
    payload_vars.append(f'F{i}_PATH="{remote}"\nF{i}_SHA="{sha}"\nF{i}_B64="$(cat <<\'B64EOF_{i}\'\n{b64}\nB64EOF_{i}\n)"')

script = r'''#!/usr/bin/env bash
# =============================================================================
# AlphaCP — D4 WHM DEPTH FIX v{VER}  (live panel 0.75.x line · 09 Oct 2026)
#
# Depth Wave D4: WHM Create Account + List Accounts + Packages ab WHM jaise.
# 4 files (SIRF views + JS — koi controller/routes/DB change NAHI, extra-safe):
#   1. accounts/create.blade.php -> WHM field-order (Domain pehle -> Username
#      auto-suggest), password Generate + strength meter + Show, package select
#      me limits summary, "kya provision hoga" + password policy cards
#   2. accounts/index.blade.php -> List Accounts: stats strip (total/active/
#      suspended/live), search, quick Suspend/Unsuspend buttons, Manage
#   3. packages/index.blade.php -> stats, search, Addon/Sub column, feature
#      badges, Add a Package
#   4. public/assets/panel.js (v1.4) -> domain -> username autofill (WHM jaisa)
# Safety: har file backup (.bak-d4whm-<stamp>) -> install -> view:clear +
#   route:clear + php-fpm graceful reload -> health 8090/2083/2087 ->
#   fail par AUTO-ROLLBACK.
# =============================================================================
set -Eeuo pipefail

D4_VERSION="{VER}"
PANEL_ROOT="${{PANEL_ROOT:-/usr/local/alphacp/panel}}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-d4-whm-fix.log"

C_B=$'\033[1m'; C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_0=$'\033[0m'
say() {{ printf '%s\n' "$*"; }}
log() {{ printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${{LOG_FILE}}" 2>/dev/null || true; }}
ok()  {{ log "OK   $*"; say "${{C_G}}[OK]${{C_0}} $*"; }}
warn(){{ log "WARN $*"; say "${{C_Y}}[!]${{C_0}} $*"; }}
die() {{ log "FAIL $*"; say "${{C_R}}[x]${{C_0}} $*"; say "Log: ${{LOG_FILE}}"; exit 1; }}

say ""
say "${{C_B}}== AlphaCP D4 WHM DEPTH FIX v${{D4_VERSION}} ==${{C_0}}"
[[ "${{EUID}}" -eq 0 ]] || die "root chahiye: sudo bash $0"
[[ -d "${{PANEL_ROOT}}/public/assets" ]] || die "panel nahi mila: ${{PANEL_ROOT}}"
[[ -f "${{PANEL_ROOT}}/public/assets/panel.js" ]] || die "pehle theme-fix v1.2 install karo (panel.js missing) — COMMANDS.md dekho"

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
    cp -a "${{dst}}" "${{dst}}.bak-d4whm-${{STAMP}}"
    chown --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
    chmod --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
  else
    chown --reference="${{PANEL_ROOT}}/public/assets/panel.css" "${{tmp}}" 2>/dev/null || true
    chmod 0644 "${{tmp}}" 2>/dev/null || true
  fi
  mv -f "${{tmp}}" "${{dst}}"
  if [[ "${{is_new}}" -eq 0 ]]; then
    ok "installed: ${{rel}}  (backup: $(basename "${{dst}}").bak-d4whm-${{STAMP}})"
  else
    ok "installed (NEW): ${{rel}}"
  fi
}}

rollback() {{
  warn "ROLLBACK shuru..."
  local rel dst
  for rel in __ROLLBACK_LIST__; do
    dst="${{PANEL_ROOT}}/${{rel}}"
    if [[ -f "${{dst}}.bak-d4whm-${{STAMP}}" ]]; then
      mv -f "${{dst}}.bak-d4whm-${{STAMP}}" "${{dst}}"
      warn "restored: ${{rel}}"
    fi
  done
  clear_caches
  warn "ROLLBACK done — panel pehle jaisa."
}}

clear_caches() {{
  local php=""
  for c in php8.4 php8.3 php; do command -v "$c" >/dev/null 2>&1 && {{ php="$c"; break; }}; done
  [[ -n "${{php}}" ]] || {{ warn "php nahi mila — caches manually clear karo"; return 0; }}
  local runas=""
  if id alphacp >/dev/null 2>&1; then runas="sudo -u alphacp"; fi
  ( cd "${{PANEL_ROOT}}" && ${{runas}} "${{php}}" artisan view:clear >>"${{LOG_FILE}}" 2>&1 ) \
    && ok "view cache cleared" || warn "view:clear fail (non-fatal)"
  ( cd "${{PANEL_ROOT}}" && ${{runas}} "${{php}}" artisan route:clear >>"${{LOG_FILE}}" 2>&1 ) \
    && ok "route cache cleared (naya PUT route register hoga)" || warn "route:clear fail (non-fatal)"
  local fpm
  for fpm in php8.4-fpm php8.3-fpm php-fpm; do
    if systemctl is-active --quiet "${{fpm}}" 2>/dev/null; then
      systemctl reload "${{fpm}}" >>"${{LOG_FILE}}" 2>&1 \
        && ok "${{fpm}} reloaded (graceful)" || warn "${{fpm}} reload fail (non-fatal)"
      break
    fi
  done
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
health || die "panel pehle se unhealthy hai — pehle panel-doctor chalao"

say ""
say "${{C_B}}-- Step 2: install (4 files, backup ke saath) --${{C_0}}"
__INSTALL_LINES__

say ""
say "${{C_B}}-- Step 3: caches clear + php-fpm reload --${{C_0}}"
clear_caches

say ""
say "${{C_B}}-- Step 4: health check --${{C_0}}"
if ! health; then
  rollback
  die "health check fail — sab files wapas purani (rollback ho gaya)"
fi

say ""
say "${{C_G}}${{C_B}}==> D4 WHM DEPTH FIX COMPLETE ✅${{C_0}}"
say "    Browser hard-refresh (Ctrl+Shift+R) karke dekho (WHM :2087):"
say "      Create Account : https://<server-ip>:2087 -> Create a New Account"
say "      List Accounts  : https://<server-ip>:2087 -> List Accounts"
say "      Packages       : https://<server-ip>:2087 -> Packages"
say "    Naya: domain->username autofill, password generator + meter,"
say "          stats, search, quick Suspend/Unsuspend, feature badges"
say "    Rollback kabhi bhi: *.bak-d4whm-${{STAMP}} files PANEL_ROOT me hain"
say "    — d4-whm-fix v${{D4_VERSION}}"
'''.replace("{VER}", VERSION).replace("{{", "\x00").replace("}}", "\x01")
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
