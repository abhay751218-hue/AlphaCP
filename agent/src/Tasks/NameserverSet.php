<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.nameserver — nameserver selection (JSON). No BIND rewrite.
 *
 * @acp-task dns.nameserver
 */
final class NameserverSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.nameserver requires PathGuard roots');
        }
        $software = Dns::normalizeNameserverSoftware((string) ($payload['software'] ?? ''));
        $ns1 = Dns::normalizeDomain((string) ($payload['ns1'] ?? ''));
        $ns2 = Dns::normalizeDomain((string) ($payload['ns2'] ?? ''));
        if ($ns1 === $ns2) {
            throw new TaskRejectedException('ns1 and ns2 must differ');
        }

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/nameserver.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('nameserver dns path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::nameserverJson($software, $ns1, $ns2), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'software' => $software,
            'ns1'      => $ns1,
            'ns2'      => $ns2,
            'status'   => 'ok',
        ];
    }
}
