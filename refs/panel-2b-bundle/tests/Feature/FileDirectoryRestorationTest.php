<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileDirectoryRestoration;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FileDirectoryRestorationTest extends TestCase
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

    public function test_root_can_set_file_directory_restoration(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/file-directory-restoration')
            ->assertOk()
            ->assertSee('File and directory restoration')
            ->assertSee('filedir.json');

        $this->asPanelUser($root)->post('/file-directory-restoration', [
            'username' => 'alicehost',
            'path' => 'mail/inbox',
        ])->assertRedirect(route('file-directory-restoration.index'));

        $row = FileDirectoryRestoration::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('mail/inbox', $row->path);
        $task = DB::table('tasks')->where('type', 'backup.filedir')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_path_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/file-directory-restoration', [
            'username' => 'alicehost',
            'path' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, FileDirectoryRestoration::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.filedir')->first());
    }

    public function test_path_escape_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/file-directory-restoration', [
            'username' => 'alicehost',
            'path' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, FileDirectoryRestoration::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.filedir')->first());
    }

    public function test_root_dashboard_has_file_directory_restoration_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('File and Directory Restoration')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_file_directory_restoration(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('File and Directory Restoration')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_file_directory_restoration(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/file-directory-restoration')->assertForbidden();
        $this->asPanelUser($mail)->get('/file-directory-restoration')->assertForbidden();
    }
}
