<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * agent.ping — the "hello world" of the task queue.
 * Used by `alphacp doctor`, by the installer self-test and by Step 15 node
 * heartbeats. Read-only, no side effects, no payload.
 */
final class AgentPing implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $ctx->log->info('pong from paneld v' . ACP_AGENT_VERSION);

        return [
            'pong'          => true,
            'agent_version' => ACP_AGENT_VERSION,
            'hostname'      => gethostname() ?: 'unknown',
            'pid'           => getmypid(),
            'php_version'   => PHP_VERSION,
            'time'          => gmdate('c'),
            'timezone'      => date_default_timezone_get(),
        ];
    }
}
