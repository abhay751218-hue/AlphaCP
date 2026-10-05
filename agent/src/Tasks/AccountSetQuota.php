<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * account.setQuota — disk quota (MB, -1 = unlimited).
 *
 * @acp-task account.setQuota
 */
final class AccountSetQuota implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('account.setQuota requires PathGuard roots');
        }

        $quotaMb = (int) $payload['quota_mb'];
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $result = $os->setQuota($username, $quotaMb);
        $ctx->log->info("quota {$username} = {$quotaMb} ({$result})");

        return [
            'username' => $username,
            'quota_mb' => $quotaMb,
            'result'   => $result,
        ];
    }
}
