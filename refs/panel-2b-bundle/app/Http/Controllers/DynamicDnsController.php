<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DnsDynamicHost;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DynamicDns;
use App\Support\DynamicDnsProvisioner;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Dynamic DNS — hosts + tokens via paneld dns.dynamic. No BIND rewrite. */
class DynamicDnsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('dynamic-dns.index', [
            'account' => $account,
            'rows' => $account?->dynamicDnsHosts()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['name' => 'Cannot change Dynamic DNS on a suspended/terminated account.']);
        }
        if (DynamicDnsProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'Dynamic DNS host limit reached (20).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:63'],
            'ip' => ['nullable', 'string', 'max:15'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $name = DynamicDns::tryName($data['name']);
        $ip = DynamicDns::tryIp((string) ($data['ip'] ?? ''));
        $allowed = MailProvisioner::domainsFor($account);
        if ($domain === null || $name === null || $ip === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['name' => 'Invalid Dynamic DNS host. No pipe/path. Domain must belong to this account. IPv4 optional.'])->withInput();
        }
        $exists = DnsDynamicHost::query()
            ->where('account_id', $account->id)
            ->where('domain', $domain)
            ->where('name', $name)
            ->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'This Dynamic DNS host already exists.'])->withInput();
        }
        DnsDynamicHost::query()->create([
            'account_id' => $account->id,
            'domain' => $domain,
            'name' => $name,
            'token' => DynamicDns::token(),
            'ip' => $ip,
        ]);
        DynamicDnsProvisioner::enqueue($account);
        $account->recordEvent('dns.dynamic.queued', $name . '.' . $domain);
        Audit::log('dns.dynamic.add', 'info', 'account', $account->id, ['name' => $name, 'domain' => $domain]);

        return redirect()->route('dynamic-dns.index')->with('success', 'Dynamic DNS host is queued.');
    }

    public function destroy(Request $request, DnsDynamicHost $dns_dynamic_host): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($dns_dynamic_host->account_id !== $account->id) {
            abort(403);
        }
        $label = $dns_dynamic_host->name . '.' . $dns_dynamic_host->domain;
        $dns_dynamic_host->delete();
        DynamicDnsProvisioner::enqueue($account);
        Audit::log('dns.dynamic.remove', 'warning', 'account', $account->id, ['name' => $label]);

        return redirect()->route('dynamic-dns.index')->with('success', 'Dynamic DNS host is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'dynamicDnsHosts']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
