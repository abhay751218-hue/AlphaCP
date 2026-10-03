#!/usr/bin/env bash
# =============================================================================
# AlphaCP backup archive primitive smoke test — no system files or services changed
# Version: 0.1.0
# Exercises the exact GNU tar create/list/extract flags used by backup.archive.
# =============================================================================
set -Eeuo pipefail

VERSION="0.1.0"
TAR_BIN="${TAR_BIN:-/usr/bin/tar}"
[[ -x "${TAR_BIN}" ]] || { echo "FAIL: GNU tar missing at ${TAR_BIN}" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "FAIL: gzip missing" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "FAIL: sha256sum missing" >&2; exit 1; }

WORK="$(mktemp -d /tmp/alphacp-backup-tar.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT
mkdir -p "${WORK}/home/acct/public_html" "${WORK}/restore"
printf '%s\n' 'home archive smoke-test payload' > "${WORK}/home/acct/public_html/index.php"
printf '%s\n' 'database configuration placeholder' > "${WORK}/home/acct/wp-config.php"
ln -s /etc/passwd "${WORK}/home/acct/passwd-link"
ARCHIVE_ID="0123456789abcdef0123456789abcdef"
ARCHIVE="${WORK}/archives/${ARCHIVE_ID}.partial.tar.gz"
mkdir -p "${WORK}/archives"

printf 'AlphaCP backup tar smoke test v%s\n' "${VERSION}"
"${TAR_BIN}" --create --gzip --file "${ARCHIVE}" \
  --directory "${WORK}/home" --one-file-system --numeric-owner --acls --xattrs -- acct
"${TAR_BIN}" --list --gzip --file "${ARCHIVE}" > "${WORK}/members.txt"
gzip -t "${ARCHIVE}"
grep -Fq 'acct/public_html/index.php' "${WORK}/members.txt"
grep -Fq 'acct/wp-config.php' "${WORK}/members.txt"
"${TAR_BIN}" --extract --gzip --file "${ARCHIVE}" --directory "${WORK}/restore" \
  --no-same-owner --no-same-permissions
[[ "$(cat "${WORK}/restore/acct/public_html/index.php")" == 'home archive smoke-test payload' ]]
[[ -L "${WORK}/restore/acct/passwd-link" ]]
[[ "$(readlink "${WORK}/restore/acct/passwd-link")" == '/etc/passwd' ]]
chmod 0640 "${ARCHIVE}"
SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"
[[ "${SHA256}" =~ ^[a-f0-9]{64}$ ]]
printf 'PASS: tar create/list/gzip integrity/extract, symlink preserved (not followed), SHA-256 %s…\n' "${SHA256:0:16}"
