<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * waf.status — ModSecurity (security2) enabled hai ya nahi (`a2query -m security2`).
 * Read-only; panel ka Security Tools page isi se toggle-state dikhata hai.
 *
 * @acp-task waf.status
 */
final class WafStatus extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $r = $ctx->cmd->run([$this->bin(['/usr/sbin/a2query', '/usr/bin/a2query']), '-m', 'security2'], 15);

        return [
            'enabled' => $r->ok(),
            'detail'  => substr(trim($r->stdout), 0, 500),
            'status'  => 'ok',
        ];
    }
}
