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
 * db.set — prefixed database names (JSON). No mysql binary, no GRANT.
 *
 * @acp-task db.set
 */
final class MysqlSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('db.set requires PathGuard roots');
        }
        $raw = $payload['databases'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('databases must be an array');
        }
        $rows = Mysql::sanitize($raw, $username);

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setDatabases($username, $rows);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username'  => $username,
            'databases' => count($written),
            'status'    => 'ok',
        ];
    }
}
