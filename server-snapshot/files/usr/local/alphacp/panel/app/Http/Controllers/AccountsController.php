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
use App\Support\License\LicenseClient;
use App\Support\Panel;
use App\Support\PasswordGenerator;
use App\Support\ShadowHash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * WHM-style account list / create / suspend / unsuspend / terminate.
 * Privileged OS work is always an agent task (ADR-0002).
 */
class AccountsController extends Controller
{
    public function index(): View
    {
        $accounts = Account::query()->with('package')->orderBy('username')->get();
        foreach ($accounts as $account) {
            AccountProvisioner::refresh($account);
        }

        return view('accounts.index', [
            'accounts' => $accounts->fresh('package'),
            'liveCount' => AccountProvisioner::liveCount(),
        ]);
    }

    public function create(LicenseClient $license): View
    {
        return view('accounts.create', [
            'packages' => Package::query()->where('status', 'active')->orderBy('name')->get(),
            'gate' => AccountProvisioner::licenseGate($license),
        ]);
    }

    public function store(Request $request, LicenseClient $license): RedirectResponse
    {
        $gate = AccountProvisioner::licenseGate($license);
        if (! $gate['ok']) {
            $message = $gate['reason'] === 'cap'
                ? 'License account limit poori ho gayi (max_accounts).'
                : 'License/trial se naye accounts band hain — customer sites nahi ruke.';
            return back()->withErrors(['username' => $message])->withInput();
        }

        $data = $request->validate([
            'username'      => ['required', 'string', 'max:16', 'regex:' . AccountIdentity::USERNAME_PATTERN],
            'main_domain'   => ['required', 'string', 'max:190', 'regex:' . AccountIdentity::DOMAIN_PATTERN],
            'contact_email' => ['required', 'email', 'max:190'],
            'package_id'    => ['required', 'integer', Rule::exists('packages', 'id')],
            'php_version'   => ['required', 'string', 'regex:/^8\.[0-9]$/'],
            'password'      => ['nullable', Password::defaults()],
        ]);

        $username = strtolower($data['username']);
        $domain = strtolower($data['main_domain']);

        if (AccountIdentity::isReserved($username)) {
            return back()->withErrors(['username' => 'Ye username reserved hai.'])->withInput();
        }
        if (User::query()->where('username', $username)->exists()) {
            return back()->withErrors(['username' => 'Panel user is naam se pehle se hai.'])->withInput();
        }
        if (Account::query()->where('username', $username)->exists()) {
            return back()->withErrors(['username' => 'Hosting account is naam se pehle se hai.'])->withInput();
        }
        if (Account::query()->where('main_domain', $domain)->exists()) {
            return back()->withErrors(['main_domain' => 'Domain pehle se kisi account par hai.'])->withInput();
        }

        $package = Package::query()->findOrFail($data['package_id']);
        $plain = $data['password'] ?: PasswordGenerator::generate(20);
        $generated = empty($data['password']);
        $role = Role::query()->where('name', 'user')->firstOrFail();
        $home = rtrim((string) config('acp.paths.accounts', '/home'), '/') . '/' . $username;

        $account = DB::transaction(function () use ($request, $username, $domain, $data, $package, $plain, $role, $home): Account {
            $owner = User::query()->create([
                'username'              => $username,
                'email'                 => $data['contact_email'],
                'password_hash'         => Hash::make($plain),
                'role_id'               => $role->id,
                'status'                => 'active',
                'force_password_change' => true,
                'created_by'            => $request->user()->id,
            ]);

            $account = Account::query()->create([
                'server_id'     => Panel::serverId(),
                'package_id'    => $package->id,
                'reseller_id'   => $request->user()->isRoot() ? null : $request->user()->id,
                'owner_user_id' => $owner->id,
                'username'      => $username,
                'main_domain'   => $domain,
                'contact_email' => $data['contact_email'],
                'home_path'     => $home,
                'php_version'   => $data['php_version'],
                'quota_mb'      => $package->quotaMb(),
                'status'        => 'pending',
                'created_by'    => $request->user()->id,
            ]);

            DB::table('account_users')->insert([
                'account_id' => $account->id,
                'user_id'    => $owner->id,
                'role'       => 'owner',
                'created_at' => now(),
            ]);

            $account->recordEvent('account.create.queued', 'Provisioning queued');
            return $account;
        });

        AccountProvisioner::enqueue($account, 'account.create', [
            'username'    => $username,
            'domain'      => $domain,
            'shadow_hash' => ShadowHash::make($plain),
            'quota_mb'    => $package->quotaMb(),
            'php_version' => $data['php_version'],
        ]);

        Audit::log('account.create', 'warning', 'account', $account->id, [
            'username' => $username, 'domain' => $domain,
        ]);

        $msg = "Account '{$username}' queue me hai.";
        if ($generated) {
            $msg .= " Panel password (ek baar): {$plain}";
        }

        return redirect()->route('accounts.show', $account)->with('success', $msg);
    }

    public function show(Account $account): View
    {
        AccountProvisioner::refresh($account);
        $account->refresh();

        return view('accounts.show', [
            'account' => $account->load(['package', 'owner', 'events']),
            'task' => DB::table('tasks')->where('account_id', $account->id)->orderByDesc('id')->first(),
        ]);
    }

    public function suspend(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ($account->isTerminated()) {
            return back()->withErrors(['reason' => 'Terminated account suspend nahi hota.']);
        }

        $account->forceFill([
            'suspend_reason' => $data['reason'] ?? 'Suspended from panel',
            'suspended_at'   => now(),
            'status'         => 'pending',
        ])->save();
        $account->recordEvent('account.suspend.queued', $account->suspend_reason);

        AccountProvisioner::enqueue($account, 'account.suspend', [
            'username' => $this->liveUsername($account),
            'domain'   => $this->liveDomain($account),
            'reason'   => (string) $account->suspend_reason,
        ]);
        Audit::log('account.suspend', 'warning', 'account', $account->id, [
            'reason' => $account->suspend_reason,
        ]);

        return redirect()->route('accounts.show', $account)->with('success', 'Suspend task queue me hai.');
    }

    public function unsuspend(Account $account): RedirectResponse
    {
        if ($account->isTerminated()) {
            return back()->withErrors(['reason' => 'Terminated account unsuspend nahi hota.']);
        }

        $account->forceFill(['status' => 'pending'])->save();
        $account->recordEvent('account.unsuspend.queued', 'Unsuspend queued');
        AccountProvisioner::enqueue($account, 'account.unsuspend', [
            'username' => $this->liveUsername($account),
            'domain'   => $this->liveDomain($account),
        ]);
        Audit::log('account.unsuspend', 'warning', 'account', $account->id, []);

        return redirect()->route('accounts.show', $account)->with('success', 'Unsuspend task queue me hai.');
    }

    public function terminate(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'confirm_username' => ['required', 'string', 'max:32'],
        ]);
        $live = $this->liveUsername($account);
        if (! hash_equals($live, strtolower($data['confirm_username']))) {
            return back()->withErrors(['confirm_username' => 'Confirm ke liye username theek se type karo.']);
        }

        $account->recordEvent('account.terminate.queued', 'Terminate queued');
        AccountProvisioner::enqueue($account, 'account.terminate', [
            'username' => $live,
            '_confirm' => 'account.terminate',
        ]);
        Audit::log('account.terminate', 'critical', 'account', $account->id, ['username' => $live]);

        return redirect()->route('accounts.index')->with('warning', "Account '{$live}' terminate queue me hai.");
    }

    private function liveUsername(Account $account): string
    {
        return explode('.deleted.', $account->username)[0];
    }

    private function liveDomain(Account $account): string
    {
        return explode('.deleted.', $account->main_domain)[0];
    }
}
