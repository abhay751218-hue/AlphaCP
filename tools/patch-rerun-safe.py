#!/usr/bin/env python3
"""Make installer/step2b-finish.sh re-run safe: preserve APP_KEY + admin password.

stage_code wipes the panel dir (fresh extract) so panel/.env dies every run; the
secrets must live under ACP_HOME (outside the code tree) to survive.
"""
import pathlib

root = pathlib.Path(__file__).resolve().parent.parent
p = root / "installer" / "step2b-finish.sh"
s = p.read_text()

# 1) saved secrets block, right before the admin_password resolution
old = '  local admin_password="${ADMIN_PASSWORD}" admin_kept="false"'
new = '''  # --- saved secrets (re-run safe) ------------------------------------------
  # stage_code panel dir ko wipe karta hai (fresh extract), isliye APP_KEY aur admin
  # password ACP_HOME ke andar rakhte hain — code ke bahar, wipe se safe.
  local state_dir="${ACP_HOME}/var"
  mkdir -p "${state_dir}" 2>/dev/null || true
  local saved_key="" saved_pass=""
  if [[ -f "${state_dir}/panel-appkey.txt" ]]; then
    saved_key="$(head -1 "${state_dir}/panel-appkey.txt" 2>/dev/null || true)"
  fi
  if [[ -f "${state_dir}/panel-admin.txt" ]]; then
    saved_pass="$(grep -E '^panel_pass=' "${state_dir}/panel-admin.txt" 2>/dev/null | head -1 | cut -d= -f2- || true)"
  fi

  local admin_password="${ADMIN_PASSWORD}" admin_kept="false"'''
assert old in s, "1"
s = s.replace(old, new, 1)

# 2) password resolution falls back to the saved file
old = '''      admin_password="$(env_val ACP_ADMIN_PASSWORD "${PANEL_ROOT}/.env" 2>/dev/null || true)"
      admin_kept="true"'''
new = '''      admin_password="$(env_val ACP_ADMIN_PASSWORD "${PANEL_ROOT}/.env" 2>/dev/null || true)"
      [[ -n "${admin_password}" ]] || admin_password="${saved_pass}"
      admin_kept="true"'''
assert old in s, "2"
s = s.replace(old, new, 1)

# 3) APP_KEY: reuse the stored key instead of regenerating (2FA secrets!)
old = '''  local old_key=""
  [[ -f "${PANEL_ROOT}/.env" ]] && old_key="$(env_val APP_KEY "${PANEL_ROOT}/.env")"'''
new = '''  local old_key=""
  if [[ -f "${PANEL_ROOT}/.env" ]]; then old_key="$(env_val APP_KEY "${PANEL_ROOT}/.env" || true)"; fi
  [[ -n "${old_key}" ]] || old_key="${saved_key}"'''
assert old in s, "3"
s = s.replace(old, new, 1)

old = '''  if ! ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan key:generate --force ) >>"${LOG_FILE}" 2>&1; then
    err "APP_KEY generate fail — artisan ne yeh kaha:"
    ( cd "${PANEL_ROOT}" && "${php}" artisan key:generate --force 2>&1 | tail -10 ) | tee -a "${LOG_FILE}"
    die "APP_KEY generate fail"
  fi'''
new = '''  if [[ -n "${old_key}" ]]; then
    ok "APP_KEY preserved (sessions + 2FA secrets valid rahenge)"
  else
    if ! ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan key:generate --force ) >>"${LOG_FILE}" 2>&1; then
      err "APP_KEY generate fail — artisan ne yeh kaha:"
      ( cd "${PANEL_ROOT}" && "${php}" artisan key:generate --force 2>&1 | tail -10 ) | tee -a "${LOG_FILE}"
      die "APP_KEY generate fail"
    fi
    local new_key; new_key="$(env_val APP_KEY "${PANEL_ROOT}/.env" || true)"
    if [[ -n "${new_key}" ]]; then
      umask 077
      printf '%s\\n' "${new_key}" > "${state_dir}/panel-appkey.txt"
      chmod 0600 "${state_dir}/panel-appkey.txt"
    fi
    ok "APP_KEY generate ho gaya (safe copy: ${state_dir}/panel-appkey.txt)"
  fi'''
assert old in s, "3b"
s = s.replace(old, new, 1)

# 4) remember the admin password in the state file
old = '''  if [[ "${admin_kept}" == "true" ]]; then
    ok "admin '${ADMIN_USER}' pehle se hai — password unchanged"'''
new = '''  local known_pass="${admin_password:-${saved_pass}}"
  if [[ -n "${known_pass}" ]]; then
    umask 077
    printf 'panel_user=%s\\npanel_pass=%s\\npanel_port=%s\\nupdated_at=%s\\n' \\
      "${ADMIN_USER}" "${known_pass}" "${PANEL_PORT}" "$(date -Is)" > "${state_dir}/panel-admin.txt"
    chmod 0600 "${state_dir}/panel-admin.txt"
  fi

  if [[ "${admin_kept}" == "true" ]]; then
    ok "admin '${ADMIN_USER}' pehle se hai — password unchanged"'''
assert old in s, "4"
s = s.replace(old, new, 1)

# 5) credentials file + summary print the known password
old = '    if [[ "${admin_kept}" == "true" && -z "${admin_password}" ]]; then'
new = '    if [[ -z "${known_pass}" ]]; then'
assert old in s, "5"
s = s.replace(old, new, 1)

old = "      printf 'Password : (pehle se set hai — is run me change nahi hua)\\n'"
new = "      printf 'Password : (pata nahi — reset: ADMIN_PASSWORD=... bash %s)\\n' \"$(basename \"$0\")\""
assert old in s, "5b"
s = s.replace(old, new, 1)

old = '''    else
      printf 'Password : %s\\n' "${admin_password}"
    fi'''
new = '''    else
      printf 'Password : %s\\n' "${known_pass}"
    fi'''
assert old in s, "5c"
s = s.replace(old, new, 1)

old = '''  if [[ "${admin_kept}" == "true" && -z "${admin_password}" ]]; then
    say "  ${C_BOLD}Password${C_RESET}  : (unchanged — purana hi chalega)"
  else
    say "  ${C_BOLD}Password${C_RESET}  : ${admin_password}"
  fi'''
new = '''  if [[ -n "${known_pass}" ]]; then
    say "  ${C_BOLD}Password${C_RESET}  : ${known_pass}"
  else
    say "  ${C_BOLD}Password${C_RESET}  : (pata nahi — reset: ADMIN_PASSWORD='NayaPass' bash $0)"
  fi'''
assert old in s, "5d"
s = s.replace(old, new, 1)

p.write_text(s)
print("patch applied ✅")
