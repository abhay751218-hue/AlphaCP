<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.park — park a domain onto a target (JSON). No BIND rewrite.
 *
 * @acp-task dns.park
 */
final class ParkSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.park requires PathGuard roots');
        }
        $raw = $payload['parks'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('parks must be an array');
        }
        $rows = Dns::sanitizePark($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/parked.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('parked dns path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::parkJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'parks'  => count($rows),
            'status' => 'ok',
        ];
    }
}
