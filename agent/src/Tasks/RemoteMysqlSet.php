<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Mysql;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * db.remote — remote MySQL access hosts (JSON). No mysql GRANT, no bind-address rewrite.
 *
 * @acp-task db.remote
 */
final class RemoteMysqlSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('db.remote requires PathGuard roots');
        }
        $raw = $payload['hosts'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('hosts must be an array');
        }
        $rows = Mysql::sanitizeRemote($raw);

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setRemoteHosts($username, $rows);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'hosts'    => count($written),
            'status'   => 'ok',
        ];
    }
}
