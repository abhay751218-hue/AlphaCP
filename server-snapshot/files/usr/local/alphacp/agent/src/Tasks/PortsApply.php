<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\PortsNginx;
use Alphacp\Agent\TaskContext;
use Alphacp\Agent\TaskRejectedException;

/**
 * ports.apply — OWNER-CTRL port↔panel map ko nginx vhosts par lagana.
 *
 * Panel /ports page save ke baad ye task enqueue karta hai; installer bhi
 * `paneld --run ports.apply` se yahi call karta hai. Idempotent + backup/
 * restore ke saath (nginx -t fail → purani vhosts wapas).
 *
 * @acp-task ports.apply
 */
final class PortsApply implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? 'apply')));
        $ports  = new PortsNginx(
            $ctx->cmd,
            $ctx->log,
            (string) (getenv('ACP_HOME') ?: '/usr/local/alphacp'),
            (string) (getenv('ACP_NGX_ROOT') ?: '/etc/nginx'),
            (string) (getenv('ACP_RC_PLUGINS') ?: '/usr/share/roundcube/plugins'),
        );

        return match ($action) {
            'apply'  => $ports->apply(),
            'status' => $ports->status(),
            default  => throw new TaskRejectedException(
                "ports.apply action '{$action}' nahi chalega (apply/status)"
            ),
        };
    }
}
