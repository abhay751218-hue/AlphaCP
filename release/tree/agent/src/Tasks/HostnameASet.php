<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.hostname — A record for the server hostname (JSON). No BIND rewrite.
 *
 * @acp-task dns.hostname
 */
final class HostnameASet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.hostname requires PathGuard roots');
        }
        $hostname = Dns::normalizeDomain((string) ($payload['hostname'] ?? ''));
        $ip = Dns::normalizeValue('A', (string) ($payload['ip'] ?? ''));

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/hostname.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('hostname dns path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::hostnameJson($hostname, $ip), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'hostname' => $hostname,
            'ip'       => $ip,
            'status'   => 'ok',
        ];
    }
}
