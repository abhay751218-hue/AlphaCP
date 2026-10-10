<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Ftp;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guards for the real `ftp.*` tasks (S6).
 *
 *  - only an existing AlphaCP account (Linux user check) may own FTP logins,
 *  - every virtual login must carry the account prefix (`<account>_<suffix>`),
 *  - pure-pw is reached only through the allowlisted CommandRunner (root-side),
 *    never from the web FPM (proc_open is disabled there — B1).
 */
abstract class FtpTask implements TaskInterface
{
    protected function account(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('ftp tasks require PathGuard roots');
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

    /** The Pure-FTPd virtual login `<account>_<suffix>`. */
    protected function login(string $account, string $raw): string
    {
        $login = strtolower(trim($raw));
        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $login) !== 1) {
            throw new TaskRejectedException('invalid FTP login: letters/numbers/_ , 3-32 chars, letter first');
        }
        if (!str_starts_with($login, $account . '_')) {
            throw new TaskRejectedException("FTP login must belong to account '{$account}' (prefix {$account}_)");
        }

        return $login;
    }

    protected function ftp(TaskContext $ctx): Ftp
    {
        return new Ftp($ctx->cmd, $ctx->log);
    }
}
