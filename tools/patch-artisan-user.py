#!/usr/bin/env python3
"""Root-cause fix for the 500: every artisan command must run AS the panel user.

Root-run artisan creates root-owned files in storage/ and bootstrap/cache/;
php-fpm (user `alphacp`) then cannot append to storage/logs/laravel.log and every
request dies with 500 ("could not be opened in append mode").

This patch adds an `artisan()` helper that runs via `runuser -u alphacp` and
rewrites all invocations to use it.
"""
import pathlib
import re

root = pathlib.Path(__file__).resolve().parent.parent
p = root / "installer" / "step2b-finish.sh"
s = p.read_text()

# 1) global for the chosen php binary + helper right after rand_pw
old = '''rand_pw() {'''
new = '''PHP_BIN=""

# Saari artisan commands PANEL USER ke roop me chalti hain — root se chalane par
# storage/logs/laravel.log root ka ban jata hai aur php-fpm (alphacp) usme likh nahi
# pata -> HAR request 500. (Yahi asli wajah thi jo browser me 500 dikh raha tha.)
artisan() {
  if id -u "${PANEL_USER}" >/dev/null 2>&1 && command -v runuser >/dev/null 2>&1; then
    ( cd "${PANEL_ROOT}" && runuser -u "${PANEL_USER}" -- env ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@" )
  else
    ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${PHP_BIN}" artisan "$@" )
  fi
}

rand_pw() {'''
assert old in s, "helper anchor"
s = s.replace(old, new, 1)

# 2) set PHP_BIN right after the php selection
old = '''  ok "panel PHP: ${php} ($("${php}" -r 'echo PHP_VERSION;'))"'''
new = '''  PHP_BIN="${php}"
  ok "panel PHP: ${php} ($("${php}" -r 'echo PHP_VERSION;'))"'''
assert old in s, "php bin set"
s = s.replace(old, new, 1)

# 3) rewrite every artisan invocation
patterns = [
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan key:generate --force \)',
     'artisan key:generate --force'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan config:clear\s*\)',
     'artisan config:clear'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan migrate --force \)',
     'artisan migrate --force'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan db:seed --force \)',
     'artisan db:seed --force'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan cache:clear\s*\)',
     'artisan cache:clear'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan view:clear\s*\)',
     'artisan view:clear'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan route:cache\s*\)',
     'artisan route:cache'),
    (r'\( cd "\$\{PANEL_ROOT\}" && ACP_HOME="\$\{ACP_HOME\}" "\$\{php\}" artisan config:cache \)',
     'artisan config:cache'),
]
for pat, rep in patterns:
    s, n = re.subn(pat, rep, s)
    print(f"{rep:35s} -> {n} replaced")

# admin-password (multi-line)
old = '''    ( cd "${PANEL_ROOT}" && ACP_HOME="${ACP_HOME}" "${php}" artisan alphacp:admin-password "${ADMIN_USER}" \\
        --password="${admin_password}" --force-change ) >>"${LOG_FILE}" 2>&1 \\'''
new = '''    artisan alphacp:admin-password "${ADMIN_USER}" \\
        --password="${admin_password}" --force-change >>"${LOG_FILE}" 2>&1 \\'''
if old in s:
    s = s.replace(old, new, 1)
    print("admin-password -> replaced")

# the failure-diagnostics artisan call (key:generate retry)
old = '''      ( cd "${PANEL_ROOT}" && "${php}" artisan key:generate --force 2>&1 | tail -10 ) | tee -a "${LOG_FILE}"'''
new = '''      artisan key:generate --force 2>&1 | tail -10 | tee -a "${LOG_FILE}"'''
if old in s:
    s = s.replace(old, new, 1)
    print("key:generate diag -> replaced")

# 4) final ownership: chown AND chmod (root-created cache files must be writable by alphacp)
old = '''  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true'''
new = '''  chown -R "${PANEL_USER}:${PANEL_USER}" "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" 2>/dev/null || true
  # root ne jo bhi cache/log files banayi, unhe alphacp ke liye likhne layak karo
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type d -exec chmod 0770 {} \\; 2>/dev/null || true
  find "${PANEL_ROOT}/storage" "${PANEL_ROOT}/bootstrap/cache" -type f -exec chmod 0660 {} \\; 2>/dev/null || true'''
assert old in s, "final chown"
s = s.replace(old, new, 1)

p.write_text(s)
print("artisan-as-panel-user patch applied ✅")
