<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\TaskRejectedException;

/**
 * db.user.grant — "Add User To Database": give an existing account MariaDB
 * user ALL PRIVILEGES on one of the account's databases.
 *
 * @acp-task db.user.grant
 */
final class DbUserGrant extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $user = $this->userName($username, (string) ($payload['user'] ?? ''));
        $database = $this->database($username, (string) ($payload['database'] ?? ''));
        $host = $this->host($payload);
        $server = $this->server($ctx);

        if (!in_array($database, $server->accountDatabases($username), true)) {
            throw new TaskRejectedException("database {$database} does not exist on this account");
        }
        if (!$server->userExists($user, $host)) {
            throw new TaskRejectedException("MariaDB user {$user}@{$host} does not exist on this server");
        }

        $granted = false;
        if (in_array($database, $server->grantedDatabases($user, $host), true)) {
            $ctx->log->info("{$user}@{$host} already has privileges on {$database}");
        } else {
            $server->sql(
                'GRANT ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($database) . '.* TO '
                . MysqlServer::account($user, $host) . ";\nFLUSH PRIVILEGES;\n"
            );
            $granted = true;
            $ctx->log->info("granted ALL PRIVILEGES on {$database} to {$user}@{$host}");
        }

        return [
            'username' => $username,
            'user'     => $user,
            'host'     => $host,
            'database' => $database,
            'granted'  => $granted,
            'status'   => 'ok',
        ];
    }
}
