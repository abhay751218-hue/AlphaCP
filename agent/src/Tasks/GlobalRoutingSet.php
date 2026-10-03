<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Files;
use Alphacp\Agent\Mail;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * mail.globalrouting — WHM email routing (JSON). No Exim rewrite.
 *
 * @acp-task mail.globalrouting
 */
final class GlobalRoutingSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.globalrouting requires PathGuard roots');
        }
        $raw = $payload['routes'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('routes must be an array');
        }
        $rows = Mail::sanitizeRouting($raw);

        $root = getenv('ACP_STATE_ROOT') ?: '/usr/local/alphacp';
        $root = rtrim($root, '/');
        $fs = new SafeFs($ctx->paths);
        $dir = Files::resolve($root, 'etc/mail');
        $file = Files::resolve($root, 'etc/mail/global-routing.json');
        try {
            if (is_link($dir) || is_link($file)) {
                throw new RuntimeException('global routing path is a symlink');
            }
            $fs->mkdir($dir, 0750);
            $fs->write($file, Mail::routingJson($rows), 0640);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'routes' => count($rows),
            'status' => 'ok',
        ];
    }
}
