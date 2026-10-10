<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.review — WHM review transfers and restores (JSON). No tar, no rsync, no shell, no pipe.
 *
 * @acp-task backup.review
 */
final class BackupReview implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.review requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $status = Backup::normalizeReviewStatus((string) ($payload['status'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/review.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup review path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::reviewJson($username, $status), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'status'   => $status,
            'ok'       => true,
        ];
    }
}
