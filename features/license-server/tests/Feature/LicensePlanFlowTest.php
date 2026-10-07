<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LicenseKey;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountProvisioner;
use App\Support\License\LicenseClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sellable flow: owner /license-server se plan-based key issue karta hai,
 * customer panel /api/v1/activate se activate karta hai, aur plan ka cap +
 * lifetime server-side enforce hota hai. (Sandbox me sodium nahi → hmac
 * fallback path chalta hai; real server par Ed25519.)
 */
class LicensePlanFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'acp.license_secret'    => 'test-secret',
            'acp.license.store_path' => tempnam(sys_get_temp_dir(), 'lic'),
            'acp.license.api_url'   => 'http://ls.test',
        ]);
    }

    private function asRoot(): static
    {
        $root = User::query()->firstOrCreate(
            ['username' => 'licroot'],
            [
                'password_hash' => Hash::make('CorrectHorse1'),
                'role_id' => Role::query()->where('name', 'root')->firstOrFail()->id,
                'status'  => 'active',
            ],
        );

        return $this->withSession(['two_factor_passed' => true])->actingAs($root->fresh());
    }

    private function issue(string $plan, int $days): string
    {
        $this->asRoot()->post('/license-server', [
            'server_id' => 'srv-1',
            'plan'      => $plan,
            'days'      => $days,
        ])->assertRedirect('/license-server');

        return (string) session('new_key');
    }

    private function activateOnPanel(string $uid): array
    {
        $endpoint = $this->postJson('/api/v1/activate', ['license_key' => $uid, 'fingerprint' => 'ff']);
        $endpoint->assertOk();

        Http::fake(['*ls.test/*' => Http::response($endpoint->json())]);

        $out = (new LicenseClient())->activate($uid);
        $this->assertTrue($out['ok'], (string) ($out['message'] ?? ''));

        return $out;
    }

    public function test_business_plan_issues_200_account_cap_and_activates(): void
    {
        $uid = $this->issue('business', 30);

        $row = LicenseKey::query()->where('license_uid', $uid)->firstOrFail();
        $this->assertSame(200, $row->payload['max_accounts']);
        $this->assertSame('business', $row->payload['tier']);
        $this->assertNotNull($row->payload['expires_at']);

        $this->activateOnPanel($uid);

        $status = (new LicenseClient())->status();
        $this->assertSame('business', $status['tier']);
        $this->assertSame(200, $status['max_accounts']);
        $this->assertContains($status['state'], ['active', 'notice']);
    }

    public function test_owner_plan_from_server_is_lifetime_unlimited(): void
    {
        $uid = $this->issue('owner', 0);

        $row = LicenseKey::query()->where('license_uid', $uid)->firstOrFail();
        $this->assertNull($row->payload['expires_at']);
        $this->assertSame(-1, $row->payload['max_accounts']);

        $this->activateOnPanel($uid);

        $status = (new LicenseClient())->status();
        $this->assertSame('LIFETIME', $status['label']);
        $this->assertSame(-1, $status['max_accounts']);

        $this->assertTrue(AccountProvisioner::licenseGate(new LicenseClient())['ok']);
    }

    public function test_starter_plan_caps_at_10_accounts(): void
    {
        $uid = $this->issue('starter', 30);
        $this->activateOnPanel($uid);

        $package = Package::query()->where('name', 'default')->firstOrFail();

        foreach (range(1, 10) as $i) {
            Account::query()->create([
                'server_id'     => 1,
                'package_id'    => $package->id,
                'username'      => 'capuser' . $i,
                'main_domain'   => 'capuser' . $i . '.test',
                'contact_email' => 'c' . $i . '@x.test',
                'home_path'     => '/home/capuser' . $i,
                'status'        => 'active',
            ]);
        }

        $gate = AccountProvisioner::licenseGate(new LicenseClient());
        $this->assertFalse($gate['ok']);
        $this->assertSame('cap', $gate['reason']);
    }

    public function test_revoked_key_cannot_activate(): void
    {
        $uid = $this->issue('pro', 30);

        $row = LicenseKey::query()->where('license_uid', $uid)->firstOrFail();
        $this->asRoot()->delete('/license-server/' . $row->id)->assertRedirect('/license-server');

        $this->postJson('/api/v1/activate', ['license_key' => $uid])->assertStatus(422);
    }

    public function test_lifetime_days_rejected_for_customer_plans(): void
    {
        $this->asRoot()->post('/license-server', ['server_id' => 'srv-1', 'plan' => 'starter', 'days' => 0])
            ->assertSessionHasErrors('days');

        $this->assertSame(0, LicenseKey::query()->count());
    }
}
