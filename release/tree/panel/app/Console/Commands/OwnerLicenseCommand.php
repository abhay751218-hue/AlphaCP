<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\License\LicenseClient;
use Illuminate\Console\Command;

/**
 * Owner-server ko lifetime + unlimited license lagao.
 *
 *     php artisan alphacp:license:owner
 *
 * Ye owner ke APNE server ke liye hai: fingerprint-bound offline record
 * (tier `owner`, max_accounts -1, expires_at null). Customer licenses
 * license-server (/license-server) se alag issue hote hain.
 */
final class OwnerLicenseCommand extends Command
{
    protected $signature = 'alphacp:license:owner';

    protected $description = 'Owner-server lifetime + unlimited license (offline, fingerprint-bound)';

    public function handle(LicenseClient $client): int
    {
        $status = $client->installOwnerLicense();

        $this->table(
            ['Field', 'Value'],
            [
                ['State', (string) $status['state']],
                ['Label', (string) $status['label']],
                ['Tier', (string) $status['tier']],
                ['Max accounts', $status['max_accounts'] === -1 ? 'UNLIMITED' : (string) $status['max_accounts']],
                ['Expires', (string) $status['expires_at']],
                ['License UID', (string) $status['license_uid']],
            ],
        );

        if (($status['state'] ?? '') !== 'active') {
            $this->error('Owner license apply nahi hui: ' . (string) $status['message']);

            return self::FAILURE;
        }

        $this->info('Owner license LIFETIME + UNLIMITED set ho gayi.');

        return self::SUCCESS;
    }
}
