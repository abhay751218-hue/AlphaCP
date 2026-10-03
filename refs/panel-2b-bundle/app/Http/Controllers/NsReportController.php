<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NsRecord;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Nameserver Record Report — JSON via paneld dns.nsreport. No BIND rewrite. */
class NsReportController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('ns-report.index', [
            'rows' => NsRecord::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        if (NsRecord::query()->count() >= 50) {
            return back()->withErrors(['domain' => 'NS report limit reached (50).']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'nameserver' => ['required', 'string', 'max:190'],
        ]);
        $domain = Dns::tryDomain($data['domain']);
        $nameserver = Dns::tryDomain($data['nameserver']);
        if ($domain === null || $nameserver === null) {
            return back()->withErrors(['domain' => 'Invalid domain/nameserver. FQDN only. No pipe/path.'])->withInput();
        }
        if (NsRecord::query()->where('domain', $domain)->where('nameserver', $nameserver)->exists()) {
            return back()->withErrors(['domain' => 'This nameserver row already exists.'])->withInput();
        }
        NsRecord::query()->create(['domain' => $domain, 'nameserver' => $nameserver]);
        DnsProvisioner::enqueueNsReport();
        Audit::log('dns.nsreport.add', 'info', 'system', null, ['domain' => $domain, 'nameserver' => $nameserver]);

        return redirect()->route('ns-report.index')->with('success', 'Nameserver record is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Nameserver Record Report is a WHM tool.');
        }
    }
}
