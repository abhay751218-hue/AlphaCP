<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * security.ipBlock — cPanel "IP Blocker": `ufw deny from <ip>` (root agent side).
 *
 * @acp-task security.ipBlock
 */
final class IpBlock extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $ip = $this->ip((string) ($payload['ip'] ?? ''));
        $r = $ctx->cmd->run([$this->bin(['/usr/sbin/ufw', '/sbin/ufw']), 'deny', 'from', $ip], 30);
        if (!$r->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('ufw deny failed: ' . substr(trim($r->stderr), 0, 200));
        }
        $ctx->log->info("ufw deny from {$ip}");

        return ['ip' => $ip, 'action' => 'block', 'status' => 'ok'];
    }
}
