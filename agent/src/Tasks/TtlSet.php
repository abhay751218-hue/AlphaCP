<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.ttl — zone TTL map (JSON). No BIND rewrite.
 *
 * @acp-task dns.ttl
 */
final class TtlSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.ttl requires PathGuard roots');
        }
        $raw = $payload['zones'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('zones must be an array');
        }
        $rows = Dns::sanitizeTtl($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/ttl.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('dns ttl path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::ttlJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'zones'  => count($rows),
            'status' => 'ok',
        ];
    }
}
