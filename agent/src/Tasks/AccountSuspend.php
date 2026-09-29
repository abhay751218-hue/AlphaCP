<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * account.suspend — lock Linux user, swap vhost to suspended page, disable pool.
 *
 * @acp-task account.suspend
 */
final class AccountSuspend implements TaskInterface
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
            throw new TaskRejectedException('account.suspend requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $os->lockUser($username);
        $os->writeSuspendedVhost($username, $domain);
        $os->disablePool($username);
        $os->reloadServices();
        $ctx->log->info("account {$username} suspended");

        return [
            'username' => $username,
            'domain'   => $domain,
            'status'   => 'suspended',
            'reason'   => (string) ($payload['reason'] ?? ''),
        ];
    }
}
