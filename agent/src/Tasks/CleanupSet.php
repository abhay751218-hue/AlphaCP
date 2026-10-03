<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.cleanup — stale DNS zone cleanup list (JSON). No BIND rewrite.
 *
 * @acp-task dns.cleanup
 */
final class CleanupSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.cleanup requires PathGuard roots');
        }
        $raw = $payload['domains'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('domains must be an array');
        }
        $rows = Dns::sanitizeCleanup($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/cleanup.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('dns cleanup path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::cleanupJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'domains' => count($rows),
            'status'  => 'ok',
        ];
    }
}
