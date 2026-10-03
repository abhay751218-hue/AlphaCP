<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ZoneTtl;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Set Zone TTL — JSON via paneld dns.ttl. No BIND rewrite. */
class ZoneTtlController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('zone-ttl.index', [
            'rows' => ZoneTtl::query()->orderBy('id')->get(),
            'ttls' => Dns::TTLS,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'ttl' => ['required', 'string', 'max:8'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $ttl = Dns::tryTtl($data['ttl']);
        if ($domain === null || $ttl === null) {
            return back()->withErrors(['domain' => 'Invalid domain/TTL. FQDN only. TTL 60–86400. No pipe/path.'])->withInput();
        }
        $row = ZoneTtl::query()->where('domain', $domain)->first();
        if ($row === null) {
            if (ZoneTtl::query()->count() >= 50) {
                return back()->withErrors(['domain' => 'Zone TTL limit reached (50).']);
            }
            ZoneTtl::query()->create(['domain' => $domain, 'ttl' => $ttl]);
        } else {
            $row->update(['ttl' => $ttl]);
        }
        DnsProvisioner::enqueueTtl();
        Audit::log('dns.ttl', 'info', 'system', null, ['domain' => $domain, 'ttl' => $ttl]);

        return redirect()->route('zone-ttl.index')->with('success', 'Zone TTL is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Set Zone TTL is a WHM tool.');
        }
    }
}
