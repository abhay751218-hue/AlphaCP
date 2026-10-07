<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * waf.enable — ModSecurity on: `a2enmod security2` + `systemctl restart apache2`.
 *
 * @acp-task waf.enable
 */
final class WafEnable extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $en = $ctx->cmd->run([$this->bin(['/usr/sbin/a2enmod', '/usr/bin/a2enmod']), 'security2'], 30);
        if (!$en->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('a2enmod security2 failed: ' . substr(trim($en->stderr), 0, 200));
        }
        $rs = $ctx->cmd->run(['/usr/bin/systemctl', 'restart', 'apache2'], 60);
        if (!$rs->ok()) {
            throw new \Alphacp\Agent\TaskRejectedException('apache2 restart failed: ' . substr(trim($rs->stderr), 0, 200));
        }
        $ctx->log->info('modsecurity enabled (security2) + apache2 restart');

        return ['enabled' => true, 'status' => 'ok'];
    }
}
