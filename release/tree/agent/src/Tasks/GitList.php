<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * git.list — repos under `<home>/git` jo asli git repos hain (`.git` maujood).
 * Read-only; panel ka Git index isi se banta hai (web-FPM /home scan nahi kar sakta).
 *
 * @acp-task git.list
 */
final class GitList extends GitTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);

        return [
            'username' => $username,
            'repos'    => $this->git($ctx)->listRepos($this->fs($ctx), $this->home($ctx, $username)),
            'status'   => 'ok',
        ];
    }
}
