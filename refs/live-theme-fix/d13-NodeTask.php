<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\CommandResult;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guard for the `node.*` app-manager tasks (D13, PM2-style via systemd).
 *
 * Har task:
 *  - sirf existing AlphaCP account (Linux user + GECOS marker) ke liye chalta hai,
 *  - app ka naam strict pattern se validate hota hai,
 *  - systemd unit ka naam HAMESHA `alphacp-node-<user>-<app>.service` hota hai —
 *    prefix fixed hai, isliye paneld/nginx jaisi system unit kabhi touch nahi ho sakti.
 */
abstract class NodeTask implements TaskInterface
{
    protected function account(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('node tasks require PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        return $username;
    }

    protected function appName(array $payload): string
    {
        $name = strtolower(trim((string) ($payload['name'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid app name: letters/numbers, 1-16 chars, letter first');
        }

        return $name;
    }

    protected function fs(TaskContext $ctx): SafeFs
    {
        return new SafeFs($ctx->paths);
    }

    protected function appsRoot(string $username): string
    {
        return AccountPaths::fromEnv()->home($username) . '/nodeapps';
    }

    protected function appDir(string $username, string $app): string
    {
        return $this->appsRoot($username) . '/' . $app;
    }

    protected function unit(string $username, string $app): string
    {
        return 'alphacp-node-' . $username . '-' . $app . '.service';
    }

    protected function unitPath(string $username, string $app): string
    {
        return '/etc/systemd/system/' . $this->unit($username, $app);
    }

    protected function systemctl(TaskContext $ctx, array $args, int $timeout = 30): CommandResult
    {
        return $ctx->cmd->run(array_merge(['/usr/bin/systemctl'], $args), $timeout);
    }

    /** ActiveState/SubState/MainPID/start time of one app unit. */
    protected function unitState(TaskContext $ctx, string $username, string $app): array
    {
        $r = $this->systemctl($ctx, [
            'show', $this->unit($username, $app),
            '--property=ActiveState,SubState,MainPID,ExecMainStartTimestamp',
        ], 15);
        $state = ['active' => 'unknown', 'sub' => '', 'pid' => 0, 'since' => ''];
        foreach (preg_split('/\R/', $r->stdout) ?: [] as $line) {
            [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
            match ($k) {
                'ActiveState'           => $state['active'] = $v,
                'SubState'              => $state['sub'] = $v,
                'MainPID'               => $state['pid'] = (int) $v,
                'ExecMainStartTimestamp' => $state['since'] = $v,
                default                 => null,
            };
        }

        return $state;
    }

    /** First existing node binary (install guaranteed by d13 installer). */
    protected function nodeBin(): string
    {
        foreach (['/usr/bin/node', '/usr/local/bin/node'] as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }
        throw new TaskRejectedException('node binary nahi mila — server par Node.js install karo');
    }
}
