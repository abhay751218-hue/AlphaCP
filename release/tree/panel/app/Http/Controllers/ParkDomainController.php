<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ParkedDomain;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Park a Domain — JSON via paneld dns.park. No BIND rewrite. */
class ParkDomainController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('park-domain.index', [
            'rows' => ParkedDomain::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        if (ParkedDomain::query()->count() >= 50) {
            return back()->withErrors(['domain' => 'Parked domain limit reached (50).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'target' => ['required', 'string', 'max:190'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $target = Dns::tryDomain($data['target']);
        if ($domain === null || $target === null || $domain === $target) {
            return back()->withErrors(['domain' => 'Invalid park. Domain and target must be different FQDNs. No pipe/path.'])->withInput();
        }
        if (ParkedDomain::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['domain' => 'This domain is already parked.'])->withInput();
        }
        ParkedDomain::query()->create(['domain' => $domain, 'target' => $target]);
        DnsProvisioner::enqueuePark();
        Audit::log('dns.park.add', 'info', 'system', null, ['domain' => $domain, 'target' => $target]);

        return redirect()->route('park-domain.index')->with('success', 'Parked domain is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Park a Domain is a WHM tool.');
        }
    }
}
