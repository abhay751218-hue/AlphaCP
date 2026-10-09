<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;


/**
 * db.user.drop — remove `<account>_<user>` from MariaDB (every host row the
 * account owns for that name, or just the one in the payload). Destructive:
 * the registry demands `_confirm` first.
 *
 * @acp-task db.user.drop
 */
final class DbUserDrop extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $user = $this->userName($username, (string) ($payload['user'] ?? ''));
        $onlyHost = trim((string) ($payload['host'] ?? ''));
        $server = $this->server($ctx);

        $targets = [];
        foreach ($server->accountUsers($username) as $row) {
            if ($row['user'] !== $user) {
                continue;
            }
            if ($onlyHost !== '' && $row['host'] !== $onlyHost) {
                continue;
            }
            $targets[] = $row;
        }

        if ($targets === []) {
            return [
                'username' => $username,
                'user'     => $user,
                'dropped'  => [],
                'status'   => 'ok',
            ];
        }

        $sql = '';
        $dropped = [];
        foreach ($targets as $row) {
            $sql .= 'DROP USER ' . \Alphacp\Agent\MysqlServer::account($row['user'], $row['host']) . ";\n";
            $dropped[] = $row['user'] . '@' . $row['host'];
        }
        $sql .= "FLUSH PRIVILEGES;\n";
        $server->sql($sql);
        $ctx->log->warning("MariaDB user(s) dropped: " . implode(', ', $dropped));

        return [
            'username' => $username,
            'user'     => $user,
            'dropped'  => $dropped,
            'status'   => 'ok',
        ];
    }
}
