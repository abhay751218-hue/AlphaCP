<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * security.scan — ClamAV virus scan (`clamscan -r --quiet <path>`), PathGuard ke
 * andar ka path. ClamAV ka exit 1 = "virus mila" (ye failure nahi, result hai);
 * exit 0 = clean; baaki exit codes asli error hain.
 *
 * @acp-task security.scan
 */
final class VirusScan extends SecurityTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $path = $this->path($payload, $ctx);
        $r = $ctx->cmd->run(
            [$this->bin(['/usr/bin/clamscan', '/usr/sbin/clamscan']), '-r', '--quiet', $path],
            120
        );

        if (!in_array($r->exitCode, [0, 1], true) || $r->timedOut) {
            throw new \Alphacp\Agent\TaskRejectedException('clamscan error: ' . substr(trim($r->stderr), 0, 200));
        }
        $ctx->log->info("clamscan {$path} exit={$r->exitCode}");

        return [
            'path'      => $path,
            'infected'  => $r->exitCode === 1,
            'output'    => substr($r->stdout, 0, 20000),
            'status'    => 'ok',
        ];
    }
}
