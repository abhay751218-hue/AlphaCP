<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * service.status — systemd state of the hosting stack services.
 * Read-only. Service names are validated against the registry allowlist,
 * so the payload can never be used to run an arbitrary systemctl call.
 */
final class ServiceStatus implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $registry  = acp_task_registry()['service.status'] ?? [];
        $allowlist = (array) ($registry['services'] ?? []);
        $wanted    = $payload['services'] ?? $allowlist;

        $services = [];
        foreach ((array) $wanted as $name) {
            if (!in_array($name, $allowlist, true)) {
                throw new TaskRejectedException("service not in agent allowlist: {$name}");
            }

            $active  = $ctx->cmd->run(['/bin/systemctl', 'is-active', $name], 10);
            $enabled = $ctx->cmd->run(['/bin/systemctl', 'is-enabled', $name], 10);

            $services[$name] = [
                'active'  => $active->stdoutTrimmed(),
                'enabled' => $enabled->stdoutTrimmed(),
            ];
        }

        $running = count(array_filter($services, static fn (array $s): bool => $s['active'] === 'active'));
        $ctx->log->info("checked " . count($services) . " services — {$running} active");

        return ['services' => $services, 'active_count' => $running, 'collected_at' => gmdate('c')];
    }
}
