<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\DomainProvisioner;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM DNS Zone Manager — list/add/delete account zones (S9: real BIND rewrite). */
class DnsZonesController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);
        $raw = strtolower(trim((string) $request->query('q', '')));
        $filter = $raw === '' ? '' : (Dns::tryDomain($raw) ?? null);
        $invalid = $raw !== '' && $filter === null;

        $accounts = Account::query()->with(['domains', 'dnsRecords', 'package'])->orderBy('username')->get();
        $rows = [];
        if (! $invalid) {
            foreach ($accounts as $account) {
                foreach (MailProvisioner::domainsFor($account) as $domain) {
                    if ($filter !== '' && $filter !== null && $domain !== $filter) {
                        continue;
                    }
                    $rows[] = [
                        'account' => $account,
                        'domain' => $domain,
                        'records' => $account->dnsRecords->where('domain', $domain)->count(),
                        'main' => $domain === $account->main_domain,
                    ];
                }
            }
        }

        return view('dns-zones.index', [
            'rows' => $rows,
            'accounts' => $accounts,
            'q' => $raw,
            'invalid' => $invalid,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'domain' => ['required', 'string', 'max:190'],
        ]);
        $account = Account::query()->find($data['account_id']);
        if ($account === null) {
            return back()->withErrors(['domain' => 'Unknown hosting account.'])->withInput();
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['domain' => 'Cannot add a DNS zone on a suspended/terminated account.']);
        }
        $fqdn = Dns::tryDomain($data['domain']);
        if ($fqdn === null) {
            return back()->withErrors(['domain' => 'Invalid domain. FQDN only. No pipe/path.'])->withInput();
        }
        if (Domain::query()->where('domain', $fqdn)->exists() || Account::query()->where('main_domain', $fqdn)->exists()) {
            return back()->withErrors(['domain' => 'This domain is already on an account.'])->withInput();
        }
        if (DomainProvisioner::limitReached($account, 'parked')) {
            return back()->withErrors(['domain' => 'Package limit reached (MAXPARK).'])->withInput();
        }
        $docroot = DomainProvisioner::docrootFor($account, 'parked', $fqdn);
        $domain = Domain::query()->create([
            'account_id' => $account->id,
            'type' => 'parked',
            'domain' => $fqdn,
            'document_root' => $docroot,
            'php_version' => $account->php_version,
            'status' => 'pending',
        ]);
        AccountProvisioner::enqueue($account, 'domain.add', [
            'username' => $account->username,
            'domain' => $fqdn,
            'type' => 'parked',
            'document_root' => $docroot,
        ]);
        DnsProvisioner::enqueue($account);
        DnsProvisioner::enqueueBindZone($account, $fqdn);
        $account->recordEvent('dns.zone.add.queued', $fqdn);
        Audit::log('dns.zones.add', 'info', 'domain', $domain->id, ['domain' => $fqdn]);

        return redirect()->route('dns-zones.index')->with('success', "DNS zone '{$fqdn}' is queued.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'domain' => ['required', 'string', 'max:190'],
        ]);
        $account = Account::query()->find($data['account_id']);
        if ($account === null) {
            return back()->withErrors(['domain' => 'Unknown hosting account.']);
        }
        $fqdn = Dns::tryDomain($data['domain']);
        if ($fqdn === null) {
            return back()->withErrors(['domain' => 'Invalid domain. FQDN only. No pipe/path.']);
        }
        if ($fqdn === $account->main_domain) {
            return back()->withErrors(['domain' => 'The main domain zone cannot be deleted.']);
        }
        $domain = Domain::query()->where('account_id', $account->id)->where('domain', $fqdn)->first();
        if ($domain === null || $domain->isMain()) {
            return back()->withErrors(['domain' => 'DNS zone not found on this account.']);
        }
        DnsRecord::query()->where('account_id', $account->id)->where('domain', $fqdn)->delete();
        $domain->forceFill(['status' => 'removing'])->save();
        AccountProvisioner::enqueue($account, 'domain.remove', [
            'username' => $account->username,
            'domain' => $fqdn,
        ]);
        DnsProvisioner::enqueue($account);
        DnsProvisioner::enqueueBindRemove($fqdn);
        $account->recordEvent('dns.zone.remove.queued', $fqdn);
        Audit::log('dns.zones.remove', 'warning', 'domain', $domain->id, ['domain' => $fqdn]);

        return redirect()->route('dns-zones.index')->with('success', "DNS zone '{$fqdn}' is queued for removal.");
    }

    public function sync(Request $request, Account $account): RedirectResponse
    {
        $this->requireWhm($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['q' => 'Cannot sync DNS on a suspended/terminated account.']);
        }
        DnsProvisioner::enqueue($account);
        DnsProvisioner::enqueueBindSync();
        $account->recordEvent('dns.zone.queued', 'whm-sync');
        Audit::log('dns.zones.sync', 'info', 'account', $account->id, ['username' => $account->username]);

        return redirect()->route('dns-zones.index')->with('success', 'Zone sync is queued (dns.zone).');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'DNS Zone Manager is a WHM tool.');
        }
    }
}
