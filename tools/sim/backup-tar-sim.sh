#!/usr/bin/env bash
# =============================================================================
# AlphaCP backup archive primitive smoke test — no system files or services changed
# Version: 0.2.0
# Exercises the exact GNU tar create/list/extract flags used by backup.archive
# AND backup.extract (restore): literal + numeric-verbose listing, whole-home and
# subtree extraction, symlink preservation, and hostile-archive detection.
# =============================================================================
set -Eeuo pipefail

VERSION="0.2.0"
TAR_BIN="${TAR_BIN:-/usr/bin/tar}"
[[ -x "${TAR_BIN}" ]] || { echo "FAIL: GNU tar missing at ${TAR_BIN}" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "FAIL: gzip missing" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "FAIL: sha256sum missing" >&2; exit 1; }

WORK="$(mktemp -d /tmp/alphacp-backup-tar.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT
mkdir -p "${WORK}/home/acct/public_html" "${WORK}/restore" "${WORK}/subtree" "${WORK}/archives"
printf '%s\n' 'home archive smoke-test payload' > "${WORK}/home/acct/public_html/index.php"
printf '%s\n' 'database configuration placeholder' > "${WORK}/home/acct/wp-config.php"
ln -s /etc/passwd "${WORK}/home/acct/passwd-link"
ARCHIVE_ID="0123456789abcdef0123456789abcdef"
ARCHIVE="${WORK}/archives/${ARCHIVE_ID}.partial.tar.gz"

printf 'AlphaCP backup tar smoke test v%s\n' "${VERSION}"
"${TAR_BIN}" --create --gzip --file "${ARCHIVE}" \
  --directory "${WORK}/home" --one-file-system --numeric-owner --acls --xattrs -- acct

# ---- listing (exactly what the agent's archive inspection runs) -------------
"${TAR_BIN}" --list --gzip --file "${ARCHIVE}" --quoting-style=literal > "${WORK}/members.txt"
gzip -t "${ARCHIVE}"
grep -Fq 'acct/public_html/index.php' "${WORK}/members.txt"
grep -Fq 'acct/wp-config.php' "${WORK}/members.txt"

"${TAR_BIN}" --list --verbose --numeric-owner --gzip --file "${ARCHIVE}" \
  --quoting-style=literal > "${WORK}/members-verbose.txt"
# every entry must start with a type letter the agent accepts (- d l) and the size column must be numeric
awk '{ if ($1 !~ /^[-dl]/) { print "FAIL: unexpected tar entry type: " $0 > "/dev/stderr"; exit 1 } if ($3 !~ /^[0-9]+$/) { print "FAIL: unparsable size column: " $0 > "/dev/stderr"; exit 1 } }' "${WORK}/members-verbose.txt"
grep -Eq '^l[-rwx]{9} ' "${WORK}/members-verbose.txt" || { echo "FAIL: symlink entry not listed as type l" >&2; exit 1; }

# ---- full extract (agent flags) ---------------------------------------------
"${TAR_BIN}" --extract --gzip --file "${ARCHIVE}" --directory "${WORK}/restore" \
  --no-same-owner --one-file-system -- acct
[[ "$(cat "${WORK}/restore/acct/public_html/index.php")" == 'home archive smoke-test payload' ]]
[[ -L "${WORK}/restore/acct/passwd-link" ]]
[[ "$(readlink "${WORK}/restore/acct/passwd-link")" == '/etc/passwd' ]]

# ---- subtree extract (backup.extract path=...) ------------------------------
"${TAR_BIN}" --extract --gzip --file "${ARCHIVE}" --directory "${WORK}/subtree" \
  --no-same-owner --one-file-system -- acct/public_html
[[ -f "${WORK}/subtree/acct/public_html/index.php" ]]
[[ ! -e "${WORK}/subtree/acct/wp-config.php" ]] || { echo "FAIL: subtree extract leaked a sibling file" >&2; exit 1; }

# ---- hostile archive: path escape must be visible in the literal listing ----
mkdir -p "${WORK}/evil"
python3 - "${WORK}/evil/evil.tar.gz" <<'PY'
import io, sys, tarfile
with tarfile.open(sys.argv[1], 'w:gz') as tar:
    info = tarfile.TarInfo('acct/../../etc/pwned')
    data = b'bad'
    info.size = len(data)
    tar.addfile(info, io.BytesIO(data))
PY
"${TAR_BIN}" --list --gzip --file "${WORK}/evil/evil.tar.gz" --quoting-style=literal > "${WORK}/evil-members.txt" 2>/dev/null || true
grep -Fq '../' "${WORK}/evil-members.txt" || { echo "FAIL: path escape not visible to the inspector" >&2; exit 1; }

chmod 0640 "${ARCHIVE}"
SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"
[[ "${SHA256}" =~ ^[a-f0-9]{64}$ ]]
printf 'PASS: tar create/list(literal+numeric verbose)/gzip integrity/extract/subtree-extract, symlink preserved (not followed), hostile path escape detectable, SHA-256 %s…\n' "${SHA256:0:16}"
