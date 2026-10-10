#!/usr/bin/env python3
"""Build installer/d16-branding-sweep.sh — D16: AlphaCP-only branding + progress removal.

PANEL-only (15 blade views). Agent/routes/DB me KOI change nahi.
"""
from __future__ import annotations

import base64
import hashlib
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "refs" / "live-theme-fix"
OUT = ROOT / "installer" / "d16-branding-sweep.sh"
VERSION = "1.0"

FILES = [
    ("d16-dash-sections.blade.php", "PANEL:resources/views/partials/dash-sections.blade.php"),
    ("d16-panel-layout.blade.php", "PANEL:resources/views/layouts/panel.blade.php"),
    ("d16-login.blade.php", "PANEL:resources/views/auth/login.blade.php"),
    ("d16-dashboard-cpanel.blade.php", "PANEL:resources/views/dashboard-cpanel.blade.php"),
    ("d16-dashboard-whm.blade.php", "PANEL:resources/views/dashboard-whm.blade.php"),
    ("d16-whm-sidebar.blade.php", "PANEL:resources/views/partials/whm-sidebar.blade.php"),
    ("d16-ports-index.blade.php", "PANEL:resources/views/ports/index.blade.php"),
    ("d16-terminal-index.blade.php", "PANEL:resources/views/terminal/index.blade.php"),
    ("d16-phpmyadmin-index.blade.php", "PANEL:resources/views/phpmyadmin/index.blade.php"),
    ("d16-system-services.blade.php", "PANEL:resources/views/system/services.blade.php"),
    ("d16-email-index.blade.php", "PANEL:resources/views/email/index.blade.php"),
    ("d16-mysql-users-index.blade.php", "PANEL:resources/views/mysql-users/index.blade.php"),
    ("d16-accounts-create.blade.php", "PANEL:resources/views/accounts/create.blade.php"),
    ("d16-packages-index.blade.php", "PANEL:resources/views/packages/index.blade.php"),
    ("d16-git-index.blade.php", "PANEL:resources/views/git/index.blade.php"),
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
# AlphaCP — D16 BRANDING SWEEP v{VER}  (live panel 0.75.x + paneld · 09 Oct 2026)
#
# D16: AlphaCP-only branding sweep — login/dashboard/footer/sidebar/ports/
#   subtitles se visible cPanel/WHM naam gaye; "feature progress 103 tools"
#   card dashboard se remove (project complete hai).
# 15 PANEL view files. Agent/routes/DB me KOI change nahi.
# Safety: backup (.bak-d16brand-<stamp>) -> install -> caches -> paneld gate ->
#   health -> AUTO-ROLLBACK.
# =============================================================================
set -Eeuo pipefail

D16_VERSION="{VER}"
PANEL_ROOT="${{PANEL_ROOT:-/usr/local/alphacp/panel}}"
AGENT_ROOT="${{AGENT_ROOT:-/usr/local/alphacp/agent}}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-d16-branding-sweep.log"

C_B=$'\033[1m'; C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_0=$'\033[0m'
say() {{ printf '%s\n' "$*"; }}
log() {{ printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${{LOG_FILE}}" 2>/dev/null || true; }}
ok()  {{ log "OK   $*"; say "${{C_G}}[OK]${{C_0}} $*"; }}
warn(){{ log "WARN $*"; say "${{C_Y}}[!]${{C_0}} $*"; }}
die() {{ log "FAIL $*"; say "${{C_R}}[x]${{C_0}} $*"; say "Log: ${{LOG_FILE}}"; exit 1; }}

say ""
say "${{C_B}}== AlphaCP D16 BRANDING SWEEP v${{D16_VERSION}} ==${{C_0}}"
[[ "${{EUID}}" -eq 0 ]] || die "root chahiye: sudo bash $0"
[[ -d "${{PANEL_ROOT}}/public/assets" ]] || die "panel nahi mila: ${{PANEL_ROOT}}"
[[ -f "${{PANEL_ROOT}}/public/assets/panel.js" ]] || die "pehle theme-fix v1.2 install karo (panel.js missing) — COMMANDS.md dekho"
[[ -f "${{AGENT_ROOT}}/config/tasks.php" ]] || die "agent nahi mila: ${{AGENT_ROOT}}"

__PAYLOADS__

resolve_dst() {{
  local rel="$1"
  case "${{rel}}" in
    AGENT:*)  printf '%s/%s' "${{AGENT_ROOT}}" "${{rel#AGENT:}}" ;;
    PANEL:*)  printf '%s/%s' "${{PANEL_ROOT}}" "${{rel#PANEL:}}" ;;
    *)        printf '%s/%s' "${{PANEL_ROOT}}" "${{rel}}" ;;
  esac
}}

install_one() {{
  local rel="$1" sha="$2" b64="$3"
  local dst; dst="$(resolve_dst "${{rel}}")"
  local is_new=0
  [[ -f "${{dst}}" ]] || is_new=1
  mkdir -p "$(dirname "${{dst}}")"
  local tmp="${{dst}}.new-${{STAMP}}"
  printf '%s' "${{b64}}" | base64 -d > "${{tmp}}" || die "decode fail: ${{rel}}"
  local got; got="$(sha256sum "${{tmp}}" | awk '{{print $1}}')"
  [[ "${{got}}" == "${{sha}}" ]] || {{ rm -f "${{tmp}}"; die "sha256 mismatch: ${{rel}} (${{got}})"; }}
  if [[ "${{is_new}}" -eq 0 ]]; then
    cp -a "${{dst}}" "${{dst}}.bak-d16brand-${{STAMP}}"
    chown --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
    chmod --reference="${{dst}}" "${{tmp}}" 2>/dev/null || true
  else
    case "${{rel}}" in
      PANEL:*) chown --reference="${{PANEL_ROOT}}/public/assets/panel.css" "${{tmp}}" 2>/dev/null || true ;;
      *)       chown root:root "${{tmp}}" 2>/dev/null || true ;;
    esac
    chmod 0644 "${{tmp}}" 2>/dev/null || true
  fi
  mv -f "${{tmp}}" "${{dst}}"
  if [[ "${{is_new}}" -eq 0 ]]; then
    ok "installed: ${{rel}}  (backup: $(basename "${{dst}}").bak-d16brand-${{STAMP}})"
  else
    ok "installed (NEW): ${{rel}}"
  fi
}}

rollback() {{
  warn "ROLLBACK shuru..."
  local rel dst
  for rel in __ROLLBACK_LIST__; do
    dst="$(resolve_dst "${{rel}}")"
    if [[ -f "${{dst}}.bak-d16brand-${{STAMP}}" ]]; then
      mv -f "${{dst}}.bak-d16brand-${{STAMP}}" "${{dst}}"
      warn "restored: ${{rel}}"
    fi
  done
  clear_caches
  if [[ "${{PANELD_WAS_ACTIVE:-0}}" -eq 1 ]]; then systemctl restart paneld >>"${{LOG_FILE}}" 2>&1 || true; fi
  warn "ROLLBACK done — panel + agent pehle jaise."
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
    && ok "route cache cleared" || warn "route:clear fail (non-fatal)"
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
PANELD_WAS_ACTIVE=0
systemctl is-active paneld >/dev/null 2>&1 && PANELD_WAS_ACTIVE=1
if [[ "${{PANELD_WAS_ACTIVE}}" -eq 1 ]]; then ok "paneld active hai (restart gate ON)"; else warn "paneld systemd active nahi (sim/container?) — agent restart skip hoga"; fi
LIVE_SRV=0
systemctl is-active nginx >/dev/null 2>&1 && LIVE_SRV=1
if [[ "${{LIVE_SRV}}" -eq 1 ]]; then ok "nginx active (LIVE mode: guards chalenge)"; else warn "nginx systemd active nahi (sim?) — apt/guard steps skip"; fi

if [[ "${{LIVE_SRV}}" -eq 1 ]]; then
  say ""
  say "${{C_B}}-- Step 1b: PHP guard + paneld check --${{C_0}}"
  # PHP CLI guard (D12 lesson)
  if /usr/bin/php -m 2>/dev/null | grep -qix 'pdo_mysql'; then
    ok "php CLI pdo_mysql OK ($(readlink -f /usr/bin/php))"
  else
    warn "/usr/bin/php ($(readlink -f /usr/bin/php)) me pdo_mysql NAHI — alternatives restore karte hain"
    PHP_FIXED=0
    for pv in php8.4 php8.3 php8.2; do
      if [[ -x "/usr/bin/${{pv}}" ]] && "/usr/bin/${{pv}}" -m 2>/dev/null | grep -qix 'pdo_mysql'; then
        update-alternatives --set php "/usr/bin/${{pv}}" >>"${{LOG_FILE}}" 2>&1 || true
        if /usr/bin/php -m 2>/dev/null | grep -qix 'pdo_mysql'; then
          ok "php alternative -> ${{pv}} (pdo_mysql wapas)"
          PHP_FIXED=1
          break
        fi
      fi
    done
    [[ "${{PHP_FIXED}}" -eq 1 ]] || die "kisi php binary me pdo_mysql nahi — paneld nahi chalega (files abhi install NAHI hui, panel safe)"
  fi
  # paneld unit pin (agar abhi tak generic /usr/bin/php par hai)
  PHP_REAL="$(readlink -f /usr/bin/php)"
  if grep -q "ExecStart=/usr/bin/php " /etc/systemd/system/paneld.service 2>/dev/null; then
    sed -i.bak-d16brand "s|ExecStart=/usr/bin/php |ExecStart=${{PHP_REAL}} |" /etc/systemd/system/paneld.service
    systemctl daemon-reload
    ok "paneld unit pinned: ${{PHP_REAL}}"
  fi
  # paneld crash-loop recovery — restart gate wapas ON
  if [[ "${{PANELD_WAS_ACTIVE}}" -ne 1 ]]; then
    systemctl restart paneld >>"${{LOG_FILE}}" 2>&1 || true
    sleep 2
    if systemctl is-active paneld >/dev/null 2>&1; then
      PANELD_WAS_ACTIVE=1
      ok "paneld recover ho gaya (restart gate wapas ON)"
    else
      die "paneld active nahi ho pa raha — 'sudo systemctl status paneld' dekho (files abhi install NAHI hui)"
    fi
  fi
fi

say ""
say "${{C_B}}-- Step 2: install (__NFILES__ files, backup ke saath) --${{C_0}}"
__INSTALL_LINES__

say ""
say "${{C_B}}-- Step 3: perms + caches clear + php-fpm reload --${{C_0}}"
clear_caches

say ""
say "${{C_B}}-- Step 4: health check --${{C_0}}"
if ! health; then
  rollback
  die "health check fail — sab files wapas purani (rollback ho gaya)"
fi

say ""
say "${{C_G}}${{C_B}}==> D16 BRANDING SWEEP COMPLETE ✅${{C_0}}"
say "    Browser hard-refresh (Ctrl+Shift+R) karke dekho:"
say "      Login pages (2083/2087): sirf AlphaCP branding — koi cPanel/WHM naam nahi"
say "      Dashboard: feature-progress (103 tools) card ab GONE"
say "      Footer / sidebar / Ports / subtitles: sab AlphaCP wording"
say "    Rollback kabhi bhi: *.bak-d16brand-${{STAMP}} files apni jagah par hain"
say "    — d16-branding-sweep v${{D16_VERSION}}"
'''.replace("{VER}", VERSION).replace("{{", "\x00").replace("}}", "\x01")
script = script.replace("\x00", "{").replace("\x01", "}")
script = script.replace("__PAYLOADS__", "\n\n".join(payload_vars))
install_lines = "\n".join(f'install_one "${{F{i}_PATH}}" "${{F{i}_SHA}}" "${{F{i}_B64}}"' for i in range(len(FILES)))
rollback_list = " ".join(f'"${{F{i}_PATH}}"' for i in range(len(FILES)))
script = script.replace("__INSTALL_LINES__", install_lines)
script = script.replace("__ROLLBACK_LIST__", rollback_list)
script = script.replace("__NFILES__", str(len(FILES)))

OUT.write_text(script)
OUT.chmod(0o755)

check = subprocess.run(["bash", "-n", str(OUT)], capture_output=True, text=True)
if check.returncode != 0:
    raise SystemExit(f"bash -n FAILED:\n{check.stderr}")

sha = hashlib.sha256(OUT.read_bytes()).hexdigest()
print(f"built: {OUT.relative_to(ROOT)} ({OUT.stat().st_size // 1024} KB) — bash -n OK")
print(f"sha256: {sha}")
