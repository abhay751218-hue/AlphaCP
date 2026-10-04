<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Mysql;
use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guard for the real `db.*` tasks (S8).
 *
 * Every task in this family:
 *  - only touches MariaDB for an existing AlphaCP account (Linux user check),
 *  - only touches names that carry the account prefix (`<user>_<suffix>`),
 *  - talks SQL through MysqlServer (stdin, socket auth, validated + quoted
 *    identifiers) — no task ever builds a shell string.
 */
abstract class DbTask implements TaskInterface
{
    protected function account(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('db tasks require PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
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

    /** Database/user name suffix as written by the customer (`shop`, `wp_admin`). */
    protected function suffix(string $raw, string $label): string
    {
        $value = strtolower(trim($raw));
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $value) !== 1) {
            throw new TaskRejectedException("invalid {$label}: letters/numbers/_, 1-16 chars, letter first");
        }

        return $value;
    }

    protected function database(string $username, string $raw): string
    {
        return MysqlServer::accountName($username, $this->suffix($raw, 'database name'), 'database name');
    }

    protected function userName(string $username, string $raw): string
    {
        return MysqlServer::accountName($username, $this->suffix($raw, 'user name'), 'user name');
    }

    protected function host(array $payload): string
    {
        $host = strtolower(trim((string) ($payload['host'] ?? 'localhost')));
        if ($host === '' || $host === 'localhost') {
            return 'localhost';
        }

        return Mysql::normalizeHost($host); // IPv4 / FQDN / %
    }

    protected function server(TaskContext $ctx): MysqlServer
    {
        return new MysqlServer($ctx->cmd, $ctx->log);
    }
}
