<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountIdentity;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\Panel;
use App\Support\PasswordGenerator;
use App\Support\ShadowHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WHM API 1 compatible endpoints (for billing software like WHMCS/Blesta).
 * Shape mirrors cPanel: /json-api/<func>?user=... returns {"result":[{status:...}]}.
 */
final class WhmApiController extends Controller
{
    public function listaccts(Request $request): JsonResponse
    {
        $this->requireManage($request);

        $accts = Account::query()->orderBy('username')->get()->map(fn (Account $a) => [
            'user'   => $a->username,
            'domain' => $a->main_domain,
            'status' => $a->status,
        ]);

        return response()->json(['acct' => $accts]);
    }

    public function accountsummary(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        return response()->json(['acct' => [
            'user'        => $account->username,
            'domain'      => $account->main_domain,
            'email'       => $account->contact_email,
            'status'      => $account->status,
            'home'        => $account->home_path,
            'quota_mb'    => $account->quota_mb,
            'php_version' => $account->php_version,
        ]]);
    }

    public function createacct(Request $request): JsonResponse
    {
        $actor = $this->requireManage($request);

        $username = strtolower((string) $request->input('username', ''));
        $domain   = strtolower((string) $request->input('domain', ''));
        $email    = (string) $request->input('email', 'admin@' . ($domain ?: 'example.com'));
        $pkgName  = (string) $request->input('pkg', 'default');

        if ($username === '' || ! preg_match(AccountIdentity::USERNAME_PATTERN, $username)) {
            return $this->fail('Invalid username.');
        }
        if ($domain === '') {
            return $this->fail('Domain required.');
        }
        if (Account::query()->where('username', $username)->orWhere('main_domain', $domain)->exists()) {
            return $this->fail('Username/domain already exists.');
        }

        $package = Package::query()->where('name', $pkgName)->first() ?? Package::query()->first();
        if ($package === null) {
            return $this->fail('No package available.');
        }

        $plain = PasswordGenerator::generate(20);
        $role  = Role::query()->where('name', 'user')->firstOrFail();
        $home  = rtrim((string) config('acp.paths.accounts', '/home'), '/') . '/' . $username;

        $account = DB::transaction(function () use ($actor, $username, $domain, $email, $package, $plain, $role, $home): Account {
            $owner = User::query()->create([
                'username'              => $username,
                'email'                 => $email,
                'password_hash'         => Hash::make($plain),
                'role_id'               => $role->id,
                'status'                => 'active',
                'force_password_change' => true,
                'created_by'            => $actor->id,
            ]);

            $account = Account::query()->create([
                'server_id'     => Panel::serverId(),
                'package_id'    => $package->id,
                'owner_user_id' => $owner->id,
                'username'      => $username,
                'main_domain'   => $domain,
                'contact_email' => $email,
                'home_path'     => $home,
                'php_version'   => '8.4',
                'quota_mb'      => $package->quotaMb(),
                'status'        => 'pending',
                'created_by'    => $actor->id,
            ]);

            DB::table('account_users')->insert([
                'account_id' => $account->id,
                'user_id'    => $owner->id,
                'role'       => 'owner',
                'created_at' => now(),
            ]);

            DomainProvisioner::seedMain($account);

            return $account;
        });

        AccountProvisioner::enqueue($account, 'account.create', [
            'username'    => $username,
            'domain'      => $domain,
            'shadow_hash' => ShadowHash::make($plain),
            'quota_mb'    => $package->quotaMb(),
            'php_version' => '8.4',
        ]);

        Audit::log('api.createacct', 'warning', 'account', $account->id, ['username' => $username]);

        return response()->json(['result' => [[
            'status'   => 1,
            'statusmsg' => 'Account queued (panel password: ' . $plain . ')',
            'username' => $username,
            'domain'   => $domain,
        ]]]);
    }

    public function suspendacct(Request $request): JsonResponse
    {
        return $this->setStatus($request, 'suspended', 'account.suspend', 'Account suspended.');
    }

    public function unsuspendacct(Request $request): JsonResponse
    {
        return $this->setStatus($request, 'active', 'account.unsuspend', 'Account unsuspended.');
    }

    public function removeacct(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        AccountProvisioner::markTerminated($account);
        AccountProvisioner::enqueue($account, 'account.terminate', ['username' => $account->username]);
        Audit::log('api.removeacct', 'warning', 'account', $account->id, ['username' => $account->username]);

        return response()->json(['result' => [['status' => 1, 'statusmsg' => 'Account termination queued.']]]);
    }

    private function setStatus(Request $request, string $status, string $task, string $msg): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        $account->update(['status' => $status]);
        AccountProvisioner::enqueue($account, $task, ['username' => $account->username]);

        return response()->json(['result' => [['status' => 1, 'statusmsg' => $msg]]]);
    }

    private function findAccount(Request $request): ?Account
    {
        $name = strtolower((string) ($request->input('user') ?: $request->input('username', '')));

        return Account::query()->where('username', $name)->first();
    }

    private function requireManage(Request $request): User
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('accounts.manage')) {
            abort(403, 'Token user lacks accounts.manage.');
        }

        return $user;
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['result' => [['status' => 0, 'statusmsg' => $message]]]);
    }
}
