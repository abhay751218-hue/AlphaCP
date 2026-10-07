<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel IP Blocker backend — ufw/iptables deny rules.
 * System calls via Process facade so tests can Process::fake() them.
 */
final class Firewall
{
    public static function block(string $ip): void
    {
        Process::run(['ufw', 'deny', 'from', $ip]);
    }

    public static function unblock(string $ip): void
    {
        Process::run(['ufw', 'delete', 'deny', 'from', $ip]);
    }
}
