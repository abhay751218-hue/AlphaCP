<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.sync — synchronize DNS records queue (JSON). No BIND rewrite.
 *
 * @acp-task dns.sync
 */
final class SyncSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.sync requires PathGuard roots');
        }
        $raw = $payload['domains'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('domains must be an array');
        }
        $rows = Dns::sanitizeSync($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/sync.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('dns sync path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::syncJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'domains' => count($rows),
            'status'  => 'ok',
        ];
    }
}
