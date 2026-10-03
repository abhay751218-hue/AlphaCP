<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * cron.set — replace the account crontab (full file). Empty jobs = crontab -r.
 *
 * @acp-task cron.set
 */
final class CronSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('cron.set requires PathGuard roots');
        }

        $jobs = $payload['jobs'] ?? [];
        if (!is_array($jobs)) {
            throw new TaskRejectedException('jobs must be an array');
        }
        $lines = [];
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                throw new TaskRejectedException('invalid job');
            }
            $line = $this->line($job);
            $lines[] = $line;
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        $os->applyCrontab($username, $body);
        $ctx->log->info("crontab {$username} jobs=" . count($lines));

        return [
            'username' => $username,
            'jobs'     => count($lines),
            'status'   => 'active',
        ];
    }

    /** @param array<string, mixed> $job */
    private function line(array $job): string
    {
        $fields = [];
        foreach (['minute', 'hour', 'day', 'month', 'weekday'] as $key) {
            $value = (string) ($job[$key] ?? '');
            if ($value === '' || strlen($value) > 40 || preg_match('/^[0-9*,\/-]+$/', $value) !== 1) {
                throw new TaskRejectedException("invalid cron field {$key}");
            }
            $fields[] = $value;
        }
        $command = (string) ($job['command'] ?? '');
        if ($command === '' || strlen($command) > 500 || strpbrk($command, "\r\n") !== false) {
            throw new TaskRejectedException('invalid cron command');
        }
        return implode(' ', $fields) . ' ' . $command;
    }
}
