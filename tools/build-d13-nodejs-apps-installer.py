#!/usr/bin/env python3
"""Build installer/d13-nodejs-apps.sh — Phase-2 Wave D13: Node.js App Manager (PM2-style).

Installs on the LIVE server (panel 0.75.x + paneld agent):
  APP:   nodejs ensure (apt, agar node binary nahi hai) + PHP CLI guard (D12 lesson)
  AGENT: config/tasks.php (+ node.list/node.setup/node.control) + NodeTask base
         + 3 handlers (apps = systemd units alphacp-node-<user>-<app>, Restart=always)
  PANEL: NodejsSelectorController v2 (app manager) + nodejs view + routes (+2 POST)
Safety: multi-root install_one, paneld restart gate, full auto-rollback.
"""
from __future__ import annotations

import base64
import hashlib
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "refs" / "live-theme-fix"
OUT = ROOT / "installer" / "d13-nodejs-apps.sh"
VERSION = "1.0"

FILES = [
    ("d13-agent-tasks.php", "AGENT:config/tasks.php"),
    ("d13-NodeTask.php", "AGENT:src/Tasks/NodeTask.php"),
    ("d13-NodeAppList.php", "AGENT:src/Tasks/NodeAppList.php"),
    ("d13-NodeAppSetup.php", "AGENT:src/Tasks/NodeAppSetup.php"),
    ("d13-NodeAppControl.php", "AGENT:src/Tasks/NodeAppControl.php"),
    ("d13-NodejsSelectorController.php", "PANEL:app/Http/Controllers/NodejsSelectorController.php"),
    ("d13-nodejs-index.blade.php", "PANEL:resources/views/nodejs/index.blade.php"),
    ("d13-routes-web.php", "PANEL:routes/web.php"),
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
# AlphaCP — D13 NODE.JS APP MANAGER v{VER}  (live panel 0.75.x + paneld · 09 Oct 2026)
#
# Phase-2 Wave D13: cPanel "Setup Node.js App" — PM2-style process manager.
#   APP:   nodejs ensure (apt) + PHP CLI guard (D12 lesson: apt PHP switch fix)
#   AGENT: node.list / node.setup / node.control — har app ek systemd unit
#          (alphacp-node-<user>-<app>, user ke naam se, Restart=always,
#          log ~/nodeapps/<app>/app.log) — prefix fixed, system units safe
#   PANEL: Setup Node.js App page — create/start/stop/restart/remove + logs
# 8 files, multi-root. DB me KOI change nahi.
# Safety: backup (.bak-d13node-<stamp>) -> install -> caches -> paneld gate ->
#   health -> AUTO-ROLLBACK.
# =============================================================================
set -Eeuo pipefail

D13_VERSION="{VER}"
PANEL_ROOT="${{PANEL_ROOT:-/usr/local/alphacp/panel}}"
AGENT_ROOT="${{AGENT_ROOT:-/usr/local/alphacp/agent}}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
LOG_FILE="/var/log/alphacp-d13-nodejs-apps.log"

C_B=$'\033[1m'; C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_0=$'\033[0m'
say() {{ printf '%s\n' "$*"; }}
log() {{ printf '%s [%s]\n' "$(date -u '+%F %T')Z" "$*" >>"${{LOG_FILE}}" 2>/dev/null || true; }}
ok()  {{ log "OK   $*"; say "${{C_G}}[OK]${{C_0}} $*"; }}
warn(){{ log "WARN $*"; say "${{C_Y}}[!]${{C_0}} $*"; }}
die() {{ log "FAIL $*"; say "${{C_R}}[x]${{C_0}} $*"; say "Log: ${{LOG_FILE}}"; exit 1; }}

say ""
say "${{C_B}}== AlphaCP D13 NODE.JS APP MANAGER v${{D13_VERSION}} ==${{C_0}}"
[[ "${{EUID}}" -eq 0 ]] || die "root chahiye: sudo bash $0"
[[ -d "${{PANEL_ROOT}}/public/assets" ]] || die "panel nahi mila: ${{PANEL_ROOT}}"
[[ -f "${{PANEL_ROOT}}/public/assets/panel.js" ]] || die "pehle theme-fix v1.2 install karo (panel.js missing) — COMMANDS.md dekho"
[[ -f "${{AGENT_ROOT}}/config/tasks.php" ]] || die "agent nahi mila: ${{AGENT_ROOT}}"
grep -q "db.pmaSignon" "${{AGENT_ROOT}}/config/tasks.php" || die "agent tasks.php me D12 (db.pmaSignon) nahi — pehle D12 wave install karo"

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
    cp -a "${{dst}}" "${{dst}}.bak-d13node-${{STAMP}}"
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
    ok "installed: ${{rel}}  (backup: $(basename "${{dst}}").bak-d13node-${{STAMP}})"
  else
    ok "installed (NEW): ${{rel}}"
  fi
}}

rollback() {{
  warn "ROLLBACK shuru..."
  local rel dst
  for rel in __ROLLBACK_LIST__; do
    dst="$(resolve_dst "${{rel}}")"
    if [[ -f "${{dst}}.bak-d13node-${{STAMP}}" ]]; then
      mv -f "${{dst}}.bak-d13node-${{STAMP}}" "${{dst}}"
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
if [[ "${{LIVE_SRV}}" -eq 1 ]]; then ok "nginx active (LIVE mode: node ensure + guards chalenge)"; else warn "nginx systemd active nahi (sim?) — apt/guard steps skip"; fi

if [[ "${{LIVE_SRV}}" -eq 1 ]]; then
  say ""
  say "${{C_B}}-- Step 1b: Node.js ensure + PHP guard --${{C_0}}"
  if command -v node >/dev/null 2>&1; then
    ok "Node.js pehle se installed: $(node -v 2>/dev/null || echo '?') ($(command -v node))"
  else
    DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs >>"${{LOG_FILE}}" 2>&1 \
      || die "apt-get install nodejs FAIL — log dekho (abhi KUCH install nahi hua, panel safe)"
    command -v node >/dev/null 2>&1 || die "apt chala par node binary nahi mila"
    ok "Node.js installed via apt: $(node -v 2>/dev/null || echo '?')"
  fi
  # PHP CLI guard (D12 lesson: apt naya PHP laakar /usr/bin/php switch kar deta hai)
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
    sed -i.bak-d13node "s|ExecStart=/usr/bin/php |ExecStart=${{PHP_REAL}} |" /etc/systemd/system/paneld.service
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
chown root:root "${{AGENT_ROOT}}/src/Tasks/NodeTask.php" "${{AGENT_ROOT}}/src/Tasks/NodeAppList.php" \
  "${{AGENT_ROOT}}/src/Tasks/NodeAppSetup.php" "${{AGENT_ROOT}}/src/Tasks/NodeAppControl.php" 2>/dev/null || true
clear_caches

say ""
say "${{C_B}}-- Step 3b: paneld restart (agent registry reload) --${{C_0}}"
if [[ "${{PANELD_WAS_ACTIVE}}" -eq 1 ]]; then
  if systemctl restart paneld >>"${{LOG_FILE}}" 2>&1 && sleep 2 && systemctl is-active paneld >/dev/null 2>&1; then
    ok "paneld restarted — node.list/node.setup/node.control ab live"
  else
    warn "paneld restart FAIL — full rollback"
    rollback
    systemctl restart paneld >>"${{LOG_FILE}}" 2>&1 || true
    die "paneld wapas start nahi hua naye config ke saath — sab files restore (rollback ho gaya)"
  fi
else
  warn "paneld restart skip (systemd active nahi tha)"
fi

say ""
say "${{C_B}}-- Step 4: health check --${{C_0}}"
if ! health; then
  rollback
  die "health check fail — sab files wapas purani (rollback ho gaya)"
fi

say ""
say "${{C_G}}${{C_B}}==> D13 NODE.JS APP MANAGER COMPLETE ✅${{C_0}}"
say "    Browser hard-refresh (Ctrl+Shift+R) karke 2083 panel kholo:"
say "      Software -> Setup Node.js App (Node.js Selector tile)"
say "      App name + port deke 'Create & Start' dabao — sample app turant chal jayegi"
say "      Table me status/PID/log dikhega; Start/Stop/Restart/Remove buttons"
say "      Har app crash par auto-restart (PM2-style), boot par auto-start"
say "    Rollback kabhi bhi: *.bak-d13node-${{STAMP}} files apni jagah par hain"
say "    — d13-nodejs-apps v${{D13_VERSION}}"
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
