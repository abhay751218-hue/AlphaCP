<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Git;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guards for the real `git.*` tasks (cPanel "Git Version Control", B1).
 *
 *  - sirf existing AlphaCP account (Linux user check) ke repos,
 *  - repo dir `<home>/git/<dir>` — `dir` tight regex se validate (koi `..`/slash nahi),
 *  - git sirf root agent ke allowlisted CommandRunner se (web-FPM proc_open disabled).
 */
abstract class GitTask implements TaskInterface
{
    protected function account(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('git tasks require PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['account'] ?? '')));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        return $username;
    }

    /** Repo dir name: `^[a-z0-9._-]{1,64}$`, `.`/`..` aur slash forbidden. */
    protected function dir(string $raw): string
    {
        $dir = strtolower(trim($raw));
        if (preg_match('/^[a-z0-9._-]{1,64}$/', $dir) !== 1 || $dir === '.' || $dir === '..') {
            throw new TaskRejectedException('invalid repo dir: letters/numbers/._- , 1-64 chars');
        }

        return $dir;
    }

    /** Absolute repo path `<home>/git/<dir>`, PathGuard se assert kiya hua. */
    protected function path(TaskContext $ctx, string $account, string $dir): string
    {
        $home = AccountPaths::fromEnv()->home($account);
        $path = Git::base($home) . '/' . $dir;
        (new SafeFs($ctx->paths))->assert($path);

        return $path;
    }

    protected function home(TaskContext $ctx, string $account): string
    {
        return AccountPaths::fromEnv()->home($account);
    }

    protected function git(TaskContext $ctx): Git
    {
        return new Git($ctx->cmd, $ctx->log);
    }

    protected function fs(TaskContext $ctx): SafeFs
    {
        return new SafeFs($ctx->paths);
    }
}
