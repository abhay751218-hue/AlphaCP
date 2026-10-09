<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * account.terminate — remove vhost, pool, quota, Linux user+home.
 * Idempotent if the user is already gone. Destructive (needs _confirm).
 *
 * @acp-task account.terminate
 */
final class AccountTerminate implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('account.terminate requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $os->removeExtraVhosts($username);
        $os->removeVhost($username);
        $os->removePool($username);
        try {
            $os->setQuota($username, 0);
        } catch (Throwable $e) {
            $ctx->log->warning('quota clear: ' . $e->getMessage());
        }
        $os->deleteUser($username);
        $os->reloadServices();
        $ctx->log->info("account {$username} terminated");

        return [
            'username' => $username,
            'status'   => 'terminated',
        ];
    }
}
