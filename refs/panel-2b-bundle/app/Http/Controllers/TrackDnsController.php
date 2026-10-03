<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Track DNS — search jailed zone/dynamic JSON via paneld. No dig, no BIND. */
class TrackDnsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('track-dns.index', [
            'account' => $account,
            'types' => Dns::TRACK_TYPES,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['query' => 'Cannot track DNS on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'query' => ['required', 'string', 'max:190'],
            'type' => ['required', 'string', 'max:8'],
        ]);
        $query = Dns::tryDomain($data['query']);
        $type = Dns::tryTrackType($data['type']);
        if ($query === null || $type === null) {
            return back()->withErrors(['query' => 'Query must be an FQDN. Type A/MX/NS/TXT/CNAME/ALL. No pipe/path.'])->withInput();
        }
        DnsProvisioner::enqueueTrack($account, $query, $type);
        $account->recordEvent('dns.track.queued', $query);
        Audit::log('dns.track', 'info', 'account', $account->id, ['query' => $query, 'type' => $type]);

        return redirect()->route('track-dns.index')->with('success', 'DNS track is queued. Dig/BIND later.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
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
