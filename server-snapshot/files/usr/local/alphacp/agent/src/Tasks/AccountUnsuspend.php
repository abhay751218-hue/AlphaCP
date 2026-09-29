<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * account.unsuspend — reverse of suspend.
 *
 * @acp-task account.unsuspend
 */
final class AccountUnsuspend implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $domain = strtolower((string) $payload['domain']);
        $err = AccountIdentity::username($username) ?? AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('account.unsuspend requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $os->unlockUser($username);
        $os->writeLiveVhost($username, $domain);
        $os->enablePool($username);
        $os->reloadServices();
        $ctx->log->info("account {$username} unsuspended");

        return [
            'username' => $username,
            'domain'   => $domain,
            'status'   => 'active',
        ];
    }
}
