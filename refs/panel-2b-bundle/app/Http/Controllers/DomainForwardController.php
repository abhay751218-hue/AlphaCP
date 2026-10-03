<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DomainForward;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Setup/Edit Domain Forwarding — JSON via paneld dns.forward. No BIND rewrite. */
class DomainForwardController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('domain-forward.index', [
            'rows' => DomainForward::query()->orderBy('id')->get(),
            'codes' => [301, 302],
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'url' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:3'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $url = Dns::tryForwardUrl($data['url']);
        $code = Dns::tryForwardCode($data['code']);
        if ($domain === null || $url === null || $code === null) {
            return back()->withErrors(['domain' => 'Invalid forward. Domain FQDN. URL http(s) host/path. Code 301/302. No pipe/path.'])->withInput();
        }
        $row = DomainForward::query()->where('domain', $domain)->first();
        if ($row === null) {
            if (DomainForward::query()->count() >= 50) {
                return back()->withErrors(['domain' => 'Domain forwarding limit reached (50).']);
            }
            DomainForward::query()->create(['domain' => $domain, 'url' => $url, 'code' => $code]);
        } else {
            $row->update(['url' => $url, 'code' => $code]);
        }
        DnsProvisioner::enqueueForward();
        Audit::log('dns.forward', 'info', 'system', null, ['domain' => $domain, 'code' => $code]);

        return redirect()->route('domain-forward.index')->with('success', 'Domain forwarding is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Domain Forwarding is a WHM tool.');
        }
    }
}
