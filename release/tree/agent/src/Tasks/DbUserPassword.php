<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\TaskRejectedException;

/**
 * db.user.password — cPanel "Change Password": set a new password for an
 * existing account MariaDB user. The new password comes in on stdin with the
 * ALTER USER statement; it never appears in argv and is never stored.
 *
 * @acp-task db.user.password
 */
final class DbUserPassword extends DbTask
{
    public function handle(array $payload, \Alphacp\Agent\Tasks\TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $user = $this->userName($username, (string) ($payload['user'] ?? ''));
        $password = MysqlServer::password((string) ($payload['password'] ?? ''));
        $host = $this->host($payload);
        $server = $this->server($ctx);

        if (!$server->userExists($user, $host)) {
            throw new TaskRejectedException("MariaDB user {$user}@{$host} does not exist on this server");
        }

        $server->sql(
            'ALTER USER ' . MysqlServer::account($user, $host)
            . ' IDENTIFIED BY ' . MysqlServer::literal($password, 'password') . ";\nFLUSH PRIVILEGES;\n"
        );
        $ctx->log->info("MariaDB password reset for {$user}@{$host}");

        return [
            'username' => $username,
            'user'     => $user,
            'host'     => $host,
            'changed'  => true,
            'status'   => 'ok',
        ];
    }
}
