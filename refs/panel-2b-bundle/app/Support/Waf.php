<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel Security suite — ModSecurity (WAF) toggle + ClamAV virus scan.
 * System ops via Process facade so tests can Process::fake() them.
 */
final class Waf
{
    public static function modsecEnabled(): bool
    {
        return Process::run('a2query -m security2')->successful();
    }

    public static function enableModsec(): void
    {
        Process::run('a2enmod security2');
        Process::run('systemctl restart apache2');
    }

    public static function disableModsec(): void
    {
        Process::run('a2dismod security2');
        Process::run('systemctl restart apache2');
    }

    public static function scan(string $path): string
    {
        $result = Process::timeout(120)->run('clamscan -r --quiet ' . escapeshellarg($path));

        return $result->output();
    }
}
