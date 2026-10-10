<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;

/**
 * db.pmaSignon — phpMyAdmin one-click SSO ke liye `pma_<account>` MariaDB
 * user rotate karta hai: har call par NAYA random password, grants SIRF us
 * account ke databases par. Password sirf task result me jata hai (panel use
 * encrypt karke 10-minute token file me rakhta hai) — kahin store nahi hota.
 */
final class PmaSignon extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $server   = $this->server($ctx);

        $user     = 'pma_' . $username;
        $host     = 'localhost';
        $password = bin2hex(random_bytes(16)); // 32 hex chars — password() rules pass

        $stmt = $server->userExists($user, $host) ? 'ALTER USER ' : 'CREATE USER ';
        $sql  = $stmt . MysqlServer::account($user, $host)
            . ' IDENTIFIED BY ' . MysqlServer::literal(MysqlServer::password($password), 'password') . ";\n";

        $databases = $server->accountDatabases($username);
        foreach ($databases as $db) {
            $sql .= 'GRANT ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($db) . '.* TO '
                . MysqlServer::account($user, $host) . ";\n";
        }
        $sql .= "FLUSH PRIVILEGES;\n";
        $server->sql($sql);

        $ctx->log->info("pma signon rotated for {$username}: {$user}@{$host}, " . count($databases) . ' db grants');

        return [
            'user'       => $user,
            'password'   => $password,
            'host'       => $host,
            'databases'  => $databases,
            'rotated_at' => gmdate('c'),
        ];
    }
}
