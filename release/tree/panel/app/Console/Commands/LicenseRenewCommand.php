<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\License\LicenseClient;
use Illuminate\Console\Command;

/**
 * Owner-server keep-alive: re-issue the offline local trial.
 * Customer websites/email/DNS/backups are NEVER affected by license state.
 */
final class LicenseRenewCommand extends Command
{
    protected $signature = 'alphacp:license:renew {--days=15 : trial days to issue}';

    protected $description = 'Re-issue local trial license (owner keep-alive; sellable signed licenses come from the license server).';

    public function handle(LicenseClient $client): int
    {
        $status = $client->renewTrial((int) $this->option('days'));

        $this->info(sprintf(
            'License state=%s expires=%s days_left=%s',
            $status['state'],
            $status['expires_at'],
            $status['days_left'],
        ));

        return self::SUCCESS;
    }
}
