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
