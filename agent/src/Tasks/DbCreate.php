<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;

/**
 * db.create — create the real MariaDB database `<account>_<name>` (utf8mb4).
 * Idempotent: an existing database is reported, never recreated.
 *
 * @acp-task db.create
 */
final class DbCreate extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $database = $this->database($username, (string) ($payload['name'] ?? ''));
        $server = $this->server($ctx);

        $created = false;
        if ($server->databaseExists($database)) {
            $ctx->log->info("MariaDB database {$database} already exists; nothing created");
        } else {
            $server->sql(
                'CREATE DATABASE ' . MysqlServer::quoteIdentifier($database)
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
            $created = true;
            $ctx->log->info("MariaDB database {$database} created");
        }

        return [
            'username' => $username,
            'database' => $database,
            'created'  => $created,
            'status'   => 'ok',
        ];
    }
}
