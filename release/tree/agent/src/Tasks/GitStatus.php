<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * git.status — `<home>/git/<dir>` ka `git status --porcelain` output (read-only).
 * Panel yeh synchronous dikhata hai (Paneld::run), cPanel ke status page ki tarah.
 *
 * @acp-task git.status
 */
final class GitStatus extends GitTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $dir = $this->dir((string) ($payload['dir'] ?? ''));
        $path = $this->path($ctx, $username, $dir);

        $fs = $this->fs($ctx);
        if (!$fs->isDir($path . '/.git')) {
            throw new TaskRejectedException("git repo nahi mila: {$dir}");
        }

        $r = $this->git($ctx)->status($path, 30);
        if (!$r->ok()) {
            throw new TaskRejectedException('git status failed: ' . substr(trim($r->stderr), 0, 300));
        }

        $lines = $r->stdoutTrimmed() === '' ? [] : explode("\n", $r->stdoutTrimmed());

        return [
            'username' => $username,
            'dir'      => $dir,
            'clean'    => $lines === [],
            'lines'    => array_slice($lines, 0, 500),
            'status'   => 'ok',
        ];
    }
}
