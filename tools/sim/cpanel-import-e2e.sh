#!/usr/bin/env bash
# =============================================================================
# AlphaCP cPanel import END-TO-END smoke test — real tar + real BackupArchiveStore.
# Version: 0.69.0
#
# Unlike cpanel-import-sim.sh (which only proves the tar members + listing
# rules), this one runs the REAL importer class with the REAL CommandRunner
# against a throwaway accounts root:
#
#   * a genuine `cpmove-<user>.tar.gz` import swaps the staged home in and keeps
#     the replaced home as `.acp-prerestore-<user>-<stamp>`,
#   * old files survive in the pre-restore copy, legitimate symlinks survive,
#     the staging dir is cleaned up,
#   * hostile archives (path escape, write-through symlink, hardlink, another
#     account's cpmove, wrong sha256, missing file, not-a-tar) are refused and
#     the live home is NOT touched by any of them.
#
# Runs as a normal user (chown is a no-op for a user that cannot chown);
# for the full ownership path use `sudo bash tools/sim/cpanel-import-e2e.sh`.
# =============================================================================
set -Eeuo pipefail

VERSION="0.69.0"
TAR_BIN="${TAR_BIN:-/usr/bin/tar}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
PHPWASM="${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js"

[[ -x "${TAR_BIN}" ]] || { echo "FAIL: GNU tar missing at ${TAR_BIN}" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "FAIL: python3 missing" >&2; exit 1; }
[[ -f "${PHPWASM}" ]] || { echo "FAIL: php-wasm missing (run tools/sim/provision-sim.sh once)" >&2; exit 1; }

WORK="$(mktemp -d /tmp/alphacp-import-e2e.XXXXXX)"
trap 'rm -rf "${WORK}"' EXIT

ACCOUNTS="${WORK}/accounts"
STATE="${WORK}/state"
SRC="${WORK}/src"
ARCH="${WORK}/archives"
EVIL="${WORK}/evil"
mkdir -p "${ACCOUNTS}/alicehost/public_html" "${STATE}" \
         "${SRC}/cpmove-alicehost/homedir/public_html" \
         "${SRC}/cpmove-alicehost/mysql" "${SRC}/cpmove-alicehost/userdata" \
         "${ARCH}" "${EVIL}"

printf '%s\n' 'old site' > "${ACCOUNTS}/alicehost/public_html/old.txt"
printf '%s\n' 'imported home page' > "${SRC}/cpmove-alicehost/homedir/public_html/index.php"
printf '%s\n' 'imported docroot file' > "${SRC}/cpmove-alicehost/homedir/public_html/about.html"
printf '%s\n' 'keep me' > "${SRC}/cpmove-alicehost/homedir/.htaccess"
ln -s public_html "${SRC}/cpmove-alicehost/homedir/www"
printf '%s\n' 'CREATE TABLE wp_options (id INT);' > "${SRC}/cpmove-alicehost/mysql/alicehost_wp.sql"
printf '%s\n' 'main domain userdata' > "${SRC}/cpmove-alicehost/userdata/main"

printf 'AlphaCP cPanel import E2E test v%s\n' "${VERSION}"

"${TAR_BIN}" --create --gzip --file "${ARCH}/cpmove-alicehost.tar.gz" \
  --directory "${SRC}" --one-file-system --numeric-owner -- cpmove-alicehost
GOOD_SHA="$(sha256sum "${ARCH}/cpmove-alicehost.tar.gz" | cut -d' ' -f1)"

# ---- hostile + wrong archives (python3, so tar keeps the bad on-disk shape) --
python3 - "${WORK}" <<'PYINNER'
import io, os, sys, tarfile

work = sys.argv[1]
evil = f'{work}/evil'
src = f'{work}/src'


def open_gz(path):
    return tarfile.open(path, 'w:gz')


with open_gz(f'{evil}/escape.tar.gz') as tar:
    info = tarfile.TarInfo('cpmove-alicehost/homedir/../../etc/pwned')
    info.size = 3
    tar.addfile(info, io.BytesIO(b'bad'))

with open_gz(f'{evil}/traverse.tar.gz') as tar:
    for name in ('cpmove-alicehost/', 'cpmove-alicehost/homedir/'):
        info = tarfile.TarInfo(name)
        info.type = tarfile.DIRTYPE
        info.mode = 0o755
        tar.addfile(info)
    link = tarfile.TarInfo('cpmove-alicehost/homedir/link')
    link.type = tarfile.SYMTYPE
    link.linkname = '/etc'
    tar.addfile(link)
    info = tarfile.TarInfo('cpmove-alicehost/homedir/link/pwned')
    info.size = 7
    tar.addfile(info, io.BytesIO(b'escaped'))

with open_gz(f'{evil}/hardlink.tar.gz') as tar:
    link = tarfile.TarInfo('cpmove-alicehost/homedir/shadow')
    link.type = tarfile.LNKTYPE
    link.linkname = '/etc/shadow'
    tar.addfile(link)

# another account's cpmove inside a correctly named file
with open_gz(f'{evil}/other-account.tar.gz') as tar:
    info = tarfile.TarInfo('cpmove-intruder/homedir/public_html/index.php')
    info.size = 5
    tar.addfile(info, io.BytesIO(b'other'))

with open(f'{evil}/not-a-tar.tar.gz', 'w') as handle:
    handle.write('this is not a tarball at all\n')
PYINNER

# ---- the real importer, with the real tar runner ----------------------------
cat > "${WORK}/e2e-probe.php" <<'PHP'
<?php
declare(strict_types=1);
require getenv('ACP_AGENT_ROOT') . '/src/Bootstrap.php';

use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\BackupArchiveStore;
use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskLogger;

$w = $argv[1];
$sha = $argv[2];
$accounts = "{$w}/accounts";
$archiveGood = "{$w}/archives/cpmove-alicehost.tar.gz";
$home = "{$accounts}/alicehost";

$guard = new PathGuard([$accounts, "{$w}/archives", "{$w}/evil", "{$w}/state"]);
$paths = AccountPaths::fromEnv();
if ($paths->accountsRoot !== $accounts) {
    fwrite(STDERR, "FAIL: ACP_ACCOUNTS_ROOT not honoured\n");
    exit(1);
}
$store = new BackupArchiveStore(
    new CommandRunner(120),
    new SafeFs($guard),
    $paths,
    new TaskLogger(new PDO('sqlite::memory:'), null),
    "{$w}/state",
);

$failures = 0;
$ok = static function (string $label, bool $cond) use (&$failures): void {
    if ($cond) {
        echo "ok   {$label}\n";
        return;
    }
    echo "FAIL {$label}\n";
    $failures++;
};
$fails = static function (string $label, callable $fn, string $needle) use (&$failures): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            echo "ok   {$label}: refused ({$e->getMessage()})\n";
            return;
        }
        echo "FAIL {$label}: wrong refusal ({$e->getMessage()})\n";
        $failures++;
        return;
    }
    echo "FAIL {$label}: was accepted\n";
    $failures++;
};

$live = static fn (): string => (string) @file_get_contents("{$home}/public_html/index.php");

// ---- 1. genuine import ------------------------------------------------------
$old = $live();
$result = $store->importCpanelHome('alicehost', $archiveGood, $sha, 'restore');
$ok('import reports success', ($result['status'] ?? '') === 'imported');
$ok('layout detected', ($result['layout'] ?? '') === 'direct' && ($result['root'] ?? '') === 'cpmove-alicehost');
$ok('sha256 echoed', ($result['sha256'] ?? '') === $sha);
$ok('home file/dir counts', ($result['files'] ?? 0) >= 4 && ($result['dirs'] ?? 0) >= 2 && ($result['bytes'] ?? 0) > 10);
$ok('skipped sections reported', in_array('mysql', $result['sections'], true) && in_array('userdata', $result['sections'], true));
$ok('pre-restore copy named', is_string($result['prerestore']) && $result['prerestore'] !== '');
$ok('imported file is live', $live() === "imported home page\n" && $old !== $live());
$ok('hidden file imported', @file_get_contents("{$home}/.htaccess") === "keep me\n");
$ok('old file left the live home', ! file_exists("{$home}/public_html/old.txt"));
$ok('old file kept in pre-restore copy', @file_get_contents("{$accounts}/{$result['prerestore']}/public_html/old.txt") === "old site\n");
$ok('legitimate symlink preserved', is_link("{$home}/www") && readlink("{$home}/www") === 'public_html');
$staging = glob("{$accounts}/.acp-import-*") ?: [];
$ok('staging dir cleaned up', $staging === []);

// ---- 2. hostile archives never touch the live home -------------------------
$before = $live();
$prerestoreBefore = glob("{$accounts}/.acp-prerestore-alicehost-*") ?: [];

$fails('path escape', static fn () => $store->importCpanelHome('alicehost', "{$w}/evil/escape.tar.gz", '', 'restore'), 'escape');
$fails('write-through symlink', static fn () => $store->importCpanelHome('alicehost', "{$w}/evil/traverse.tar.gz", '', 'restore'), 'symlink');
$fails('hardlink entry', static fn () => $store->importCpanelHome('alicehost', "{$w}/evil/hardlink.tar.gz", '', 'restore'), 'hardlink');
$fails('another account', static fn () => $store->importCpanelHome('alicehost', "{$w}/evil/other-account.tar.gz", '', 'restore'), 'another account');
$fails('checksum mismatch', static fn () => $store->importCpanelHome('alicehost', $archiveGood, str_repeat('0', 64), 'restore'), 'checksum');
$fails('missing archive', static fn () => $store->importCpanelHome('alicehost', "{$w}/archives/nope.tar.gz", '', 'restore'), 'not found');
$fails('not a tar', static fn () => $store->importCpanelHome('alicehost', "{$w}/evil/not-a-tar.tar.gz", '', 'restore'), 'unreadable');
$fails('unknown account', static fn () => $store->importCpanelHome('nobodyhere', $archiveGood, '', 'restore'), 'missing');

$ok('live home untouched by hostile archives', $live() === $before && $live() === "imported home page\n");
$ok('no extra pre-restore copy was made', count(glob("{$accounts}/.acp-prerestore-alicehost-*") ?: []) === count($prerestoreBefore));

if ($failures > 0) {
    echo "FAILURES: {$failures}\n";
    exit(1);
}
echo "ok   all end-to-end import checks done\n";
PHP

ACP_ACCOUNTS_ROOT="${ACCOUNTS}" ACP_AGENT_ROOT="${REPO}/agent" ACP_STATE_ROOT="${STATE}" \
  node "${PHPWASM}" -d memory_limit=512M "${WORK}/e2e-probe.php" "${WORK}" "${GOOD_SHA}" > "${WORK}/e2e.out" 2>&1 || {
  cat "${WORK}/e2e.out" >&2
  echo "=== CPANEL-IMPORT-E2E: FAIL (php probe) ====" >&2
  exit 1
}
cat "${WORK}/e2e.out"
if grep -q '^FAIL' "${WORK}/e2e.out"; then
  echo "=== CPANEL-IMPORT-E2E: FAIL ====" >&2
  exit 1
fi
grep -q '^ok   all end-to-end import checks done$' "${WORK}/e2e.out" || {
  echo "=== CPANEL-IMPORT-E2E: FAIL (probe did not finish) ====" >&2
  exit 1
}

echo
echo "PASS: real cpmove import into a throwaway accounts root — home swapped, pre-restore kept, hostile archives refused (v${VERSION})"
echo "=== CPANEL-IMPORT-E2E: PASS ===="
