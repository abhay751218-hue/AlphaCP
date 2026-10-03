#!/usr/bin/env bash
# =============================================================================
# AlphaCP backup archive primitive smoke test — no system files or services changed
# Version: 0.2.0
# Exercises the exact GNU tar flags used by backup.archive (create/list) AND by
# backup.recover (staged extract), plus the hostile archives the restore audit
# must survive: symlink-through-extraction, absolute members and `..` members.
# =============================================================================
set -Eeuo pipefail

VERSION="0.2.0"
TAR_BIN="${TAR_BIN:-/usr/bin/tar}"
[[ -x "${TAR_BIN}" ]] || { echo "FAIL: GNU tar missing at ${TAR_BIN}" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "FAIL: gzip missing" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "FAIL: sha256sum missing" >&2; exit 1; }

WHO="user"
if [[ ${EUID} -eq 0 ]]; then WHO="root"; fi

WORK="$(mktemp -d /tmp/alphacp-backup-tar.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT
mkdir -p "${WORK}/home/acct/public_html" "${WORK}/restore"
printf '%s\n' 'home archive smoke-test payload' > "${WORK}/home/acct/public_html/index.php"
printf '%s\n' 'database configuration placeholder' > "${WORK}/home/acct/wp-config.php"
ln -s /etc/passwd "${WORK}/home/acct/passwd-link"
ARCHIVE_ID="0123456789abcdef0123456789abcdef"
ARCHIVE="${WORK}/archives/${ARCHIVE_ID}.partial.tar.gz"
mkdir -p "${WORK}/archives"

printf 'AlphaCP backup tar smoke test v%s (running as %s, tar %s)\n' \
  "${VERSION}" "${WHO}" "$("${TAR_BIN}" --version | head -1 | awk '{print $NF}')"

# --- A. backup.archive: create + list + gzip integrity -----------------------
"${TAR_BIN}" --create --gzip --file "${ARCHIVE}" \
  --directory "${WORK}/home" --one-file-system --numeric-owner --acls --xattrs -- acct
"${TAR_BIN}" --list --gzip --file "${ARCHIVE}" > "${WORK}/members.txt"
gzip -t "${ARCHIVE}"
grep -Fq 'acct/public_html/index.php' "${WORK}/members.txt"
grep -Fq 'acct/wp-config.php' "${WORK}/members.txt"

# --- B. backup.recover: the exact staged-extraction flags --------------------
STAGING="${WORK}/staging"
mkdir -m 0700 -p "${STAGING}"
chmod 0640 "${ARCHIVE}"
mv "${ARCHIVE}" "${WORK}/archives/${ARCHIVE_ID}.tar.gz"
ARCHIVE="${WORK}/archives/${ARCHIVE_ID}.tar.gz"
"${TAR_BIN}" --extract --gzip --file "${ARCHIVE}" --directory "${STAGING}" --no-same-owner -- acct
[[ "$(cat "${STAGING}/acct/public_html/index.php")" == 'home archive smoke-test payload' ]]
[[ "$(cat "${STAGING}/acct/wp-config.php")" == 'database configuration placeholder' ]]
# the staged tree must contain EXACTLY the account directory (agent rule)
TOP="$(ls -A "${STAGING}")"
[[ "${TOP}" == 'acct' ]]
# symlinks are staged as links (never followed); the agent audit drops escaping ones
[[ -L "${STAGING}/acct/passwd-link" ]]
[[ "$(readlink "${STAGING}/acct/passwd-link")" == '/etc/passwd' ]]
# nothing outside staging was touched
[[ ! -e "${WORK}/restore/acct" ]]
SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"
[[ "${SHA256}" =~ ^[a-f0-9]{64}$ ]]

# --- C. hostile archive: symlink + a member written THROUGH that symlink -----
EVIL="${WORK}/evil"
mkdir -p "${EVIL}/src/acct" "${EVIL}/outside"
printf '%s\n' 'innocent' > "${EVIL}/src/acct/ok.txt"
ln -s "${EVIL}/outside" "${EVIL}/src/acct/loot"
printf '%s\n' 'attacker payload' > "${EVIL}/src/acct/loot/pwned.txt"
rm -f "${EVIL}/src/acct/loot/pwned.txt"   # keep the symlink, drop the real file
tar --create --file "${EVIL}/evil.tar" --directory "${EVIL}/src" acct/ok.txt acct/loot >/dev/null
printf '%s\n' 'attacker payload' > "${EVIL}/outside/pwned.txt"   # re-create for --append stat
tar --append --file "${EVIL}/evil.tar" --directory "${EVIL}/src" acct/loot/pwned.txt >/dev/null
rm -f "${EVIL}/outside/pwned.txt"
mkdir -m 0700 -p "${EVIL}/staging"
set +e
"${TAR_BIN}" --extract --file "${EVIL}/evil.tar" --directory "${EVIL}/staging" --no-same-owner -- acct \
  > "${EVIL}/extract.log" 2>&1
EVIL_EXIT=$?
set -e
[[ ${EVIL_EXIT} -ne 0 ]] || { echo "FAIL: tar wrote through a staged symlink (exit 0)" >&2; exit 1; }
[[ ! -e "${EVIL}/outside/pwned.txt" ]] || { echo "FAIL: hostile archive escaped the staging dir" >&2; exit 1; }
[[ -L "${EVIL}/staging/acct/loot" ]] || { echo "FAIL: symlink member was not staged as a link" >&2; exit 1; }

# --- D. `..` / extra top-level members are contained -------------------------
DD="${WORK}/dotdot"
mkdir -p "${DD}/src/acct" "${DD}/outside"
printf '%s\n' 'x' > "${DD}/src/acct/ok.txt"
printf '%s\n' 'secret' > "${DD}/outside/secret.txt"
tar --create --file "${DD}/dd.tar" --directory "${DD}/src" acct >/dev/null 2>&1
tar --append --file "${DD}/dd.tar" --directory "${DD}/src" ../outside/secret.txt >/dev/null 2>&1
mkdir -m 0700 -p "${DD}/staging"
"${TAR_BIN}" --extract --file "${DD}/dd.tar" --directory "${DD}/staging" --no-same-owner || true
[[ "$(cat "${DD}/outside/secret.txt")" == 'secret' ]]   # the real file was never touched
DD_TOP="$(ls -A "${DD}/staging" | tr '\n' ' ')"
# tar strips `../`, so an extra top-level entry appears -> the agent's
# "exactly one account directory" rule is what rejects this archive.
[[ "${DD_TOP}" == *'outside'* ]] || { echo "FAIL: expected an extra staged top-level entry, got '${DD_TOP}'" >&2; exit 1; }

printf 'PASS: create/list/gzip, staged restore extract (%s), symlink-through-extraction blocked, `..` contained, SHA-256 %s…\n' \
  "${WHO}" "${SHA256:0:16}"
