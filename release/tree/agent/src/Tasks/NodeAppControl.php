<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * node.control — PM2-style start/stop/restart/remove, sirf apni
 * `alphacp-node-<user>-<app>.service` units par (prefix fixed — koi system
 * service kabhi touch nahi ho sakti). remove = stop + disable + unit delete
 * (app files /home me rehti hain).
 */
final class NodeAppControl extends NodeTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $app      = $this->appName($payload);
        $action   = (string) ($payload['action'] ?? '');

        $fs = $this->fs($ctx);
        if (!$fs->exists($this->unitPath($username, $app))) {
            throw new TaskRejectedException("app '{$app}' registered nahi hai (unit missing) — pehle setup karo");
        }
        $unit = $this->unit($username, $app);

        if ($action === 'remove') {
            $this->systemctl($ctx, ['stop', $unit], 30);
            $this->systemctl($ctx, ['disable', $unit], 30);
            $fs->unlink($this->unitPath($username, $app));
            $this->systemctl($ctx, ['daemon-reload'], 30);
            $ctx->log->info("node app {$username}/{$app} removed (files kept in home)");

            return ['username' => $username, 'app' => $app, 'action' => 'remove', 'removed' => true];
        }

        $r = $this->systemctl($ctx, [$action, $unit], 45);
        if (!$r->ok()) {
            throw new TaskRejectedException("systemctl {$action} fail: " . substr(trim($r->stderr), 0, 300));
        }
        usleep(700_000);
        $ctx->log->info("node app {$username}/{$app}: {$action} ok");

        return [
            'username' => $username,
            'app'      => $app,
            'action'   => $action,
            'state'    => $this->unitState($ctx, $username, $app),
        ];
    }
}
