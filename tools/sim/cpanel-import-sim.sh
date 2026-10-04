#!/usr/bin/env bash
# =============================================================================
# AlphaCP cPanel import smoke test — real GNU tar + the real PHP inspector.
# Version: 0.69.0
#
# Proves, without touching system files or services:
#   * the exact listing members the importer asks tar for,
#   * a real `cpmove-<user>/homedir` extraction into a staging dir,
#   * the legacy `backup-*.tar` (uncompressed) and nested `homedir.tar` layouts,
#   * that CpanelArchive (the real PHP class, run under php-wasm) accepts a
#     genuine cpmove archive and rejects path escapes, hardlinks and archives
#     that write through a symlink.
# =============================================================================
set -Eeuo pipefail

VERSION="0.69.0"
TAR_BIN="${TAR_BIN:-/usr/bin/tar}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
PHPWASM="${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js"

[[ -x "${TAR_BIN}" ]] || { echo "FAIL: GNU tar missing at ${TAR_BIN}" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "FAIL: gzip missing" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "FAIL: python3 missing" >&2; exit 1; }
[[ -f "${PHPWASM}" ]] || { echo "FAIL: php-wasm missing (run tools/sim/provision-sim.sh once)" >&2; exit 1; }

WORK="$(mktemp -d /tmp/alphacp-cpanel-import.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT
mkdir -p "${WORK}/src/cpmove-acct/homedir/public_html" \
         "${WORK}/src/cpmove-acct/homedir/mail/example.com" \
         "${WORK}/src/cpmove-acct/homedir/logs" \
         "${WORK}/src/cpmove-acct/mysql" \
         "${WORK}/src/cpmove-acct/userdata" \
         "${WORK}/staging" "${WORK}/legacy" "${WORK}/nested" \
         "${WORK}/evil"
printf '%s\n' 'imported cpanel home page' > "${WORK}/src/cpmove-acct/homedir/public_html/index.php"
printf '%s\n' 'wp config' > "${WORK}/src/cpmove-acct/homedir/public_html/wp-config.php"
printf '%s\n' 'mailbox data' > "${WORK}/src/cpmove-acct/homedir/mail/example.com/inbox"
ln -s /etc/hostname "${WORK}/src/cpmove-acct/homedir/passwd-link"
mkdir -p "${WORK}/src/cpmove-acct/homedir/public_html/real"
ln -s real "${WORK}/src/cpmove-acct/homedir/inside-link"
printf '%s\n' 'CREATE TABLE wp_options (id INT);' > "${WORK}/src/cpmove-acct/mysql/acct_wp.sql"
printf '%s\n' 'main domain userdata' > "${WORK}/src/cpmove-acct/userdata/main"

printf 'AlphaCP cPanel import smoke test v%s\n' "${VERSION}"

# ---- 1. real cpmove-<user>.tar.gz (the format Transfer Tool produces) -------
"${TAR_BIN}" --create --gzip --file "${WORK}/cpmove-acct.tar.gz" \
  --directory "${WORK}/src" --one-file-system --numeric-owner -- cpmove-acct

"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/cpmove-acct.tar.gz" > "${WORK}/plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${WORK}/cpmove-acct.tar.gz" > "${WORK}/verbose.txt"
[[ "$(wc -l < "${WORK}/plain.txt")" == "$(wc -l < "${WORK}/verbose.txt")" ]] \
  || { echo "FAIL: plain/verbose listings disagree (the importer refuses such archives)" >&2; exit 1; }

# ---- 2. the member listing + member verbose the importer actually uses ------
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/cpmove-acct.tar.gz" -- cpmove-acct/homedir > "${WORK}/home-plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${WORK}/cpmove-acct.tar.gz" -- cpmove-acct/homedir > "${WORK}/home-verbose.txt"
grep -Fq 'cpmove-acct/homedir/public_html/index.php' "${WORK}/home-plain.txt" || { echo "FAIL: home listing misses a file" >&2; exit 1; }
if grep -Fq 'mysql/acct_wp.sql' "${WORK}/home-plain.txt"; then echo "FAIL: home listing leaked the mysql section" >&2; exit 1; fi
grep -Fq 'lrwx' "${WORK}/home-verbose.txt" || { echo "FAIL: symlink not listed as type l" >&2; exit 1; }

# ---- 3. extraction exactly like importCpanelHome (no --gzip: auto-detect) ----
"${TAR_BIN}" --extract --file "${WORK}/cpmove-acct.tar.gz" --directory "${WORK}/staging" \
  --no-same-owner --one-file-system -- cpmove-acct/homedir
[[ "$(cat "${WORK}/staging/cpmove-acct/homedir/public_html/index.php")" == 'imported cpanel home page' ]]
[[ -L "${WORK}/staging/cpmove-acct/homedir/passwd-link" ]] || { echo "FAIL: symlink was not preserved" >&2; exit 1; }
if [[ -e "${WORK}/staging/cpmove-acct/mysql/acct_wp.sql" ]]; then echo "FAIL: extraction leaked a non-home section" >&2; exit 1; fi

# ---- 4. legacy plain .tar with homedir/ at the archive root -----------------
mkdir -p "${WORK}/legacy/homedir/public_html"
printf '%s\n' 'legacy backup home page' > "${WORK}/legacy/homedir/public_html/index.php"
printf '%s\n' 'legacy db dump' > "${WORK}/legacy/db.sql"
"${TAR_BIN}" --create --file "${WORK}/backup-10.03.2026_16-00-00_acct.tar" \
  --directory "${WORK}/legacy" -- homedir db.sql
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/backup-10.03.2026_16-00-00_acct.tar" > "${WORK}/legacy-plain.txt"
grep -q '^homedir/public_html/index.php$' "${WORK}/legacy-plain.txt" || { echo "FAIL: legacy layout not listed at the root" >&2; exit 1; }
mkdir -p "${WORK}/legacy-out"
"${TAR_BIN}" --extract --file "${WORK}/backup-10.03.2026_16-00-00_acct.tar" --directory "${WORK}/legacy-out" \
  --no-same-owner --one-file-system -- homedir
[[ "$(cat "${WORK}/legacy-out/homedir/public_html/index.php")" == 'legacy backup home page' ]]

# ---- 5. nested homedir/homedir.tar layout (older pkgacct output) ------------
mkdir -p "${WORK}/nested-home/public_html" "${WORK}/nested/cpmove-acct/homedir"
printf '%s\n' 'nested home page' > "${WORK}/nested-home/public_html/index.php"
"${TAR_BIN}" --create --file "${WORK}/nested/cpmove-acct/homedir/homedir.tar" \
  --directory "${WORK}/nested-home" -- .
"${TAR_BIN}" --create --gzip --file "${WORK}/nested-cpmove-acct.tar.gz" \
  --directory "${WORK}/nested" -- cpmove-acct
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/nested-cpmove-acct.tar.gz" > "${WORK}/nested-plain.txt"
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/nested-cpmove-acct.tar.gz" -- cpmove-acct/homedir/homedir.tar > "${WORK}/nested-member.txt"
grep -Fq 'cpmove-acct/homedir/homedir.tar' "${WORK}/nested-member.txt" || { echo "FAIL: nested member not selectable" >&2; exit 1; }
mkdir -p "${WORK}/nested-stage"
"${TAR_BIN}" --extract --file "${WORK}/nested-cpmove-acct.tar.gz" --directory "${WORK}/nested-stage" \
  --no-same-owner --one-file-system -- cpmove-acct/homedir/homedir.tar
INNER="${WORK}/nested-stage/cpmove-acct/homedir/homedir.tar"
"${TAR_BIN}" --list --quoting-style=literal --file "${INNER}" > "${WORK}/inner-plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${INNER}" > "${WORK}/inner-verbose.txt"
grep -q '^[.]/public_html/index.php$' "${WORK}/inner-plain.txt" || { echo "FAIL: nested home listing unexpected" >&2; exit 1; }
mkdir -p "${WORK}/nested-home-out"
"${TAR_BIN}" --extract --file "${INNER}" --directory "${WORK}/nested-home-out" --no-same-owner --one-file-system
[[ "$(cat "${WORK}/nested-home-out/public_html/index.php")" == 'nested home page' ]]

# ---- 6. hostile archives: write-through-symlink (absolute + relative) -------
python3 - "${WORK}" <<'PYINNER'
import io, sys, tarfile

work = sys.argv[1]


def build(path: str, linkname: str, children: list[str]) -> None:
    with tarfile.open(path, 'w:gz') as tar:
        for name in ('cpmove-acct/', 'cpmove-acct/homedir/'):
            info = tarfile.TarInfo(name)
            info.type = tarfile.DIRTYPE
            info.mode = 0o755
            tar.addfile(info)
        link = tarfile.TarInfo('cpmove-acct/homedir/link')
        link.type = tarfile.SYMTYPE
        link.linkname = linkname
        tar.addfile(link)
        for child in children:
            info = tarfile.TarInfo(f'cpmove-acct/homedir/link/{child}')
            data = b'escaped'
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))


build(f'{work}/evil-absolute.tar.gz', '/etc', ['pwned'])
build(f'{work}/evil-relative.tar.gz', 'real', ['pwned'])
PYINNER
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/evil-absolute.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-abs-plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${WORK}/evil-absolute.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-abs-verbose.txt"
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/evil-relative.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-rel-plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${WORK}/evil-relative.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-rel-verbose.txt"
grep -Fq 'cpmove-acct/homedir/link/pwned' "${WORK}/evil-rel-plain.txt" || { echo "FAIL: hostile member missing from listing" >&2; exit 1; }

# ---- 7. hostile archive with a path escape + a hardlink --------------------
python3 - "${WORK}/evil" <<'PY'
import io, sys, tarfile
base = sys.argv[1]
with tarfile.open(f'{base}/evil-escape.tar.gz', 'w:gz') as tar:
    info = tarfile.TarInfo('cpmove-acct/homedir/../../etc/pwned')
    info.size = 3
    tar.addfile(info, io.BytesIO(b'bad'))
with tarfile.open(f'{base}/evil-hardlink.tar.gz', 'w:gz') as tar:
    link = tarfile.TarInfo('cpmove-acct/homedir/shadow')
    link.type = tarfile.LNKTYPE
    link.linkname = '/etc/shadow'
    tar.addfile(link)
PY
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/evil/evil-escape.tar.gz" > "${WORK}/evil-escape.txt"
"${TAR_BIN}" --list --quoting-style=literal --file "${WORK}/evil/evil-hardlink.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-hardlink-plain.txt"
"${TAR_BIN}" --list --verbose --numeric-owner --quoting-style=literal --file "${WORK}/evil/evil-hardlink.tar.gz" -- cpmove-acct/homedir > "${WORK}/evil-hardlink-verbose.txt"

# ---- 8. run the REAL CpanelArchive class against those real listings --------
cat > "${WORK}/probe.php" <<'PHP'
<?php
declare(strict_types=1);
require getenv('ACP_AGENT_ROOT') . '/src/Bootstrap.php';

use Alphacp\Agent\CpanelArchive;
use Alphacp\Agent\TaskRejectedException;

$work = $argv[1];
$read = static fn (string $file): string => (string) file_get_contents($file);

function fails(string $label, callable $fn, string $needle): void
{
    try {
        $fn();
    } catch (TaskRejectedException $e) {
        if (str_contains($e->getMessage(), $needle)) {
            echo "ok   {$label}: refused ({$e->getMessage()})\n";
            return;
        }
        echo "FAIL {$label}: wrong refusal ({$e->getMessage()})\n";
        exit(1);
    }
    echo "FAIL {$label}: was accepted\n";
    exit(1);
}

function ok(string $label, bool $cond): void
{
    if (! $cond) {
        echo "FAIL {$label}\n";
        exit(1);
    }
    echo "ok   {$label}\n";
}

$plan = CpanelArchive::structure($read("{$work}/plain.txt"), 'acct');
ok('cpmove layout detected', $plan['layout'] === 'direct' && $plan['root'] === 'cpmove-acct' && $plan['member'] === 'cpmove-acct/homedir');
ok('skipped sections reported', in_array('mysql', $plan['sections'], true) && in_array('userdata', $plan['sections'], true));
ok('home section not reported as skipped', ! in_array('homedir', $plan['sections'], true));

CpanelArchive::assertNoSymlinkTraversal($read("{$work}/home-plain.txt"), $read("{$work}/home-verbose.txt"));
ok('a symlink with a safe target passes the traversal check', true);
$counts = CpanelArchive::homeCounts($read("{$work}/home-verbose.txt"));
ok('home counts parsed from a real listing', $counts['files'] === 5 && $counts['dirs'] === 6 && $counts['bytes'] > 40);

$legacy = CpanelArchive::structure($read("{$work}/legacy-plain.txt"), 'acct');
ok('legacy layout detected', $legacy['layout'] === 'direct' && $legacy['root'] === '' && $legacy['member'] === 'homedir');

$nested = CpanelArchive::structure($read("{$work}/nested-plain.txt"), 'acct');
ok('nested homedir.tar detected', $nested['layout'] === 'nested' && $nested['nested_member'] === 'cpmove-acct/homedir/homedir.tar');
$inner = CpanelArchive::homeCounts($read("{$work}/inner-verbose.txt"));
ok('nested home counts parsed', $inner['files'] === 1 && $inner['dirs'] === 2);

fails('absolute symlink target', static function () use ($read, $work): void {
    CpanelArchive::assertNoSymlinkTraversal($read("{$work}/evil-abs-plain.txt"), $read("{$work}/evil-abs-verbose.txt"));
}, 'symlink');

fails('path through a relative symlink', static function () use ($read, $work): void {
    CpanelArchive::assertNoSymlinkTraversal($read("{$work}/evil-rel-plain.txt"), $read("{$work}/evil-rel-verbose.txt"));
}, 'symlink');

fails('path escape', static function () use ($read, $work): void {
    CpanelArchive::structure($read("{$work}/evil-escape.txt"), 'acct');
}, 'escape');

fails('hardlink entry', static function () use ($read, $work): void {
    CpanelArchive::homeCounts($read("{$work}/evil-hardlink-verbose.txt"));
}, 'hardlink');

ok('all real-tar checks done', true);
PHP

ACP_AGENT_ROOT="${REPO}/agent" node "${PHPWASM}" -d memory_limit=512M "${WORK}/probe.php" "${WORK}" > "${WORK}/probe.out" 2>&1 || {
  cat "${WORK}/probe.out" >&2
  echo "=== CPANEL-IMPORT-SIM: FAIL (php probe) ===" >&2
  exit 1
}
cat "${WORK}/probe.out"
if grep -q '^FAIL' "${WORK}/probe.out"; then
  echo "=== CPANEL-IMPORT-SIM: FAIL ===" >&2
  exit 1
fi
grep -q '^ok   all real-tar checks done$' "${WORK}/probe.out" || {
  echo "=== CPANEL-IMPORT-SIM: FAIL (php probe did not finish) ===" >&2
  exit 1
}

echo
echo "PASS: real cpmove/legacy/nested tar listing + member extract + php CpanelArchive accept/reject (v${VERSION})"
echo "=== CPANEL-IMPORT-SIM: PASS ==="
