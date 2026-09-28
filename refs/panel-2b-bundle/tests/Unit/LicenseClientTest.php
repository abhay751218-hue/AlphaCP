<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\License\LicenseClient;
use Tests\TestCase;

final class LicenseClientTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir() . '/alphacp-license-' . bin2hex(random_bytes(6)) . '/license.json';
        config(['acp.license.store_path' => $this->store]);
        config(['acp.license.api_url' => '']);
    }

    protected function tearDown(): void
    {
        @unlink($this->store);
        @rmdir(dirname($this->store));
        parent::tearDown();
    }

    public function test_first_status_starts_a_local_fifteen_day_trial(): void
    {
        $client = new LicenseClient();
        $status = $client->ensureTrial();

        $this->assertSame('trial', $status['state']);
        $this->assertSame('trial', $status['tier']);
        $this->assertSame('local', $status['source']);
        $this->assertSame(20, $status['max_accounts']);
        $this->assertFileExists($this->store);
        $current = $client->status();
        $this->assertSame('trial', $current['state']);
        $this->assertSame($status['license_uid'], $current['license_uid']);
        $this->assertSame($status['expires_at'], $current['expires_at']);
    }

    public function test_trial_state_is_reused_without_extending_expiry(): void
    {
        $client = new LicenseClient();
        $first = $client->ensureTrial();
        $second = $client->ensureTrial();

        $this->assertSame($first['license_uid'], $second['license_uid']);
        $this->assertSame($first['expires_at'], $second['expires_at']);
    }

    public function test_canonical_payload_sorts_object_keys_but_preserves_lists(): void
    {
        $payload = [
            'z' => true,
            'features' => ['dns', 'core'],
            'a' => ['b' => 2, 'a' => 1],
        ];

        $this->assertSame(
            '{"a":{"a":1,"b":2},"features":["dns","core"],"z":true}',
            LicenseClient::canonicalPayload($payload),
        );
    }

    public function test_fingerprint_is_a_non_empty_sha256_hex_value(): void
    {
        $fingerprint = (new LicenseClient())->fingerprint();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
    }
}
