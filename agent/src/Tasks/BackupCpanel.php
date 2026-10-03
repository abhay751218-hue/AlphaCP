<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.cpanel — WHM transfer/restore a cPanel account (JSON). No tar, no rsync, no shell, no pipe.
 *
 * @acp-task backup.cpanel
 */
final class BackupCpanel implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.cpanel requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $action = Backup::normalizeCpanelAction((string) ($payload['action'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/cpanel-account.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup cpanel path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::cpanelJson($username, $action), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'action'   => $action,
            'status'   => 'ok',
        ];
    }
}
