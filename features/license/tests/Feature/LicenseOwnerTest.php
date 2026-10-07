<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AccountProvisioner;
use App\Support\License\LicenseClient;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Owner-server lifetime + unlimited license. Owner ka apna panel kabhi
 * trial-expiry ya account-cap me lock nahi hona chahiye — ye business ka
 * base requirement hai.
 */
class LicenseOwnerTest extends TestCase
{
    private function tempStore(): void
    {
        config(['acp.license.store_path' => tempnam(sys_get_temp_dir(), 'lic')]);
    }

    public function test_owner_license_is_lifetime_unlimited_and_gate_stays_open(): void
    {
        $this->tempStore();

        $this->assertSame(0, Artisan::call('alphacp:license:owner'));

        $status = (new LicenseClient())->status();

        $this->assertSame('active', $status['state']);
        $this->assertSame('LIFETIME', $status['label']);
        $this->assertSame('owner', $status['tier']);
        $this->assertSame(-1, $status['max_accounts']);
        $this->assertSame('lifetime', $status['expires_at']);
        $this->assertNull($status['days_left']);

        $gate = AccountProvisioner::licenseGate(new LicenseClient());
        $this->assertTrue($gate['ok'], 'owner license par account-cap nahi lagni chahiye');
        $this->assertNull($gate['reason']);
    }

    public function test_owner_record_copied_to_another_server_is_invalid(): void
    {
        $this->tempStore();
        (new LicenseClient())->installOwnerLicense();

        $path = (string) config('acp.license.store_path');
        $rec  = json_decode((string) file_get_contents($path), true);
        $rec['fingerprint'] = str_repeat('0', 64); // doosre server ki binding
        file_put_contents($path, json_encode($rec));

        $this->assertSame('invalid', (new LicenseClient())->status()['state']);
    }

    public function test_idempotent_rerun_keeps_owner_tier(): void
    {
        $this->tempStore();
        Artisan::call('alphacp:license:owner');
        Artisan::call('alphacp:license:owner');

        $status = (new LicenseClient())->status();
        $this->assertSame('owner', $status['tier']);
        $this->assertSame('LIFETIME', $status['label']);
    }
}
