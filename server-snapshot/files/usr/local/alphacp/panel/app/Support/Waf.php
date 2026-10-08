<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel Security suite — ModSecurity (WAF) toggle + ClamAV virus scan,
 * root agent ke zariye (waf.status / waf.enable / waf.disable / security.scan).
 *
 * B1-ext: pehle a2query/a2enmod/a2dismod/clamscan web-FPM se Process se chalte
 * the (proc_open disabled → 500). Ab status/scan synchronous `Paneld::run` se
 * (agent jawab deta hai) aur toggle queue hota hai.
 */
final class Waf
{
    public static function modsecEnabled(): bool
    {
        if (! in_array('waf.status', Paneld::taskTypes(), true)) {
            return false;
        }
        $res = Paneld::run('waf.status', [], 10);

        return (bool) ($res['enabled'] ?? false);
    }

    public static function enableModsec(): void
    {
        Paneld::enqueue('waf.enable', [], 'panel');
    }

    public static function disableModsec(): void
    {
        Paneld::enqueue('waf.disable', [], 'panel');
    }

    public static function scan(string $path): string
    {
        if (! in_array('security.scan', Paneld::taskTypes(), true)) {
            return 'Agent par security.scan available nahi (agent update chahiye).';
        }
        $res = Paneld::run('security.scan', ['path' => $path], 130);
        if ($res === null) {
            return 'Scan timeout / agent se jawab nahi mila.';
        }

        return (string) ($res['output'] ?? '');
    }
}
