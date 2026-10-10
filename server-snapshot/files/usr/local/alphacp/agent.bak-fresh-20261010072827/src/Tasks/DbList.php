<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;


/**
 * db.list — what MariaDB actually holds for this account (databases, users and
 * the databases each user can reach). Read-only, so the panel/doctor can verify
 * provisioning without trusting the panel's own bookkeeping.
 *
 * @acp-task db.list
 */
final class DbList extends DbTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $server = $this->server($ctx);

        return [
            'username'  => $username,
            'databases' => $server->accountDatabases($username),
            'users'     => $server->accountUsers($username),
            'status'    => 'ok',
        ];
    }
}
