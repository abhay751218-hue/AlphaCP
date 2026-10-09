<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * files.set — mkdir / write / delete / rename + D15: chmod / compress (tar.gz)
 * / extract (tar.gz) / upload (panel staging file se) — sab account home ke
 * andar jailed.
 *
 * @acp-task files.set
 */
final class FilesSet implements TaskInterface
{
    private const STAGING_PREFIX = '/usr/local/alphacp/panel/storage/app/fm-staging/';
    private const UPLOAD_MAX = 104857600; // 100 MB

    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('files.set requires PathGuard roots');
        }
        try {
            $op = Files::normalizeOp((string) ($payload['op'] ?? ''));
            $rel = Files::normalizeRel((string) ($payload['path'] ?? ''));
        } catch (TaskRejectedException $e) {
            throw $e;
        }
        if ($rel === '') {
            throw new TaskRejectedException('refusing to mutate account home root');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $out = match ($op) {
                'mkdir' => $os->mkdirFile($username, $rel),
                'write' => $os->writeFile($username, $rel, (string) ($payload['content'] ?? '')),
                'delete' => $os->deleteFile($username, $rel),
                'rename' => $os->renameFile($username, $rel, (string) ($payload['to'] ?? '')),
                'chmod' => $this->chmodPath($ctx, $username, $rel, (string) ($payload['mode'] ?? '')),
                'compress' => $this->compress($ctx, $username, $rel),
                'extract' => $this->extract($ctx, $username, $rel),
                'upload' => $this->upload($ctx, $username, $rel, (string) ($payload['staging'] ?? '')),
                default => throw new TaskRejectedException('invalid files op'),
            };
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info("files.{$op} {$rel} for {$username}");

        return [
            'username' => $username,
            'op'       => $op,
            'path'     => $rel,
            'result'   => $out,
            'status'   => 'ok',
        ];
    }

    /** D15 — permissions (3 octal digits, no setuid/setgid possible). */
    private function chmodPath(TaskContext $ctx, string $username, string $rel, string $mode): string
    {
        if (preg_match('/^[0-7]{3}$/', $mode) !== 1) {
            throw new TaskRejectedException('invalid mode (jaise 644, 755)');
        }
        $fs = new SafeFs($ctx->paths);
        $abs = Files::resolve(AccountPaths::fromEnv()->home($username), $rel);
        if (is_link($abs) || !file_exists($abs)) {
            throw new TaskRejectedException('path missing (ya symlink — allowed nahi)');
        }
        $fs->chmod($abs, octdec($mode));

        return $rel . ' -> ' . $mode;
    }

    /** D15 — <path> ko <path>.tar.gz me compress karo (cPanel Compress). */
    private function compress(TaskContext $ctx, string $username, string $rel): string
    {
        $fs = new SafeFs($ctx->paths);
        $abs = Files::resolve(AccountPaths::fromEnv()->home($username), $rel);
        if (is_link($abs) || !file_exists($abs)) {
            throw new TaskRejectedException('path missing (ya symlink)');
        }
        $dest = $abs . '.tar.gz';
        if (file_exists($dest)) {
            throw new TaskRejectedException(basename($dest) . ' pehle se maujood hai — pehle delete/rename karo');
        }
        $r = $ctx->cmd->run(['/usr/bin/tar', 'czf', $dest, '-C', dirname($abs), basename($abs)], 300);
        if (!$r->ok()) {
            @unlink($dest);
            throw new TaskRejectedException('tar fail: ' . substr(trim($r->stderr), 0, 200));
        }
        $fs->chownName($dest, $username);
        $fs->chmod($dest, 0644);

        return $rel . '.tar.gz';
    }

    /** D15 — .tar.gz ko usi folder me extract karo (cPanel Extract). */
    private function extract(TaskContext $ctx, string $username, string $rel): string
    {
        $fs = new SafeFs($ctx->paths);
        $abs = Files::resolve(AccountPaths::fromEnv()->home($username), $rel);
        if (is_link($abs) || !is_file($abs)) {
            throw new TaskRejectedException('archive missing (ya symlink)');
        }
        if (!str_ends_with($abs, '.tar.gz') && !str_ends_with($abs, '.tgz')) {
            throw new TaskRejectedException('sirf .tar.gz / .tgz extract hota hai');
        }
        $destDir = dirname($abs);
        // GNU tar: absolute paths strip + '..' members refuse (default), owner hum khud set karenge
        $r = $ctx->cmd->run(['/usr/bin/tar', '--no-same-owner', '-xzf', $abs, '-C', $destDir], 300);
        if (!$r->ok()) {
            throw new TaskRejectedException('tar extract fail: ' . substr(trim($r->stderr), 0, 200));
        }
        $this->chownTree($fs, $destDir, $username);

        return dirname($rel) === '.' ? '' : dirname($rel);
    }

    /** D15 — panel staging file ko account home me move karo (upload). */
    private function upload(TaskContext $ctx, string $username, string $rel, string $staging): string
    {
        if (preg_match('~^' . preg_quote(self::STAGING_PREFIX, '~') . '[A-Za-z0-9._-]{1,120}$~', $staging) !== 1) {
            throw new TaskRejectedException('invalid staging path');
        }
        if (is_link($staging) || !is_file($staging)) {
            throw new TaskRejectedException('staging file missing');
        }
        $size = (int) filesize($staging);
        if ($size < 1 || $size > self::UPLOAD_MAX) {
            @unlink($staging);
            throw new TaskRejectedException('upload 1 B – 100 MB hona chahiye');
        }
        $fs = new SafeFs($ctx->paths);
        $abs = Files::resolve(AccountPaths::fromEnv()->home($username), $rel);
        if (is_dir($abs) || is_link($abs)) {
            throw new TaskRejectedException('destination directory/symlink hai');
        }
        if (!is_dir(dirname($abs))) {
            throw new TaskRejectedException('destination folder missing — pehle folder banao');
        }
        if (!copy($staging, $abs)) {
            throw new TaskRejectedException('upload copy fail');
        }
        @unlink($staging);
        $fs->chownName($abs, $username);
        $fs->chmod($abs, 0644);

        return $rel . ' (' . $size . ' bytes)';
    }

    /** Extracted tree ki ownership account user ko do (symlinks skip). */
    private function chownTree(SafeFs $fs, string $dir, string $username): void
    {
        $fs->chownName($dir, $username);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $n = 0;
        foreach ($it as $p) {
            if (is_link((string) $p)) {
                continue;
            }
            $fs->chownName((string) $p, $username);
            if (++$n > 20000) {
                break; // safety cap
            }
        }
    }
}
