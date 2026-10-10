<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Ftp;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * ftp.add — create a Pure-FTPd virtual user `<account>_<suffix>` chrooted to
 * `<account-home>/ftp/<suffix>` (cPanel "FTP Accounts"). Idempotent-ish: the
 * chroot dir is created if missing; pure-pw useradd fails cleanly if the login
 * already exists (the panel pre-checks uniqueness in its own DB).
 *
 * @acp-task ftp.add
 */
final class FtpAdd extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));
        $password = Ftp::password((string) ($payload['password'] ?? ''));

        $home = rtrim(trim((string) ($payload['home'] ?? '')), '/');
        if ($home === '') {
            throw new TaskRejectedException('FTP home is required');
        }
        $home = $ctx->paths->assert($home);
        $accountHome = rtrim(AccountPaths::fromEnv()->home($account), '/');
        if (!str_starts_with($home, $accountHome . '/')) {
            throw new TaskRejectedException('FTP home must live inside the account home');
        }

        $fs = new SafeFs($ctx->paths);
        if (!$fs->isDir($home)) {
            $fs->mkdir($home, 0750);
            $fs->chownName($home, $account);
        }

        $this->ftp($ctx)->addUser($login, $account, $password, $home);

        return [
            'account' => $account,
            'login'   => $login,
            'home'    => $home,
            'status'  => 'ok',
        ];
    }
}
