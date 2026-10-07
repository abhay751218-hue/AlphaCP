<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;

/**
 * db.drop — drop `<account>_<name>` and revoke every account user's privileges
 * on it (so no orphaned grants survive, like cPanel). Destructive: the registry
 * demands `_confirm` before this handler is ever called.
 *
 * @acp-task db.drop
 */
final class DbDrop extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $database = $this->database($username, (string) ($payload['name'] ?? ''));
        $server = $this->server($ctx);

        if (!$server->databaseExists($database)) {
            return [
                'username'      => $username,
                'database'      => $database,
                'dropped'       => false,
                'revoked_users' => [],
                'status'        => 'ok',
            ];
        }

        $sql = '';
        $revoked = [];
        foreach ($server->accountUsers($username) as $row) {
            if (!in_array($database, $row['databases'], true)) {
                continue;
            }
            $sql .= 'REVOKE ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($database) . '.* FROM '
                . MysqlServer::account($row['user'], $row['host']) . ";\n";
            $revoked[] = $row['user'] . '@' . $row['host'];
        }
        $sql .= 'DROP DATABASE ' . MysqlServer::quoteIdentifier($database) . ";\n";
        $server->sql($sql);
        $ctx->log->warning("MariaDB database {$database} dropped (" . count($revoked) . ' grant(s) revoked)');

        return [
            'username'      => $username,
            'database'      => $database,
            'dropped'       => true,
            'revoked_users' => $revoked,
            'status'        => 'ok',
        ];
    }
}
