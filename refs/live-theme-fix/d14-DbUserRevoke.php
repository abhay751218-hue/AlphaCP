<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;

/**
 * db.user.revoke — grant ka ulta: account MariaDB user ke saare privileges
 * EK database par wapas le lo (user aur database dono bache rehte hain).
 *
 * @acp-task db.user.revoke
 */
final class DbUserRevoke extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $user = $this->userName($username, (string) ($payload['user'] ?? ''));
        $database = $this->database($username, (string) ($payload['database'] ?? ''));
        $host = $this->host($payload);
        $server = $this->server($ctx);

        if (!$server->userExists($user, $host)) {
            throw new TaskRejectedException("MariaDB user {$user}@{$host} does not exist on this server");
        }

        $revoked = false;
        if (!in_array($database, $server->grantedDatabases($user, $host), true)) {
            $ctx->log->info("{$user}@{$host} has no privileges on {$database} (already revoked)");
        } else {
            $server->sql(
                'REVOKE ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($database) . '.* FROM '
                . MysqlServer::account($user, $host) . ";\nFLUSH PRIVILEGES;\n"
            );
            $revoked = true;
            $ctx->log->info("revoked ALL PRIVILEGES on {$database} from {$user}@{$host}");
        }

        return [
            'username' => $username,
            'user'     => $user,
            'host'     => $host,
            'database' => $database,
            'revoked'  => $revoked,
            'status'   => 'ok',
        ];
    }
}
