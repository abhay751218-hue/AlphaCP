<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Ftp;

/**
 * ftp.passwd — change a Pure-FTPd virtual user's password (cPanel "Change
 * Password" on an FTP account). The new password arrives on stdin only.
 *
 * @acp-task ftp.passwd
 */
final class FtpPasswd extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));
        $password = Ftp::password((string) ($payload['password'] ?? ''));

        $this->ftp($ctx)->passwd($login, $password);

        return [
            'account' => $account,
            'login'   => $login,
            'status'  => 'ok',
        ];
    }
}
