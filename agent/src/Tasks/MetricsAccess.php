<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Metrics;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * metrics.access — account ke Apache access-log se cPanel-jaise stats
 * (Bandwidth / Visitors / Requests / Errors / top pages), root agent side (B2).
 *
 * Log path default: `/var/log/apache2/<account>-access.log` (panel config wala
 * pattern), ya payload `log_path` (tests / custom layout) — dono soorton me
 * PathGuard roots ke andar hona zaroori hai. Log maujood na ho to zero-stats
 * (error nahi — naya account ho sakta hai).
 *
 * @acp-task metrics.access
 */
final class MetricsAccess implements TaskInterface
{
    /** @var list<string> jahan vhost access-log typically hota hai */
    private const CANDIDATES = [
        '/var/log/apache2/{user}-access.log',
        '/var/log/apache2/{user}-access.log.1',
        '/var/log/httpd/{user}-access.log',
        '/var/log/apache2/access.log',
    ];

    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('metrics.access requires PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['account'] ?? '')));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        $fs = new SafeFs($ctx->paths);
        $log = $this->logPath($payload, $ctx, $username, $fs);

        if ($log === null) {
            return [
                'username' => $username,
                'log'      => null,
                'stats'    => ['bytes' => 0, 'visitors' => 0, 'requests' => 0, 'errors' => 0, 'top' => [], 'tail' => false],
                'status'   => 'ok',
            ];
        }

        return [
            'username' => $username,
            'log'      => $log,
            'stats'    => Metrics::parseFile($log),
            'status'   => 'ok',
        ];
    }

    /** Payload log_path (validated) ya pehla maujood default candidate. */
    private function logPath(array $payload, TaskContext $ctx, string $username, SafeFs $fs): ?string
    {
        $custom = trim((string) ($payload['log_path'] ?? ''));
        if ($custom !== '') {
            return $fs->assert($custom);
        }
        foreach (self::CANDIDATES as $pattern) {
            $path = str_replace('{user}', $username, $pattern);
            // /var/log roots me na ho to PathGuard allow nahi karega — chup-chaap skip
            try {
                $safe = $fs->assert($path);
            } catch (\Throwable) {
                continue;
            }
            if ($fs->isFile($safe)) {
                return $safe;
            }
        }

        return null;
    }
}
