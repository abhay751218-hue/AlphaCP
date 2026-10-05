<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.filedir — WHM file/directory restoration (JSON). No tar, no shell, no pipe.
 *
 * @acp-task backup.filedir
 */
final class BackupFiledir implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.filedir requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $path = Backup::normalizeFiledirPath((string) ($payload['path'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/filedir.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup filedir path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::filedirJson($username, $path), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'path'     => $path,
            'status'   => 'ok',
        ];
    }
}
