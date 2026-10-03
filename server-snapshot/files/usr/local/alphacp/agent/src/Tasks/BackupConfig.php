<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Backup;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * backup.config — WHM backup schedule/retention (JSON). No tar, no shell, no pipe.
 *
 * @acp-task backup.config
 */
final class BackupConfig implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.config requires PathGuard roots');
        }
        $schedule = Backup::normalizeSchedule((string) ($payload['schedule'] ?? ''));
        $retention = Backup::normalizeRetention($payload['retention'] ?? null);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/backup');
        $file = Files::resolve($root, 'etc/backup/config.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('backup config path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Backup::configJson($schedule, $retention), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'schedule'  => $schedule,
            'retention' => $retention,
            'status'    => 'ok',
        ];
    }
}
