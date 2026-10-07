<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Dns;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.templates — zone templates (JSON). No BIND rewrite.
 *
 * @acp-task dns.templates
 */
final class TemplatesSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.templates requires PathGuard roots');
        }
        $raw = $payload['templates'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('templates must be an array');
        }
        $rows = Dns::sanitizeTemplates($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/dns');
        $file = Files::resolve($root, 'etc/dns/templates.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('dns templates path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Dns::templatesJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'templates' => count($rows),
            'status'    => 'ok',
        ];
    }
}
