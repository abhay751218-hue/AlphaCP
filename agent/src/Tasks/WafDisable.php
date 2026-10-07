<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * waf.disable — ModSecurity off: `a2dismod security2` + `systemctl restart apache2`.
 *
 * @acp-task waf.disable
 */
final class WafDisable extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $dis = $ctx->cmd->run([$this->bin(['/usr/sbin/a2dismod', '/usr/bin/a2dismod']), 'security2'], 30);
        if (!$dis->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('a2dismod security2 failed: ' . substr(trim($dis->stderr), 0, 200));
        }
        $rs = $ctx->cmd->run(['/usr/bin/systemctl', 'restart', 'apache2'], 60);
        if (!$rs->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('apache2 restart failed: ' . substr(trim($rs->stderr), 0, 200));
        }
        $ctx->log->info('modsecurity disabled (security2) + apache2 restart');

        return ['enabled' => false, 'status' => 'ok'];
    }
}
