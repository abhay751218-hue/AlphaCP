<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel IP Blocker backend — ufw deny rules, root agent ke zariye.
 *
 * B1-ext: pehle web-FPM se Process facade ufw chalata tha (proc_open disabled →
 * HTTP 500). Ab panel sirf agent task queue karta hai (security.ipBlock /
 * security.ipUnblock); asli kaam root agent karta hai.
 */
final class Firewall
{
    public static function block(string $ip): void
    {
        Paneld::enqueue('security.ipBlock', ['ip' => $ip], 'panel');
    }

    public static function unblock(string $ip): void
    {
        Paneld::enqueue('security.ipUnblock', ['ip' => $ip], 'panel');
    }
}
