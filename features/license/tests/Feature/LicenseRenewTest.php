<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\License\LicenseClient;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LicenseRenewTest extends TestCase
{
    public function test_renew_command_issues_trial(): void
    {
        $this->assertSame(0, Artisan::call('alphacp:license:renew', ['--days' => 365]));

        $status = (new LicenseClient())->status();
        $this->assertSame('trial', $status['state']);
        $this->assertGreaterThan(360, $status['days_left']);
    }

    public function test_renew_overwrites_existing_record(): void
    {
        (new LicenseClient())->renewTrial(15);

        $this->assertSame(0, Artisan::call('alphacp:license:renew', ['--days' => 200]));

        $status = (new LicenseClient())->status();
        $this->assertSame('trial', $status['state']);
        $this->assertGreaterThan(195, $status['days_left']);
    }
}
