<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.forward — domain forwarding map (JSON). No BIND rewrite.
 *
 * @acp-task dns.forward
 */
final class ForwardSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.forward requires PathGuard roots');
        }
        $raw = $payload['forwards'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('forwards must be an array');
        }
        $rows = Dns::sanitizeForward($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/forward.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('dns forward path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::forwardJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'forwards' => count($rows),
            'status'   => 'ok',
        ];
    }
}
