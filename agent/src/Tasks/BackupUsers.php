<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.users — WHM backup user selection (JSON). No tar, no shell, no pipe.
 *
 * @acp-task backup.users
 */
final class BackupUsers implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.users requires PathGuard roots');
        }
        $raw = $payload['users'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('users must be an array');
        }
        $users = Backup::sanitizeUsers($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/users.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup users path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::usersJson($users), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'users'  => $users,
            'status' => 'ok',
        ];
    }
}
