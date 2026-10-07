<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Ftp;

/**
 * ftp.del — remove a Pure-FTPd virtual user (cPanel "Delete" on an FTP account).
 * Only the PureDB entry goes; the chroot directory's files are left untouched so
 * a customer never loses uploaded data by deleting an FTP login. Classified
 * `mutating` (reversible: recreate the login), not `destructive`.
 *
 * @acp-task ftp.del
 */
final class FtpDel extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));

        $this->ftp($ctx)->delUser($login);

        return [
            'account' => $account,
            'login'   => $login,
            'status'  => 'ok',
        ];
    }
}
