<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.restoration — WHM full/partial/per-account restore (JSON). No tar, no shell, no pipe.
 *
 * @acp-task backup.restoration
 */
final class BackupRestoration implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.restoration requires PathGuard roots');
        }
        $mode = Backup::normalizeMode((string) ($payload['mode'] ?? ''));
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/restoration.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup restoration path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::restorationJson($mode, $username), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'mode'     => $mode,
            'username' => $username,
            'status'   => 'ok',
        ];
    }
}
