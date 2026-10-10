<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guards for the Security-suite tasks (cPanel IP Blocker / ModSecurity /
 * ClamAV — audit B1-ext).
 *
 *  - IP sirf valid v4/v6 (filter_var), koi shell meta nahi (argv-only anyway),
 *  - scan path PathGuard roots ke andar (account homes etc.),
 *  - ufw/a2enmod/a2dismod/a2query/clamscan sirf allowlisted CommandRunner se —
 *    web-FPM proc_open disabled hone se ye sab pehle HTTP 500 dete the.
 */
abstract class SecurityTask implements TaskInterface
{
    /** Valid IPv4/IPv6 address, warna reject. */
    protected function ip(string $raw): string
    {
        $ip = trim($raw);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new TaskRejectedException("invalid IP address: {$raw}");
        }

        return $ip;
    }

    /** Scan target: PathGuard ke andar ka dir/file. */
    protected function path(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('security.scan requires PathGuard roots');
        }
        $path = trim((string) ($payload['path'] ?? ''));
        if ($path === '') {
            throw new TaskRejectedException('scan ke liye path chahiye');
        }

        return (new SafeFs($ctx->paths))->assert($path);
    }

    /** First existing binary path (default for fake/test envs). @param list<string> $candidates */
    protected function bin(array $candidates): string
    {
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return $candidates[0];
    }
}
