<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Backup;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.wizard — guided backup/restore plan (JSON). No tar, no shell, no pipe.
 *
 * @acp-task backup.wizard
 */
final class BackupWizard implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.wizard requires PathGuard roots');
        }
        $action = Backup::normalizeAction((string) ($payload['action'] ?? ''));
        $scope = Backup::normalizeScope((string) ($payload['scope'] ?? ''));

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setBackupWizard($username, $action, $scope);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'action'   => $written['action'],
            'scope'    => $written['scope'],
            'status'   => 'ok',
        ];
    }
}
