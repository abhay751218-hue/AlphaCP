<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * service.restart — WHM "Restart Services": systemctl restart, but ONLY for
 * the registry allowlist. Payload can never reach an arbitrary unit name,
 * and paneld itself is not on the list (the agent must never kill itself).
 */
final class ServiceRestart implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $registry  = acp_task_registry()['service.restart'] ?? [];
        $allowlist = (array) ($registry['services'] ?? []);
        $name      = (string) ($payload['service'] ?? '');

        if (!in_array($name, $allowlist, true)) {
            throw new TaskRejectedException("service not in agent restart allowlist: {$name}");
        }

        $restart = $ctx->cmd->run(['/bin/systemctl', 'restart', $name], 60);
        $active  = $ctx->cmd->run(['/bin/systemctl', 'is-active', $name], 10);
        $state   = $active->stdoutTrimmed();
        $ok      = $restart->ok() && $state === 'active';

        $ctx->log->info("service restart {$name}: " . ($ok ? 'ok' : 'FAILED') . " (state={$state})");

        return [
            'service'      => $name,
            'ok'           => $ok,
            'active'       => $state,
            'error'        => $ok ? null : trim($restart->stderr . ' ' . $state),
            'restarted_at' => gmdate('c'),
        ];
    }
}
