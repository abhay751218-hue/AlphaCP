<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.transfer — WHM cPanel→AlphaCP transfer (JSON). No tar, no rsync, no shell, no pipe.
 *
 * @acp-task backup.transfer
 */
final class BackupTransfer implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.transfer requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $source = Backup::normalizeTransferSource((string) ($payload['source'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/transfer.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup transfer path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::transferJson($username, $source), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'source'   => $source,
            'status'   => 'ok',
        ];
    }
}
