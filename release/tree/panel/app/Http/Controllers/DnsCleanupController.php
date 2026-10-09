<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DnsCleanup;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Perform a DNS Cleanup — JSON via paneld dns.cleanup. No BIND rewrite. */
class DnsCleanupController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('dns-cleanup.index', [
            'rows' => DnsCleanup::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        if (DnsCleanup::query()->count() >= 50) {
            return back()->withErrors(['domain' => 'DNS cleanup limit reached (50).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        if ($domain === null) {
            return back()->withErrors(['domain' => 'Invalid domain. FQDN only. No pipe/path.'])->withInput();
        }
        if (DnsCleanup::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['domain' => 'This domain is already queued for cleanup.'])->withInput();
        }
        DnsCleanup::query()->create(['domain' => $domain]);
        DnsProvisioner::enqueueCleanup();
        Audit::log('dns.cleanup.add', 'info', 'system', null, ['domain' => $domain]);

        return redirect()->route('dns-cleanup.index')->with('success', 'DNS cleanup is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'DNS Cleanup is a WHM tool.');
        }
    }
}
