<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WhmApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'testtoken123';

    private function rootWithToken(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', 'root')->firstOrFail();
        $user = User::query()->create([
            'username'      => 'apiroot',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
        ApiToken::query()->create([
            'user_id'    => $user->id,
            'name'       => 'billing',
            'token_hash' => hash('sha256', self::TOKEN),
        ]);

        return $user;
    }

    private function api(): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN];
    }

    public function test_requires_token(): void
    {
        $this->rootWithToken();

        $this->get('/json-api/listaccts')->assertStatus(401);
    }

    public function test_listaccts_returns_json(): void
    {
        $this->rootWithToken();

        $this->withHeaders($this->api())
            ->get('/json-api/listaccts')
            ->assertOk()
            ->assertJsonStructure(['acct']);
    }

    public function test_createacct_queues_account(): void
    {
        $this->rootWithToken();

        $this->withHeaders($this->api())
            ->post('/json-api/createacct', ['username' => 'apiuser', 'domain' => 'api.example.com'])
            ->assertOk()
            ->assertJsonPath('result.0.status', 1);

        $this->assertDatabaseHas('accounts', ['username' => 'apiuser', 'main_domain' => 'api.example.com']);
    }

    public function test_suspend_and_unsuspend(): void
    {
        $this->rootWithToken();

        $this->withHeaders($this->api())
            ->post('/json-api/createacct', ['username' => 'apiuser', 'domain' => 'api.example.com']);

        $this->withHeaders($this->api())
            ->get('/json-api/suspendacct?user=apiuser')
            ->assertJsonPath('result.0.status', 1);
        $this->assertDatabaseHas('accounts', ['username' => 'apiuser', 'status' => 'suspended']);

        $this->withHeaders($this->api())
            ->get('/json-api/unsuspendacct?user=apiuser')
            ->assertJsonPath('result.0.status', 1);
        $this->assertDatabaseHas('accounts', ['username' => 'apiuser', 'status' => 'active']);
    }
}
