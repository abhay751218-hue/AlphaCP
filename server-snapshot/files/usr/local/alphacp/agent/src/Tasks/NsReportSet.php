<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.nsreport — nameserver record report (JSON). No BIND rewrite.
 *
 * @acp-task dns.nsreport
 */
final class NsReportSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.nsreport requires PathGuard roots');
        }
        $raw = $payload['records'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('records must be an array');
        }
        $rows = Dns::sanitizeNsReport($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/ns-report.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('ns report path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::nsReportJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'records' => count($rows),
            'status'  => 'ok',
        ];
    }
}
