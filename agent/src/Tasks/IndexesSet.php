<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Indexes;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * indexes.set — Apache directory listing mode for the account home.
 *
 * @acp-task indexes.set
 */
final class IndexesSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('indexes.set requires PathGuard roots');
        }
        try {
            $mode = Indexes::normalize((string) ($payload['mode'] ?? 'off'));
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $os->setIndexes($username, $mode);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info("indexes {$mode} for {$username}");

        return [
            'username' => $username,
            'mode'     => $mode,
            'status'   => 'active',
        ];
    }
}
