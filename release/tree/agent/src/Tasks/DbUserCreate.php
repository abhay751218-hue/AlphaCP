<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\TaskRejectedException;

/**
 * db.user.create — create the MariaDB user `<account>_<user>` and grant it
 * ALL PRIVILEGES on the databases named in the payload.
 *
 * The password never reaches argv: it is escaped into the SQL script that the
 * client reads from stdin. An existing user is reported (and its password is
 * left alone); unknown/foreign database names are refused before any SQL runs.
 *
 * @acp-task db.user.create
 */
final class DbUserCreate extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $user = $this->userName($username, (string) ($payload['user'] ?? ''));
        $password = MysqlServer::password((string) ($payload['password'] ?? ''));
        $host = $this->host($payload);
        $server = $this->server($ctx);

        $raw = $payload['databases'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('databases must be an array');
        }
        if (count($raw) > 50) {
            throw new TaskRejectedException('too many databases for one user (50 max)');
        }
        $grants = [];
        foreach ($raw as $row) {
            $grants[] = $this->database($username, (string) $row);
        }
        $existing = $server->accountDatabases($username);
        foreach ($grants as $database) {
            if (!in_array($database, $existing, true)) {
                throw new TaskRejectedException("database {$database} does not exist on this account");
            }
        }

        $created = false;
        if ($server->userExists($user, $host)) {
            $ctx->log->info("MariaDB user {$user}@{$host} already exists; password untouched");
        } else {
            $sql = 'CREATE USER ' . MysqlServer::account($user, $host)
                . ' IDENTIFIED BY ' . MysqlServer::literal($password, 'password') . ";\n";
            foreach ($grants as $database) {
                $sql .= 'GRANT ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($database) . '.* TO '
                    . MysqlServer::account($user, $host) . ";\n";
            }
            $sql .= "FLUSH PRIVILEGES;\n";
            $server->sql($sql);
            $created = true;
            $ctx->log->info("MariaDB user {$user}@{$host} created with " . count($grants) . ' grant(s)');
        }

        return [
            'username'  => $username,
            'user'      => $user,
            'host'      => $host,
            'created'   => $created,
            'databases' => $grants,
            'status'    => 'ok',
        ];
    }
}
