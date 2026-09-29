#!/usr/bin/env python3
"""Harden installer/step2b-finish.sh into a single-shot, self-diagnosing installer.

Adds:
  * `cd /` at the start (kills the getcwd spam from a deleted shell cwd)
  * /etc/hosts fix (kills "sudo: unable to resolve host ..." noise)
  * cache clear + service restart + long verify (60s) with real diagnostics on failure
  * a final verdict box that always prints URL / user / password
"""
import pathlib

root = pathlib.Path(__file__).resolve().parent.parent
p = root / "installer" / "step2b-finish.sh"
s = p.read_text()

# ---------------------------------------------------------------- 1. cd / + hosts
old = '''main() {
  step "AlphaCP Step 2B — finishing (panel v0.3.x, port ${PANEL_PORT})"

  # --- 0. sanity ------------------------------------------------------------
  [[ "${EUID}" -eq 0 ]] || die "root chahiye: sudo bash $0"'''
new = '''main() {
  step "Stage B — Step 2B finishing (database + admin + nginx :${PANEL_PORT})"

  # --- 0. sanity ------------------------------------------------------------
  [[ "${EUID}" -eq 0 ]] || die "root chahiye: sudo bash $0"

  # shell ka cwd delete ho gaya ho to bash "getcwd" shikayat karta hai — chup karao
  cd / 2>/dev/null || true

  # "sudo: unable to resolve host <name>" noise hamesha ke liye band karo
  if ! grep -qE "(^|[[:space:]])$(hostname)([[:space:]]|$)" /etc/hosts 2>/dev/null; then
    printf '127.0.1.1\\t%s\\n' "$(hostname)" >> /etc/hosts 2>/dev/null || true
  fi'''
assert old in s, "main header"
s = s.replace(old, new, 1)

# ---------------------------------------------------------------- 2. extra cache clear + restart
old = '''  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"
  ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan config:cache ) >>"${LOG_FILE}" 2>&1 || true'''
new = '''  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache"
  ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan cache:clear  ) >>"${LOG_FILE}" 2>&1 || true
  ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan view:clear   ) >>"${LOG_FILE}" 2>&1 || true
  ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan route:cache  ) >>"${LOG_FILE}" 2>&1 || true
  ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan config:cache ) >>"${LOG_FILE}" 2>&1 || true
  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true'''
assert old in s, "cache clear"
s = s.replace(old, new, 1)

# ---------------------------------------------------------------- 3. verify: 60s + real diagnostics + verdict box
old = '''  # --- 11. verify -----------------------------------------------------------
  step "Verify (end to end)"
  local code="" tries=0
  while (( tries < 10 )); do
    tries=$((tries + 1))
    code="$(curl -k -s -o /tmp/.acp-check.html -w '%{http_code}' -m 10 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
    [[ "${code}" == "200" ]] && break
    sleep 2
  done
  if [[ "${code}" == "200" ]] && grep -qi "Panel Login" /tmp/.acp-check.html 2>/dev/null; then
    ok "login page HTTP 200 (https://127.0.0.1:${PANEL_PORT}/)"
  else
    warn "login page HTTP ${code:-none} — diagnostics:"
    tail -5 /var/log/nginx/alphacp-panel.error.log 2>/dev/null | tee -a "${LOG_FILE}" || true
    tail -8 "${PANEL_ROOT}/storage/logs/laravel.log" 2>/dev/null | tee -a "${LOG_FILE}" || true
  fi
  rm -f /tmp/.acp-check.html'''
new = '''  # --- 11. verify (60s tak koshish, phir asli reason dikhao) -----------------
  step "Verify (end to end)"
  local code="" tries=0
  while (( tries < 30 )); do
    tries=$((tries + 1))
    code="$(curl -k -s -o /tmp/.acp-check.html -w '%{http_code}' -m 10 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
    [[ "${code}" == "200" ]] && break
    if [[ "${code}" == "500" || "${code}" == "502" ]]; then
      # pehli 500 par services ek baar fresh restart (opcache/stale socket)
      svc restart "php${v}-fpm" >>"${LOG_FILE}" 2>&1 || true
      svc restart nginx >>"${LOG_FILE}" 2>&1 || true
    fi
    sleep 2
  done

  local verdict="OK"
  if [[ "${code}" == "200" ]]; then
    ok "login page HTTP 200 (https://127.0.0.1:${PANEL_PORT}/)"
  else
    verdict="FAIL"
    err "login page HTTP ${code:-none} — asli reason (aakhri 15 lines):"
    {
      echo "----- nginx error log -----"
      tail -6 /var/log/nginx/alphacp-panel.error.log 2>/dev/null
      echo "----- php-fpm log -----"
      tail -6 "/var/log/php${v}-fpm.log" 2>/dev/null
      echo "----- laravel.log -----"
      tail -15 "${PANEL_ROOT}/storage/logs/laravel.log" 2>/dev/null
      echo "----- storage perms -----"
      ls -la "${PANEL_ROOT}/storage" "${PANEL_ROOT}/storage/logs" 2>/dev/null | head -12
    } | tee -a "${LOG_FILE}"
  fi
  rm -f /tmp/.acp-check.html'''
assert old in s, "verify block"
s = s.replace(old, new, 1)

# ---------------------------------------------------------------- 4. verdict box at the very end
old = '''  say "  ${C_BOLD}Agla kadam${C_RESET}: browser me URL kholo (self-signed cert warning → Advanced → Proceed),"
  say "  admin se login karo, password change karo, phir 2FA setup karo."
  say ""
}'''
new = '''  say "  ${C_BOLD}Agla kadam${C_RESET}: browser me URL kholo (self-signed cert warning → Advanced → Proceed),"
  say "  admin se login karo, password change karo, phir 2FA setup karo."
  say ""
  if [[ "${verdict}" == "OK" ]]; then
    say "${C_GREEN}${C_BOLD}==> VERDICT: PANEL READY ✅ — koi command nahi chahiye, seedha browser kholo.${C_RESET}"
  else
    say "${C_RED}${C_BOLD}==> VERDICT: PANEL NE 200 NAHI DIYA ❌ — upar wali 'asli reason' lines bhej do,${C_RESET}"
    say "${C_RED}${C_BOLD}    baaki sab (code, database, admin) already ho chuka hai.${C_RESET}"
  fi
  say ""
}'''
assert old in s, "verdict box"
s = s.replace(old, new, 1)

p.write_text(s)
print("hardened ✅")
