<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Domain;
use App\Support\AccountIdentity;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Domains tool — scoped to the logged-in customer's hosting account.
 * WHM users (root/reseller) do not use this page; they manage accounts instead.
 */
class DomainsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        if ($account !== null) {
            DomainProvisioner::seedMain($account);
            foreach ($account->domains as $domain) {
                DomainProvisioner::refresh($domain);
            }
            $account->refresh()->load(['domains', 'package.featureList']);
        }

        return view('domains.index', [
            'account' => $account,
            'domains' => $account?->domains()
                ->orderByRaw("CASE type WHEN 'main' THEN 0 WHEN 'addon' THEN 1 WHEN 'sub' THEN 2 WHEN 'parked' THEN 3 ELSE 4 END")
                ->orderBy('domain')
                ->get() ?? collect(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['domain' => 'Suspended/terminated account par domain nahi badlega.']);
        }
        if (! DomainProvisioner::featureAllowed($account)) {
            return back()->withErrors(['domain' => 'Is package me domains feature band hai.']);
        }

        $data = $request->validate([
            'type' => ['required', 'in:addon,sub,parked,redirect'],
            'domain' => ['required', 'string', 'max:190', 'regex:' . AccountIdentity::DOMAIN_PATTERN],
            'redirect_url' => ['nullable', 'url', 'max:500', 'required_if:type,redirect'],
            'redirect_code' => ['nullable', 'in:301,302'],
        ]);
        $fqdn = strtolower($data['domain']);
        $type = $data['type'];

        if ($type === 'sub' && ! str_ends_with($fqdn, '.' . strtolower($account->main_domain))) {
            return back()->withErrors(['domain' => 'Subdomain parent domain ke under hona chahiye (blog.' . $account->main_domain . ').'])->withInput();
        }
        if (Domain::query()->where('domain', $fqdn)->exists() || Account::query()->where('main_domain', $fqdn)->exists()) {
            return back()->withErrors(['domain' => 'Ye domain pehle se kisi account par hai.'])->withInput();
        }
        if (DomainProvisioner::limitReached($account, $type)) {
            $key = DomainProvisioner::LIMIT_KEY[$type];
            return back()->withErrors(['domain' => "Package limit poori ({$key})."])->withInput();
        }

        $docroot = DomainProvisioner::docrootFor($account, $type, $fqdn);
        $domain = Domain::query()->create([
            'account_id' => $account->id,
            'type' => $type,
            'domain' => $fqdn,
            'document_root' => $docroot,
            'redirect_url' => $type === 'redirect' ? $data['redirect_url'] : null,
            'redirect_code' => $type === 'redirect' ? (int) ($data['redirect_code'] ?? 301) : null,
            'php_version' => $account->php_version,
            'status' => 'pending',
        ]);

        $payload = [
            'username' => $account->username,
            'domain' => $fqdn,
            'type' => $type,
            'document_root' => $docroot,
        ];
        if ($type === 'redirect') {
            $payload['redirect_url'] = (string) $domain->redirect_url;
            $payload['redirect_code'] = (int) $domain->redirect_code;
        }
        AccountProvisioner::enqueue($account, 'domain.add', $payload);
        $account->recordEvent('domain.add.queued', $fqdn);
        Audit::log('domain.add', 'info', 'domain', $domain->id, ['domain' => $fqdn, 'type' => $type]);

        return redirect()->route('domains.index')->with('success', "Domain '{$fqdn}' queue me hai.");
    }

    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        if ($domain->isMain()) {
            return back()->withErrors(['domain' => 'Main domain delete nahi hota.']);
        }

        $domain->forceFill(['status' => 'removing'])->save();
        AccountProvisioner::enqueue($account, 'domain.remove', [
            'username' => $account->username,
            'domain' => $domain->domain,
        ]);
        $account->recordEvent('domain.remove.queued', $domain->domain);
        Audit::log('domain.remove', 'warning', 'domain', $domain->id, ['domain' => $domain->domain]);

        return redirect()->route('domains.index')->with('success', "Domain '{$domain->domain}' hataane ke liye queue me hai.");
    }

    private function accountFor(Request $request): ?Account
    {
        $user = $request->user();
        if (ModuleCatalog::modeFor($user) === 'whm') {
            return null;
        }
        return $user->hostingAccount?->load(['package.featureList']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'Is login ka hosting account nahi hai.');
        }
        return $account;
    }
}
