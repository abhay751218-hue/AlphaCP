<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Creates immutable, checksum-verified home archives outside the account tree,
 * and restores them through an audited staging directory.
 * The archive directory is root-owned and group-readable by the panel so an
 * authenticated, account-scoped controller can stream a completed download.
 */
final class BackupArchiveStore
{
    private const FORMAT_VERSION = 1;
    private const MIN_FREE_BYTES = 67_108_864; // 64 MiB safety reserve
    private const TAR_TIMEOUT = 3600;
    private const TAR_BIN = '/usr/bin/tar';

    /** Restore: staged extraction lives beside the home so the swap is a rename. */
    private const RESTORE_PREFIX = '.acp-restore-';
    private const RESTORE_EXPANSION_FACTOR = 8; // gzip -> on-disk headroom
    private const MAX_RESTORE_NODES = 250_000;
    private const MAX_RESTORE_DEPTH = 40;
    private const HOME_MODE = 0751;

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

        $stateRoot = $this->assertStateRoot();

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

    public static function normalizeId(string $archiveId): string
    {
        $archiveId = strtolower(trim($archiveId));
        if (preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            throw new TaskRejectedException('invalid backup archive id');
        }

        return $archiveId;
    }

    /**
     * Restore a checksum-verified home archive into the account home.
     *
     * Fail-closed sequence — the live home is not touched until the staged tree
     * has been fully verified:
     *   1. re-verify the manifest, SHA-256, size and root ownership of the archive,
     *   2. extract into a fresh root-owned `0700` staging directory next to the
     *      home (same filesystem, so the swap is a rename, not a copy),
     *   3. audit the staged tree: exactly one account directory, no special
     *      files, no setuid/setgid bits, no symlink that escapes the account
     *      subtree (escaping links are deleted and reported, never recreated),
     *      and ownership transferred to the account (a quota/ownership failure
     *      aborts the restore while the live home is still intact),
     *   4. rename the live home aside, rename the staged tree into place and
     *      roll back automatically when that fails,
     *   5. delete the previous home and the staging directory.
     *
     * Mail and database contents are intentionally NOT claimed: S7/S8 do not
     * provision real mailboxes/databases yet, so only home files are restored.
     *
     * @return array{archive_id:string,username:string,scope:string,files:int,bytes:int,sha256:string,restored_at:string,status:string,removed_symlinks:int,setuid_cleared:int,previous_home:string}
     */
    public function restoreHome(string $username, string $archiveId): array
    {
        $this->assertUsername($username);
        $archiveId = self::normalizeId($archiveId);
        $accountsRoot = $this->paths->accountsRoot;
        $home = $this->paths->home($username);
        $this->assertDirectory($accountsRoot, 'account root');
        $this->assertDirectory($home, 'account home');

        $stateRoot = $this->assertStateRoot();
        $accountDir = $stateRoot . '/backups/accounts/' . $username;
        $archive = $accountDir . '/' . $archiveId . '.tar.gz';
        $manifest = $accountDir . '/' . $archiveId . '.json';
        $this->assertRegularFile($archive, 'backup archive');
        $this->assertRegularFile($manifest, 'backup archive manifest');
        $record = $this->verifiedRecord($archive, $manifest, $username, $archiveId);
        $this->assertArchiveOwnership($archive);
        $this->assertRestoreSpace($accountsRoot, $record['size_bytes']);

        $staging = $accountsRoot . '/' . self::RESTORE_PREFIX . $username . '-' . bin2hex(random_bytes(6));
        if (file_exists($staging) || is_link($staging)) {
            throw new TaskRejectedException('restore staging path already exists');
        }
        $this->fs->mkdir($staging, 0700);
        $this->fs->chmod($staging, 0700);
        $this->fs->chownName($staging, 'root', $this->panelGroup());

        $swappedHome = null;
        try {
            $extract = $this->cmd->run([
                self::TAR_BIN,
                '--extract',
                '--gzip',
                '--file', $archive,
                '--directory', $staging,
                '--no-same-owner',
                '--', $username,
            ], self::TAR_TIMEOUT);
            if (!$extract->ok()) {
                throw new TaskRejectedException(
                    'backup archive extraction failed (tar exit ' . $extract->exitCode . '); home untouched',
                );
            }

            $stagedHome = $staging . '/' . $username;
            $topLevel = $this->fs->listNames($staging);
            if ($topLevel !== [$username]) {
                throw new TaskRejectedException('backup archive did not stage exactly one account directory');
            }
            if (is_link($stagedHome) || !is_dir($stagedHome)) {
                throw new TaskRejectedException('staged account home is missing or is a symlink');
            }

            $audit = ['nodes' => 0, 'files' => 0, 'bytes' => 0, 'removed_symlinks' => 0, 'setuid_cleared' => 0, 'ownership_failed' => 0];
            $this->auditWalk($stagedHome, $username, $stagedHome, $audit, 0);
            if ($audit['ownership_failed'] > 0) {
                throw new TaskRejectedException(
                    "restore blocked: {$audit['ownership_failed']} file(s) could not be owned by '{$username}' (quota or filesystem error)",
                );
            }

            $previous = $accountsRoot . '/.' . $username . '.pre-restore-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            $this->fs->rename($home, $previous);
            $swappedHome = $previous;
            try {
                $this->fs->rename($stagedHome, $home);
            } catch (Throwable $e) {
                $swappedHome = null; // the original home must never be deleted here
                try {
                    $this->fs->rename($previous, $home);
                } catch (Throwable $rollbackError) {
                    throw new TaskRejectedException(
                        'restore swap failed and rollback failed; original home kept at ' . $previous
                        . ' (' . $rollbackError->getMessage() . ')',
                    );
                }
                throw new TaskRejectedException('restore swap failed; original home kept: ' . $e->getMessage());
            }
            $swappedHome = null;
            $this->fs->chmod($home, self::HOME_MODE);
            $this->fs->chownName($home, $username);

            $previousHome = 'removed';
            try {
                $this->removeTree($previous);
            } catch (Throwable $e) {
                $previousHome = 'kept';
                $this->log->warning('previous home could not be deleted after restore: ' . $e->getMessage());
            }

            $this->log->info(
                "home archive {$archiveId} restored for {$username}: {$audit['files']} files, "
                . "{$audit['removed_symlinks']} escaping symlink(s) dropped, {$audit['setuid_cleared']} setuid bit(s) cleared",
            );

            return [
                'archive_id' => $archiveId,
                'username' => $username,
                'scope' => 'home',
                'files' => $audit['files'],
                'bytes' => $audit['bytes'],
                'sha256' => $record['sha256'],
                'restored_at' => gmdate(DATE_ATOM),
                'status' => 'restored',
                'removed_symlinks' => $audit['removed_symlinks'],
                'setuid_cleared' => $audit['setuid_cleared'],
                'previous_home' => $previousHome,
            ];
        } finally {
            foreach (array_filter([$staging, $swappedHome]) as $leftover) {
                try {
                    $this->removeTree($leftover);
                } catch (Throwable $e) {
                    $this->log->warning('restore cleanup failed for ' . $leftover . ': ' . $e->getMessage());
                }
            }
        }
    }

    private function assertStateRoot(): string
    {
        $stateRoot = rtrim($this->stateRoot, '/');
        if ($stateRoot === '' || $stateRoot === '/' || is_link($stateRoot)) {
            throw new TaskRejectedException('invalid backup state root');
        }
        $this->fs->assert($stateRoot);
        $this->assertDirectory($stateRoot, 'state root');

        return $stateRoot;
    }

    private function assertRegularFile(string $path, string $label): void
    {
        try {
            $this->fs->assertSafe($path);
        } catch (PathGuardException $e) {
            throw new TaskRejectedException("unsafe {$label} path: " . $e->getMessage());
        }
        if (is_link($path) || !is_file($path)) {
            throw new TaskRejectedException("{$label} is missing or is not a regular file");
        }
    }

    private function assertArchiveOwnership(string $archive): void
    {
        if (!$this->runningAsRoot()) {
            return; // sandbox/test run: ownership checks are not meaningful
        }
        if (@fileowner($archive) !== 0) {
            throw new TaskRejectedException('backup archive is not root-owned; refusing to restore it');
        }
        $perms = @fileperms($archive);
        if (is_int($perms) && ($perms & 0002) !== 0) {
            throw new TaskRejectedException('backup archive is world-writable; refusing to restore it');
        }
    }

    private function assertRestoreSpace(string $target, int $archiveBytes): void
    {
        $free = @disk_free_space($target);
        if ($free === false || !is_numeric($free)) {
            $this->log->warning('restore free-space check unavailable; proceeding with extraction attempt');
            return;
        }
        $needed = ($archiveBytes * self::RESTORE_EXPANSION_FACTOR) + self::MIN_FREE_BYTES;
        if ((float) $free < $needed) {
            throw new TaskRejectedException('insufficient free disk space to restore this archive safely');
        }
    }

    /**
     * Verify every staged entry before it can reach the live home.
     *
     * @param array{nodes:int,files:int,bytes:int,removed_symlinks:int,setuid_cleared:int,ownership_failed:int} $audit
     */
    private function auditWalk(string $dir, string $username, string $stagedHome, array &$audit, int $depth): void
    {
        if ($depth > self::MAX_RESTORE_DEPTH) {
            throw new TaskRejectedException('backup archive nests too deeply to restore safely');
        }
        foreach ($this->fs->listNames($dir) as $name) {
            if (++$audit['nodes'] > self::MAX_RESTORE_NODES) {
                throw new TaskRejectedException('backup archive has too many entries to restore safely');
            }
            $full = $dir . '/' . $name;
            $this->fs->assert($full);

            if (is_link($full)) {
                $target = @readlink($full);
                if (!is_string($target) || $target === '' || str_contains($target, "\0")) {
                    throw new TaskRejectedException('backup archive contains an unreadable symlink');
                }
                if (!$this->symlinkStaysInside($full, $target, $stagedHome)) {
                    $this->fs->unlink($full);
                    $audit['removed_symlinks']++;
                    $this->log->warning("restore: dropping escaping symlink {$name} -> {$target}");
                    continue;
                }
                $this->transferOwnership($full, $username, true, $audit);
                continue;
            }

            if (is_dir($full)) {
                $this->clearSpecialBits($full, $audit);
                $this->transferOwnership($full, $username, false, $audit);
                $this->auditWalk($full, $username, $stagedHome, $audit, $depth + 1);
                continue;
            }

            if (!is_file($full)) {
                throw new TaskRejectedException('backup archive contains a special file: ' . $name);
            }
            $this->clearSpecialBits($full, $audit);
            $this->transferOwnership($full, $username, false, $audit);
            $audit['files']++;
            $size = @filesize($full);
            $audit['bytes'] += is_int($size) ? $size : 0;
        }
    }

    /** Only relative links that stay inside the staged account home may be recreated. */
    private function symlinkStaysInside(string $linkPath, string $target, string $stagedHome): bool
    {
        if (str_starts_with($target, '/')) {
            return false;
        }
        $resolved = PathGuard::canonicalize(dirname($linkPath) . '/' . $target);

        return $resolved === $stagedHome || str_starts_with($resolved, $stagedHome . '/');
    }

    /**
     * @param array{setuid_cleared:int} $audit
     */
    private function clearSpecialBits(string $path, array &$audit): void
    {
        $perms = @fileperms($path);
        if (!is_int($perms)) {
            return;
        }
        $mode = $perms & 07777;
        if (($mode & 06000) !== 0) {
            $this->fs->chmod($path, $mode & ~06000);
            $audit['setuid_cleared']++;
        }
    }

    /**
     * @param array{ownership_failed:int} $audit
     */
    private function transferOwnership(string $path, string $username, bool $isLink, array &$audit): void
    {
        if ($isLink) {
            if (function_exists('lchown')) {
                @lchown($path, $username);
                @lchgrp($path, $username);
            }
            return;
        }
        $this->fs->chownName($path, $username);
        if (!$this->runningAsRoot() || !function_exists('posix_getpwnam')) {
            return;
        }
        $info = @posix_getpwnam($username);
        if (!is_array($info) || !isset($info['uid'])) {
            return;
        }
        if (@fileowner($path) !== (int) $info['uid']) {
            $audit['ownership_failed']++;
        }
    }

    /** Recursive delete that never follows symlinks and stays inside the roots. */
    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            throw new TaskRejectedException('refusing to delete through a symlink');
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach ($this->fs->listNames($dir) as $name) {
            $full = $dir . '/' . $name;
            $this->fs->assert($full);
            if (is_link($full) || is_file($full)) {
                $this->fs->unlink($full);
                continue;
            }
            if (is_dir($full)) {
                $this->removeTree($full);
                continue;
            }
            throw new TaskRejectedException('refusing to delete a special file during restore cleanup');
        }
        $this->fs->rmdir($dir);
    }

    private function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && @posix_geteuid() === 0;
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

        return $this->verifiedRecord($archive, $manifest, $username, $archiveId);
    }

    /**
     * Manifest + checksum verification shared by create (retry) and restore.
     *
     * @return array{archive_id:string,username:string,scope:string,filename:string,size_bytes:int,sha256:string,created_at:string,status:string}
     */
    private function verifiedRecord(string $archive, string $manifest, string $username, string $archiveId): array
    {
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
