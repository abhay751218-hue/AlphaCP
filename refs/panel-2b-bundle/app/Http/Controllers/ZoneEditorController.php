<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DnsRecord;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Zone Editor — A/CNAME/MX/TXT via paneld dns.zone. No BIND rewrite. */
class ZoneEditorController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('zone-editor.index', [
            'account' => $account,
            'rows' => $account?->dnsRecords()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'types' => Dns::TYPES,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['name' => 'Cannot change DNS on a suspended/terminated account.']);
        }
        if (DnsProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'DNS record limit reached (50).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:63'],
            'type' => ['required', 'string', 'max:8'],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $name = Dns::tryName($data['name']);
        $type = Dns::tryType($data['type']);
        $value = $type ? Dns::tryValue($type, $data['value']) : null;
        $allowed = MailProvisioner::domainsFor($account);
        if ($domain === null || $name === null || $type === null || $value === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['name' => 'Invalid DNS record. Use A/CNAME/MX/TXT. No pipe/path. Domain must belong to this account.'])->withInput();
        }
        $exists = DnsRecord::query()
            ->where('account_id', $account->id)
            ->where('domain', $domain)
            ->where('name', $name)
            ->where('type', $type)
            ->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'This DNS record already exists.'])->withInput();
        }
        DnsRecord::query()->create([
            'account_id' => $account->id,
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'value' => $value,
        ]);
        DnsProvisioner::enqueue($account);
        $account->recordEvent('dns.zone.queued', $name . '.' . $domain);
        Audit::log('dns.add', 'info', 'account', $account->id, ['name' => $name, 'domain' => $domain, 'type' => $type]);

        return redirect()->route('zone-editor.index')->with('success', 'DNS record is queued.');
    }

    public function destroy(Request $request, DnsRecord $dns_record): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($dns_record->account_id !== $account->id) {
            abort(403);
        }
        $label = $dns_record->name . '.' . $dns_record->domain;
        $dns_record->delete();
        DnsProvisioner::enqueue($account);
        Audit::log('dns.remove', 'warning', 'account', $account->id, ['name' => $label]);

        return redirect()->route('zone-editor.index')->with('success', 'DNS record is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'dnsRecords']);
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
