<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TransferReview;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransferReviewTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => 'u_' . $roleName,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asPanelUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_root_can_set_transfer_review(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/transfer-review')
            ->assertOk()
            ->assertSee('Review transfers and restores')
            ->assertSee('review.json');

        $this->asPanelUser($root)->post('/transfer-review', [
            'username' => 'alicehost',
            'status' => 'ok',
        ])->assertRedirect(route('transfer-review.index'));

        $row = TransferReview::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('ok', $row->status);
        $task = DB::table('tasks')->where('type', 'backup.review')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_status_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-review', [
            'username' => 'alicehost',
            'status' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, TransferReview::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.review')->first());
    }

    public function test_path_escape_username_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-review', [
            'username' => '../etc',
            'status' => 'ok',
        ])->assertRedirect();
        $this->assertSame(0, TransferReview::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.review')->first());
    }

    public function test_root_dashboard_has_transfer_review_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Review Transfers and Restores')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_transfer_review(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('Review Transfers and Restores')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_transfer_review(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/transfer-review')->assertForbidden();
        $this->asPanelUser($mail)->get('/transfer-review')->assertForbidden();
    }
}
