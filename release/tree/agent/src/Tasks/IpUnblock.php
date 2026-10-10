<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * security.ipUnblock — cPanel "IP Blocker" delete: `ufw delete deny from <ip>`.
 *
 * @acp-task security.ipUnblock
 */
final class IpUnblock extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $ip = $this->ip((string) ($payload['ip'] ?? ''));
        $r = $ctx->cmd->run([$this->bin(['/usr/sbin/ufw', '/sbin/ufw']), 'delete', 'deny', 'from', $ip], 30);
        if (!$r->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('ufw delete deny failed: ' . substr(trim($r->stderr), 0, 200));
        }
        $ctx->log->info("ufw delete deny from {$ip}");

        return ['ip' => $ip, 'action' => 'unblock', 'status' => 'ok'];
    }
}
