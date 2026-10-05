<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Creates immutable, checksum-verified home archives outside the account tree.
 * The archive directory is root-owned and group-readable by the panel so an
 * authenticated, account-scoped controller can stream a completed download.
 */
final class BackupArchiveStore
{
    private const FORMAT_VERSION = 1;
    private const MIN_FREE_BYTES = 67_108_864; // 64 MiB safety reserve
    private const TAR_TIMEOUT = 3600;
    private const PRERESTORE_KEEP = 1;
    private const MAX_ENTRIES = 500_000;
    private const TAR_BIN = '/usr/bin/tar';

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly SafeFs $fs,
        private readonly AccountPaths $paths,
        private readonly TaskLogger $log,
        private readonly string $stateRoot,
    ) {
    }

    /** @return array{archive_id:string,username:string,scope:string,filename:string,size_bytes:int,sha256:string,created_at:string,status:string} */
    public function createHome(string $username, string $archiveId): array
    {
        $this->assertUsername($username);
        $archiveId = self::normalizeId($archiveId);
        $accountsRoot = $this->paths->accountsRoot;
        $home = $this->paths->home($username);
        $this->assertDirectory($accountsRoot, 'account root');
        $this->assertDirectory($home, 'account home');

        $stateRoot = rtrim($this->stateRoot, '/');
        if ($stateRoot === '' || $stateRoot === '/' || is_link($stateRoot)) {
            throw new TaskRejectedException('invalid backup state root');
        }
        $this->fs->assert($stateRoot);
        $this->assertDirectory($stateRoot, 'state root');

        $root = $stateRoot . '/backups';
        $accounts = $root . '/accounts';
        $accountDir = $accounts . '/' . $username;
        $this->ensureDirectory($root, 0750);
        $this->ensureDirectory($accounts, 0750);
        $this->ensureDirectory($accountDir, 0750);

        $archive = $accountDir . '/' . $archiveId . '.tar.gz';
        $manifest = $accountDir . '/' . $archiveId . '.json';
        $existing = $this->loadExisting($archive, $manifest, $username, $archiveId);
        if ($existing !== null) {
            return $existing;
        }

        $pruned = 0;
        try {
            $pruned = $this->pruneExpired($accountDir, $username, $archiveId);
        } catch (Throwable $e) {
            $this->log->warning('pre-backup retention cleanup failed: ' . $e->getMessage());
        }
        $estimatedBytes = $this->estimateHomeBytes($home);
        $this->assertFreeSpace($accountDir, $estimatedBytes);

        $temporary = $accountDir . '/.' . $archiveId . '.partial.tar.gz';
        if (is_link($temporary)) {
            throw new TaskRejectedException('partial backup path is a symlink');
        }
        if (file_exists($temporary) && !@unlink($temporary)) {
            throw new TaskRejectedException('cannot remove stale partial backup');
        }

        $published = false;
        try {
            $result = $this->cmd->run([
                self::TAR_BIN,
                '--create',
                '--gzip',
                '--file', $temporary,
                '--directory', $accountsRoot,
                '--one-file-system',
                '--numeric-owner',
                '--acls',
                '--xattrs',
                '--', $username,
            ], self::TAR_TIMEOUT);
            if (!$result->ok()) {
                throw new TaskRejectedException('home backup archive creation failed (tar exit ' . $result->exitCode . ')');
            }
            if (is_link($temporary) || !is_file($temporary) || filesize($temporary) < 20) {
                throw new TaskRejectedException('home backup produced no valid archive');
            }

            $verified = $this->cmd->run([
                self::TAR_BIN,
                '--list',
                '--gzip',
                '--file', $temporary,
            ], 120);
            if (!$verified->ok()) {
                throw new TaskRejectedException('home backup archive integrity check failed');
            }

            $sha256 = hash_file('sha256', $temporary);
            $size = filesize($temporary);
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !is_int($size) || $size < 20) {
                throw new TaskRejectedException('home backup checksum could not be computed');
            }
            $createdAt = gmdate(DATE_ATOM);
            $filename = $archiveId . '.tar.gz';
            $record = [
                'format_version' => self::FORMAT_VERSION,
                'archive_id' => $archiveId,
                'username' => $username,
                'scope' => 'home',
                'filename' => $filename,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'created_at' => $createdAt,
            ];
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new TaskRejectedException('home backup manifest encoding failed');
            }

            $this->fs->chmod($temporary, 0640);
            $this->fs->chownName($temporary, 'root', $this->panelGroup());
            if (is_link($archive) || file_exists($archive) || is_link($manifest) || file_exists($manifest)) {
                throw new TaskRejectedException('backup archive id already exists');
            }
            $this->fs->rename($temporary, $archive);
            $published = true;
            $this->fs->write($manifest, $json . "\n", 0640);
            $this->fs->chownName($manifest, 'root', $this->panelGroup());
            $this->fs->chmod($accountDir, 0750);
            $this->fs->chownName($accountDir, 'root', $this->panelGroup());

            $this->log->info("home archive {$archiveId} created for {$username}; pruned {$pruned}");

            return [
                'archive_id' => $archiveId,
                'username' => $username,
                'scope' => 'home',
                'filename' => $filename,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'created_at' => $createdAt,
                'status' => 'ready',
            ];
        } catch (Throwable $e) {
            if (is_file($temporary) || is_link($temporary)) {
                $this->fs->unlink($temporary);
            }
            if ($published && (is_file($archive) || is_link($archive))) {
                $this->fs->unlink($archive);
            }
            if (is_file($manifest) || is_link($manifest)) {
                $this->fs->unlink($manifest);
            }
            throw $e;
        }
    }

    /**
     * Restore a previously published, checksum-verified home archive back into
     * the account home — safely:
     *
     *   1. the manifest is re-verified (sha256 + size) before anything moves,
     *   2. every tar entry is inspected: it must live under `<username>/`, may not
     *      contain `..` or absolute paths, and hardlinks / device nodes / FIFOs /
     *      sockets are refused (a hardlink to /etc/shadow would otherwise land in
     *      a customer home as root),
     *   3. the archive is extracted into a fresh staging dir OUTSIDE the account,
     *      with `--no-same-owner`, then chowned to the account (symlinks skipped,
     *      never followed),
     *   4. the swap is done with same-filesystem renames: the current tree is
     *      moved to `.acp-prerestore-<user>-<stamp>` first, then the staged tree
     *      moves into place; any failure rolls the old tree back.
     *
     * `$subPath` ('' = whole home) restores a single subtree, like cPanel's
     * File and Directory Restoration.
     *
     * @return array{archive_id:string,username:string,scope:string,path:string,files:int,dirs:int,bytes:int,replaced:bool,prerestore:?string,status:string}
     */
    public function restoreHome(string $username, string $archiveId, string $subPath = ''): array
    {
        $this->assertUsername($username);
        $archiveId = self::normalizeId($archiveId);
        $subPath = self::normalizeSubPath($subPath);

        $accountsRoot = $this->paths->accountsRoot;
        $home = $this->paths->home($username);
        $stateRoot = rtrim($this->stateRoot, '/');
        if ($stateRoot === '' || $stateRoot === '/' || is_link($stateRoot)) {
            throw new TaskRejectedException('invalid backup state root');
        }
        $this->assertDirectory($accountsRoot, 'account root');
        $this->assertDirectory($home, 'account home');
        $this->assertDirectory($stateRoot, 'state root');

        $accountDir = $stateRoot . '/backups/accounts/' . $username;
        $this->assertDirectory($accountDir, 'backup account storage');
        $archive = $accountDir . '/' . $archiveId . '.tar.gz';
        $manifest = $accountDir . '/' . $archiveId . '.json';

        // Verifies manifest fields, size and sha256 before any filesystem change.
        $record = $this->loadExisting($archive, $manifest, $username, $archiveId);
        if ($record === null) {
            throw new TaskRejectedException('backup archive not found');
        }

        $lock = $this->acquireLock($username);
        $staging = $accountsRoot . '/.acp-restore-' . $archiveId . '-' . gmdate('YmdHis');
        $pre = $accountsRoot . '/.acp-prerestore-' . $username . '-' . gmdate('YmdHis');
        $movedOld = false;
        $movedNew = false;
        $replaced = false;

        try {
            $inspected = $this->inspectArchive($archive, $username, $subPath);
            $this->assertFreeSpace($accountsRoot, $inspected['bytes']);

            $this->ensureDirectory($staging, 0700);
            $result = $this->cmd->run([
                self::TAR_BIN,
                '--extract',
                '--gzip',
                '--file', $archive,
                '--directory', $staging,
                '--no-same-owner',
                '--one-file-system',
                '--',
                $subPath === '' ? $username : $username . '/' . $subPath,
            ], self::TAR_TIMEOUT);
            if (!$result->ok()) {
                throw new TaskRejectedException('home archive extraction failed (tar exit ' . $result->exitCode . ')');
            }

            $stagedRoot = $subPath === '' ? $staging . '/' . $username : $staging . '/' . $username . '/' . $subPath;
            if (is_link($stagedRoot) || (!is_dir($stagedRoot) && !is_file($stagedRoot))) {
                throw new TaskRejectedException('archive does not contain the requested path');
            }

            $this->chownTree($stagedRoot, $username);
            $target = $subPath === '' ? $home : $home . '/' . $subPath;

            if ($subPath === '') {
                if (is_link($home) || !is_dir($home)) {
                    throw new TaskRejectedException('account home changed while restoring');
                }
                $this->fs->rename($home, $pre);
                $movedOld = true;
            } else {
                $this->ensureDirectory($pre, 0700);
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    $this->fs->mkdir($parent, 0755);
                }
                if (file_exists($target) || is_link($target)) {
                    $replaced = true;
                    $this->fs->mkdir($pre . '/old/' . dirname($subPath), 0700);
                    $this->fs->rename($target, $pre . '/old/' . $subPath);
                    $movedOld = true;
                }
            }

            try {
                $this->fs->rename($stagedRoot, $target);
                $movedNew = true;
                if ($subPath !== '' && $movedOld) {
                    // keep the pre-restore tree for the whole subtree swap too
                    $this->fs->chownName($pre, 'root', $this->panelGroup());
                }
            } catch (Throwable $e) {
                if ($movedOld) {
                    $this->fs->rename($subPath === '' ? $pre : $pre . '/old/' . $subPath, $target);
                    $movedOld = false;
                }
                throw new TaskRejectedException('restore swap failed; previous files were put back: ' . $e->getMessage());
            }

            $kept = null;
            if ($movedOld) {
                $this->fs->chownName($pre, 'root', $this->panelGroup());
                $kept = basename($pre);
            }
            $this->prunePrerestore($accountsRoot, $username, $pre);

            $this->log->info("home archive {$archiveId} restored for {$username}" . ($subPath === '' ? '' : " ({$subPath})"));

            return [
                'archive_id' => $archiveId,
                'username' => $username,
                'scope' => 'home',
                'path' => $subPath,
                'files' => $inspected['files'],
                'dirs' => $inspected['dirs'],
                'bytes' => $inspected['bytes'],
                'replaced' => $replaced,
                'prerestore' => $kept,
                'status' => 'restored',
            ];
        } finally {
            if (is_dir($staging) && !is_link($staging)) {
                $this->removeTree($staging);
            }
            $this->releaseLock($lock);
            if ($movedNew && $movedOld && $subPath === '') {
                // success path: `$pre` intentionally kept (pre-restore copy)
            } elseif ($movedOld && !$movedNew && $subPath === '' && is_dir($pre)) {
                // swap never completed — put the home back
                $this->fs->rename($pre, $home);
            }
        }
    }

    /**
     * Import a cPanel account archive (`cpmove-<user>.tar.gz` or a legacy
     * `backup-*.tar.gz`) into an EXISTING account home. Same safety pipeline as
     * restoreHome — verified listing, path/type checks, staging outside the
     * account, root-owned pre-restore copy — plus:
     *
     *   - the archive is an outside file, so it is path-guarded, must be a real
     *     regular file and is checksum-verified when the panel sends a sha256,
     *   - only the `homedir` subtree is extracted; MySQL dumps, DNS zones,
     *     mail and cPanel userdata are reported in `sections`, never touched,
     *   - archives that write through symlinks are refused.
     *
     * @return array{username:string,action:string,archive:string,sha256:string,size_bytes:int,layout:string,root:string,files:int,dirs:int,bytes:int,sections:list<string>,section_entries:array<string,int>,prerestore:?string,status:string}
     */
    public function importCpanelHome(string $username, string $archivePath, string $expectedSha256 = '', string $action = 'restore'): array
    {
        $this->assertUsername($username);
        if (!in_array($action, ['transfer', 'restore'], true)) {
            throw new TaskRejectedException('invalid cPanel import action');
        }
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new TaskRejectedException('invalid cPanel archive checksum');
        }

        $archive = $this->assertReadableArchive($archivePath);
        $sha256 = hash_file('sha256', $archive);
        $size = filesize($archive);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !is_int($size) || $size < 20) {
            throw new TaskRejectedException('cPanel archive checksum could not be computed');
        }
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, $sha256)) {
            throw new TaskRejectedException('cPanel archive checksum mismatch; refusing to import');
        }

        $accountsRoot = $this->paths->accountsRoot;
        $home = $this->paths->home($username);
        $this->assertDirectory($accountsRoot, 'account root');
        $this->assertDirectory($home, 'account home');
        $stateRoot = rtrim($this->stateRoot, '/');
        if ($stateRoot === '' || $stateRoot === '/' || is_link($stateRoot)) {
            throw new TaskRejectedException('invalid backup state root');
        }
        $this->assertDirectory($stateRoot, 'state root');

        $lock = $this->acquireLock($username);
        $staging = $accountsRoot . '/.acp-import-' . $username . '-' . gmdate('YmdHis');
        try {
            $plan = CpanelArchive::structure($this->tarListing($archive), $username);

            $this->ensureDirectory($staging, 0700);
            if ($plan['layout'] === 'direct') {
                $homeListing = $this->tarListing($archive, false, $plan['member']);
                $homeVerbose = $this->tarListing($archive, true, $plan['member']);
                $counts = CpanelArchive::homeCounts($homeVerbose);
                CpanelArchive::assertNoSymlinkTraversal($homeListing, $homeVerbose);
                $this->assertFreeSpace($accountsRoot, $counts['bytes']);
                $this->extract($archive, $staging, $plan['member'], 'cPanel archive extraction failed');
                $stagedRoot = $staging . '/' . $plan['member'];
            } else {
                $this->extract($archive, $staging, (string) $plan['nested_member'], 'cPanel archive extraction failed');
                $inner = $staging . '/' . $plan['nested_member'];
                if (is_link($inner) || !is_file($inner)) {
                    throw new TaskRejectedException('cPanel archive nested home was not extracted');
                }
                $innerListing = $this->tarListing($inner);
                $innerVerbose = $this->tarListing($inner, true);
                $counts = CpanelArchive::homeCounts($innerVerbose);
                CpanelArchive::assertNoSymlinkTraversal($innerListing, $innerVerbose);
                $this->assertFreeSpace($accountsRoot, $counts['bytes']);
                $innerStaging = $staging . '/home';
                $this->ensureDirectory($innerStaging, 0700);
                $this->extract($inner, $innerStaging, null, 'cPanel archive home extraction failed');
                $stagedRoot = $innerStaging;
            }

            if (is_link($stagedRoot) || !is_dir($stagedRoot)) {
                throw new TaskRejectedException('cPanel archive home could not be staged');
            }
            $this->chownTree($stagedRoot, $username);
            $kept = $this->swapWholeHome($username, $stagedRoot, $home);

            $this->log->info("cPanel archive " . basename($archive) . " imported for {$username} ({$counts['files']} files)");

            return [
                'username' => $username,
                'action' => $action,
                'archive' => basename($archive),
                'sha256' => $sha256,
                'size_bytes' => $size,
                'layout' => $plan['layout'],
                'root' => $plan['root'],
                'files' => $counts['files'],
                'dirs' => $counts['dirs'],
                'bytes' => $counts['bytes'],
                'sections' => $plan['sections'],
                'section_entries' => $plan['section_entries'],
                'prerestore' => $kept,
                'status' => 'imported',
            ];
        } finally {
            if (is_dir($staging) && !is_link($staging)) {
                try {
                    $this->removeTree($staging);
                } catch (Throwable $e) {
                    $this->log->warning('cPanel import staging cleanup failed: ' . $e->getMessage());
                }
            }
            $this->releaseLock($lock);
        }
    }

    /**
     * Restore the `mysql/` section of a cPanel archive into real MariaDB
     * databases (`<account>_<suffix>`), reusing the archive guards of the home
     * import (allowlisted path, checksum, hostile-member scan, staging dir).
     *
     * Dumps are staged, sanitised (see CpanelMysql) and streamed to the client;
     * the staging always disappears, even on failure.
     *
     * @param  list<string> $only database suffixes to restore (empty = every dump)
     * @return array<string, mixed>
     */
    public function importCpanelMysql(string $username, string $archivePath, string $expectedSha256 = '', string $action = 'restore', array $only = []): array
    {
        $this->assertUsername($username);
        if (!in_array($action, ['transfer', 'restore'], true)) {
            throw new TaskRejectedException('invalid cPanel import action');
        }
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new TaskRejectedException('invalid cPanel archive checksum');
        }

        $archive = $this->assertReadableArchive($archivePath);
        $sha256 = hash_file('sha256', $archive);
        $size = filesize($archive);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !is_int($size) || $size < 20) {
            throw new TaskRejectedException('cPanel archive checksum could not be computed');
        }
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, $sha256)) {
            throw new TaskRejectedException('cPanel archive checksum mismatch; refusing to import');
        }

        $accountsRoot = $this->paths->accountsRoot;
        $this->assertDirectory($accountsRoot, 'account root');

        $lock = $this->acquireLock($username);
        $staging = $accountsRoot . '/.acp-mysql-' . $username . '-' . gmdate('YmdHis');
        try {
            $plan = CpanelArchive::structure($this->tarListing($archive), $username);
            $mysqlEntries = (int) (($plan['section_entries'] ?? [])['mysql'] ?? 0);
            if ($mysqlEntries === 0) {
                return [
                    'username' => $username, 'action' => $action, 'archive' => basename($archive),
                    'sha256' => $sha256, 'size_bytes' => $size, 'status' => 'empty',
                    'databases' => [], 'skipped' => [],
                    'message' => 'archive me mysql/ section nahi hai (koi dump restore nahi hua)',
                ];
            }

            $prefix = $plan['root'] === '' ? '' : $plan['root'] . '/';
            $mysqlMember = $prefix . 'mysql';
            $selection = CpanelMysql::plan($this->tarListing($archive, false, $mysqlMember), $username, $only);
            if ($selection['dumps'] === []) {
                return [
                    'username' => $username, 'action' => $action, 'archive' => basename($archive),
                    'sha256' => $sha256, 'size_bytes' => $size, 'status' => 'empty',
                    'databases' => [], 'skipped' => $selection['skipped'],
                    'message' => 'archive ke mysql/ section me koi restorable dump nahi mila',
                ];
            }

            $this->ensureDirectory($staging, 0700);

            // Phase 1 — stage and sanitise EVERY dump before touching MariaDB, so a
            // hostile archive can never leave a half-imported set of databases.
            $jobs = [];
            foreach ($selection['dumps'] as $dump) {
                $member = $prefix . 'mysql/' . $dump['file'];
                $staged = $staging . '/' . $member;
                $this->extract($archive, $staging, $member, 'cPanel mysql dump extraction failed');
                if (is_link($staged) || !is_file($staged)) {
                    throw new TaskRejectedException('cPanel mysql dump could not be staged: ' . $dump['file']);
                }

                $database = MysqlServer::accountName($username, $dump['suffix'], 'database name');
                $prepared = $staging . '/prepared-' . $dump['suffix'] . '.sql';
                $info = CpanelMysql::prepare($staged, $prepared, $database);
                $jobs[] = ['dump' => $dump, 'database' => $database, 'prepared' => $prepared, 'info' => $info];
            }

            // Phase 2 — create + import.
            $server = new MysqlServer($this->cmd, $this->log);
            $databases = [];
            foreach ($jobs as $job) {
                $created = $server->createDatabase($job['database']);
                $server->importFile($job['prepared'], self::TAR_TIMEOUT);

                $databases[] = [
                    'database' => $job['database'],
                    'dump' => $job['dump']['file'],
                    'gzip' => $job['dump']['gzip'],
                    'bytes' => $job['info']['bytes'],
                    'statements_lines' => $job['info']['lines'],
                    'database_created' => $created,
                    'status' => 'imported',
                ];
                $this->log->info("cPanel mysql dump {$job['dump']['file']} imported into {$job['database']} ({$job['info']['bytes']} bytes)");
            }

            return [
                'username' => $username,
                'action' => $action,
                'archive' => basename($archive),
                'sha256' => $sha256,
                'size_bytes' => $size,
                'layout' => $plan['layout'],
                'status' => 'imported',
                'databases' => $databases,
                'skipped' => $selection['skipped'],
            ];
        } finally {
            if (is_dir($staging) && !is_link($staging)) {
                try {
                    $this->removeTree($staging);
                } catch (Throwable $e) {
                    $this->log->warning('cPanel mysql staging cleanup failed: ' . $e->getMessage());
                }
            }
            $this->releaseLock($lock);
        }
    }

    private function extract(string $archive, string $directory, ?string $member, string $error): void
    {
        $argv = [
            self::TAR_BIN,
            '--extract',
            '--file', $archive,
            '--directory', $directory,
            '--no-same-owner',
            '--one-file-system',
            '--',
        ];
        if ($member !== null) {
            $argv[] = $member;
        }
        $result = $this->cmd->run($argv, self::TAR_TIMEOUT);
        if (!$result->ok()) {
            throw new TaskRejectedException($error . ' (tar exit ' . $result->exitCode . ')');
        }
    }

    /** @param list<string>|null $members */
    private function tarListing(string $archive, bool $verbose = false, ?string $member = null): string
    {
        $argv = [self::TAR_BIN, '--list'];
        if ($verbose) {
            $argv[] = '--verbose';
            $argv[] = '--numeric-owner';
        }
        $argv[] = '--quoting-style=literal';
        $argv[] = '--file';
        $argv[] = $archive;
        if ($member !== null) {
            $argv[] = '--';
            $argv[] = $member;
        }
        $result = $this->cmd->run($argv, 300);
        if (!$result->ok()) {
            throw new TaskRejectedException('cPanel archive is unreadable');
        }

        return $result->stdout;
    }

    private function assertReadableArchive(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new TaskRejectedException('cPanel archive path is missing');
        }
        if (!str_starts_with($path, '/')) {
            throw new TaskRejectedException('cPanel archive path must be absolute');
        }
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new TaskRejectedException('cPanel archive file not found');
        }
        try {
            $this->fs->assert($real); // must still sit inside an allowlisted root
        } catch (Throwable $e) {
            throw new TaskRejectedException('cPanel archive path is outside the allowlisted roots');
        }
        if (preg_match('/\.(tar|tar\.gz|tgz)$/i', basename($real)) !== 1) {
            throw new TaskRejectedException('cPanel archive must be a .tar, .tar.gz or .tgz file');
        }

        return $real;
    }

    /**
     * Swap a freshly staged home tree into place, keeping the replaced tree as
     * an `.acp-prerestore-<user>-<stamp>` copy (same convention as restoreHome).
     * Any failure puts the old home back before the exception leaves.
     */
    private function swapWholeHome(string $username, string $stagedRoot, string $home): ?string
    {
        $accountsRoot = $this->paths->accountsRoot;
        if (is_link($home) || !is_dir($home)) {
            throw new TaskRejectedException('account home changed while importing');
        }
        $stamp = gmdate('YmdHis');
        $pre = $accountsRoot . '/.acp-prerestore-' . $username . '-' . $stamp;
        $suffix = 1;
        while (file_exists($pre) || is_link($pre)) {
            $suffix++;
            if ($suffix > 50) {
                throw new TaskRejectedException('pre-restore path already exists');
            }
            $pre = $accountsRoot . '/.acp-prerestore-' . $username . '-' . $stamp . '-' . $suffix;
        }
        $this->fs->rename($home, $pre);
        try {
            $this->fs->rename($stagedRoot, $home);
        } catch (Throwable $e) {
            $this->fs->rename($pre, $home);
            throw new TaskRejectedException('cPanel import swap failed; previous files were put back: ' . $e->getMessage());
        }
        $this->fs->chownName($pre, 'root', $this->panelGroup());
        $this->prunePrerestore($accountsRoot, $username, $pre);

        return basename($pre);
    }

    public static function normalizeSubPath(string $subPath): string
    {
        if (trim($subPath) === '') {
            return '';
        }

        return Files::normalizeRel($subPath);
    }

    /**
     * @return array{files:int,dirs:int,bytes:int}
     */
    private function inspectArchive(string $archive, string $username, string $subPath): array
    {
        $names = $this->cmd->run([
            self::TAR_BIN,
            '--list',
            '--gzip',
            '--file', $archive,
            '--quoting-style=literal',
        ], 300);
        if (!$names->ok()) {
            throw new TaskRejectedException('home archive is unreadable');
        }
        $prefix = $username . '/';
        $files = 0;
        $dirs = 0;
        $lines = 0;
        $sawRoot = false;
        $sawRequested = $subPath === '';
        foreach (preg_split('/\r?\n/', rtrim($names->stdout, "\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $lines++;
            if ($lines > self::MAX_ENTRIES) {
                throw new TaskRejectedException('home archive has too many entries');
            }
            if ($line === $username) {
                $sawRoot = true;
                $dirs++;
                continue;
            }
            if (!str_starts_with($line, $prefix)) {
                throw new TaskRejectedException('home archive contains an entry outside the account home');
            }
            $rel = substr($line, strlen($prefix));
            if ($rel === '') {   // the top-level `username/` entry itself
                $sawRoot = true;
                $dirs++;
                continue;
            }
            if (str_contains($rel, "\0") || str_contains($rel, '\\')) {
                throw new TaskRejectedException('home archive contains an unsafe path');
            }
            $isDir = str_ends_with($rel, '/');
            $clean = $isDir ? substr($rel, 0, -1) : $rel;
            if ($clean === '') {
                throw new TaskRejectedException('home archive contains an unsafe path');
            }
            foreach (explode('/', $clean) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new TaskRejectedException('home archive contains a path escape');
                }
            }
            if ($subPath !== '' && ($clean === $subPath || str_starts_with($clean, $subPath . '/'))) {
                $sawRequested = true;
            }
            if ($isDir) {
                $dirs++;
            } else {
                $files++;
            }
        }
        if ($lines === 0 || (!$sawRoot && $subPath === '')) {
            throw new TaskRejectedException('home archive is empty');
        }
        if (!$sawRequested) {
            throw new TaskRejectedException("archive does not contain '{$subPath}'");
        }

        $verbose = $this->cmd->run([
            self::TAR_BIN,
            '--list',
            '--verbose',
            '--numeric-owner',
            '--gzip',
            '--file', $archive,
            '--quoting-style=literal',
        ], 300);
        if (!$verbose->ok()) {
            throw new TaskRejectedException('home archive entry types could not be verified');
        }
        CpanelArchive::assertNoSymlinkTraversal($names->stdout, $verbose->stdout);
        $bytes = 0;
        foreach (preg_split('/\r?\n/', rtrim($verbose->stdout, "\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $type = $line[0];
            if (in_array($type, ['h', 'b', 'c', 'p', 's'], true)) {
                throw new TaskRejectedException('home archive contains a hardlink or special file; refusing to restore');
            }
            if (!in_array($type, ['-', 'd', 'l'], true)) {
                throw new TaskRejectedException('home archive contains an unexpected entry type');
            }
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            $size = $tokens[2] ?? null;
            if (is_string($size) && ctype_digit($size)) {
                $value = (int) $size;
                if ($value > PHP_INT_MAX - $bytes) {
                    throw new TaskRejectedException('home archive size cannot be summed safely');
                }
                $bytes += $value;
            }
        }

        return ['files' => $files, 'dirs' => $dirs, 'bytes' => $bytes];
    }

    private function chownTree(string $root, string $owner): void
    {
        if (is_link($root)) {
            return; // never follow or touch symlinks
        }
        $nodes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($nodes++ > self::MAX_ENTRIES) {
                throw new TaskRejectedException('restored tree is too large to chown safely');
            }
            if (is_link($path)) {
                continue; // lchown is unavailable in PHP; leaving the link root-owned is safe
            }
            $this->fs->chownName($path, $owner);
        }
        if (!is_link($root)) {
            $this->fs->chownName($root, $owner);
        }
    }

    private function prunePrerestore(string $accountsRoot, string $username, string $keepPath): void
    {
        $prefix = '.acp-prerestore-' . $username . '-';
        $names = @scandir($accountsRoot) ?: [];
        $found = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || !str_starts_with($name, $prefix)) {
                continue;
            }
            $path = $accountsRoot . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $found[] = $name;
            }
        }
        if (count($found) <= self::PRERESTORE_KEEP) {
            return;
        }
        rsort($found);
        $drop = array_slice($found, self::PRERESTORE_KEEP);
        foreach ($drop as $name) {
            $path = $accountsRoot . '/' . $name;
            if ($path === $keepPath) {
                continue;
            }
            try {
                $this->removeTree($path);
            } catch (Throwable $e) {
                $this->log->warning('pre-restore cleanup failed: ' . $e->getMessage());
            }
        }
    }

    private function removeTree(string $root): void
    {
        $this->fs->assert($root);
        if (is_link($root)) {
            $this->fs->unlink($root);

            return;
        }
        if (!is_dir($root)) {
            if (is_file($root)) {
                $this->fs->unlink($root);
            }

            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $this->fs->assert($path);
            if (is_link($path) || $entry->isFile()) {
                $this->fs->unlink($path);
                continue;
            }
            $this->fs->rmdir($path);
        }
        $this->fs->rmdir($root);
    }

    /** @return resource */
    private function acquireLock(string $username)
    {
        $dir = rtrim($this->stateRoot, '/') . '/backups/locks';
        $this->ensureDirectory($dir, 0750);
        $handle = @fopen($dir . '/' . $username . '.lock', 'c');
        if ($handle === false) {
            throw new TaskRejectedException('cannot open restore lock file');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new TaskRejectedException('another restore is already running for this account');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public static function normalizeId(string $archiveId): string
    {
        $archiveId = strtolower(trim($archiveId));
        if (preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            throw new TaskRejectedException('invalid backup archive id');
        }

        return $archiveId;
    }

    private function assertUsername(string $username): void
    {
        $error = AccountIdentity::username($username);
        if ($error !== null) {
            throw new TaskRejectedException($error);
        }
    }

    private function assertDirectory(string $path, string $label): void
    {
        $this->fs->assert($path);
        if (is_link($path) || !is_dir($path)) {
            throw new TaskRejectedException("{$label} is missing or is a symlink");
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        $this->fs->assert($path);
        if (is_link($path)) {
            throw new TaskRejectedException('backup storage path is a symlink');
        }
        if (file_exists($path) && !is_dir($path)) {
            throw new TaskRejectedException('backup storage path is not a directory');
        }
        if (!is_dir($path)) {
            $this->fs->mkdir($path, $mode);
        }
        $this->fs->chmod($path, $mode);
        $this->fs->chownName($path, 'root', $this->panelGroup());
    }

    private function panelGroup(): string
    {
        $group = getenv('ACP_PANEL_GROUP') ?: 'alphacp';
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $group) !== 1) {
            throw new TaskRejectedException('invalid panel group configuration');
        }

        return $group;
    }

    private function estimateHomeBytes(string $home): int
    {
        $total = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $entry) {
                if ($entry->isLink() || !$entry->isFile()) {
                    continue;
                }
                $size = $entry->getSize();
                if ($size < 0 || $size > PHP_INT_MAX - $total) {
                    throw new TaskRejectedException('account home size cannot be estimated safely');
                }
                $total += $size;
            }
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('cannot safely scan account home for backup');
        }

        return $total;
    }

    private function assertFreeSpace(string $target, int $estimatedBytes): void
    {
        $free = @disk_free_space($target);
        if ($free === false || !is_numeric($free)) {
            $this->log->warning('backup free-space check unavailable; proceeding with archive attempt');
            return;
        }
        if ($estimatedBytes > PHP_INT_MAX - self::MIN_FREE_BYTES || (float) $free < $estimatedBytes + self::MIN_FREE_BYTES) {
            throw new TaskRejectedException('insufficient free disk space for a safe home backup');
        }
    }

    /** @return array{archive_id:string,username:string,scope:string,filename:string,size_bytes:int,sha256:string,created_at:string,status:string}|null */
    private function loadExisting(string $archive, string $manifest, string $username, string $archiveId): ?array
    {
        if (!file_exists($archive) && !file_exists($manifest) && !is_link($archive) && !is_link($manifest)) {
            return null;
        }
        if (is_link($archive) || is_link($manifest) || !is_file($archive) || !is_file($manifest)) {
            throw new TaskRejectedException('backup archive id collides with an incomplete or unsafe file');
        }
        $data = json_decode($this->fs->read($manifest), true);
        if (!is_array($data)
            || ($data['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($data['archive_id'] ?? null) !== $archiveId
            || ($data['username'] ?? null) !== $username
            || ($data['scope'] ?? null) !== 'home'
            || ($data['filename'] ?? null) !== $archiveId . '.tar.gz'
            || !is_int($data['size_bytes'] ?? null)
            || !is_string($data['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $data['sha256']) !== 1
            || !is_string($data['created_at'] ?? null)) {
            throw new TaskRejectedException('existing backup manifest is invalid');
        }
        $hash = hash_file('sha256', $archive);
        $size = filesize($archive);
        if (!is_string($hash) || !hash_equals($data['sha256'], $hash) || $size !== $data['size_bytes']) {
            throw new TaskRejectedException('existing backup archive failed checksum verification');
        }

        return [
            'archive_id' => $archiveId,
            'username' => $username,
            'scope' => 'home',
            'filename' => $archiveId . '.tar.gz',
            'size_bytes' => $size,
            'sha256' => $hash,
            'created_at' => $data['created_at'],
            'status' => 'ready',
        ];
    }

    private function pruneExpired(string $accountDir, string $username, string $currentId): int
    {
        $configPath = rtrim($this->stateRoot, '/') . '/etc/backup/config.json';
        $retention = 30;
        if (is_link($configPath)) {
            $this->log->warning('backup config is a symlink; retention cleanup skipped');
            return 0;
        }
        if (is_file($configPath)) {
            $config = json_decode($this->fs->read($configPath), true);
            if (is_array($config) && is_int($config['retention'] ?? null) && $config['retention'] >= 1 && $config['retention'] <= 365) {
                $retention = $config['retention'];
            } else {
                $this->log->warning('backup retention config invalid; using 30 days');
            }
        }

        $cutoff = time() - ($retention * 86400);
        $deleted = 0;
        foreach ($this->fs->listNames($accountDir) as $name) {
            if (preg_match('/^([a-f0-9]{32})\.json$/', $name, $match) !== 1 || $match[1] === $currentId) {
                continue;
            }
            $id = $match[1];
            $manifest = $accountDir . '/' . $name;
            $archive = $accountDir . '/' . $id . '.tar.gz';
            if (is_link($manifest) || is_link($archive) || !is_file($manifest) || !is_file($archive)) {
                continue;
            }
            $mtime = filemtime($manifest);
            if (!is_int($mtime) || $mtime >= $cutoff) {
                continue;
            }
            $this->fs->unlink($archive);
            $this->fs->unlink($manifest);
            $deleted++;
        }

        return $deleted;
    }
}
