<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * git.pull — `<home>/git/<dir>` par `git pull --ff-only` (cPanel "Update"/pull).
 * Repo maujood na ho to reject; fast-forward-only isliye ki history rewrite na ho.
 *
 * @acp-task git.pull
 */
final class GitPull extends GitTask
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

        $r = $this->git($ctx)->pull($path, 120);
        if (!$r->ok()) {
            throw new TaskRejectedException('git pull failed: ' . substr(trim($r->stderr), 0, 300));
        }

        return [
            'username' => $username,
            'dir'      => $dir,
            'output'   => substr(trim($r->stdout), 0, 4000),
            'status'   => 'ok',
        ];
    }
}
