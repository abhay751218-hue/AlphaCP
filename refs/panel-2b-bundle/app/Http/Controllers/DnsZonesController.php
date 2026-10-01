<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM DNS Zone Manager — list account zones. No BIND rewrite. */
class DnsZonesController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);
        $raw = strtolower(trim((string) $request->query('q', '')));
        $filter = $raw === '' ? '' : (Dns::tryDomain($raw) ?? null);
        $invalid = $raw !== '' && $filter === null;

        $rows = [];
        if (! $invalid) {
            $accounts = Account::query()->with(['domains', 'dnsRecords', 'package'])->orderBy('username')->get();
            foreach ($accounts as $account) {
                foreach (MailProvisioner::domainsFor($account) as $domain) {
                    if ($filter !== '' && $filter !== null && $domain !== $filter) {
                        continue;
                    }
                    $rows[] = [
                        'account' => $account,
                        'domain' => $domain,
                        'records' => $account->dnsRecords->where('domain', $domain)->count(),
                    ];
                }
            }
        }

        return view('dns-zones.index', [
            'rows' => $rows,
            'q' => $raw,
            'invalid' => $invalid,
            'panelMode' => 'whm',
        ]);
    }

    public function sync(Request $request, Account $account): RedirectResponse
    {
        $this->requireWhm($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['q' => 'Cannot sync DNS on a suspended/terminated account.']);
        }
        DnsProvisioner::enqueue($account);
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
