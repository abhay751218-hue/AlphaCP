<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DnsSync;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Synchronize DNS Records — JSON via paneld dns.sync. No BIND rewrite. */
class DnsSyncController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('dns-sync.index', [
            'rows' => DnsSync::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        if (DnsSync::query()->count() >= 50) {
            return back()->withErrors(['domain' => 'DNS sync limit reached (50).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        if ($domain === null) {
            return back()->withErrors(['domain' => 'Invalid domain. FQDN only. No pipe/path.'])->withInput();
        }
        if (DnsSync::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['domain' => 'This domain is already queued for sync.'])->withInput();
        }
        DnsSync::query()->create(['domain' => $domain]);
        DnsProvisioner::enqueueSync();
        Audit::log('dns.sync.add', 'info', 'system', null, ['domain' => $domain]);

        return redirect()->route('dns-sync.index')->with('success', 'DNS sync is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Synchronize DNS Records is a WHM tool.');
        }
    }
}
