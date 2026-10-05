<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NameserverSelection;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Nameserver Selection — JSON via paneld dns.nameserver. No BIND rewrite. */
class NameserverSelectionController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('nameserver-selection.index', [
            'row' => NameserverSelection::query()->orderByDesc('id')->first(),
            'softwares' => Dns::NAMESERVER_SOFTWARE,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'software' => ['required', 'string', 'max:16'],
            'ns1' => ['required', 'string', 'max:190'],
            'ns2' => ['required', 'string', 'max:190'],
        ]);
        $software = Dns::tryNameserverSoftware($data['software']);
        $ns1 = Dns::tryDomain($data['ns1']);
        $ns2 = Dns::tryDomain($data['ns2']);
        if ($software === null || $ns1 === null || $ns2 === null || $ns1 === $ns2) {
            return back()->withErrors(['software' => 'Invalid nameserver. Software bind/nsd/powerdns/disabled. ns1 and ns2 different FQDNs. No pipe/path.'])->withInput();
        }
        $row = NameserverSelection::query()->orderByDesc('id')->first();
        if ($row === null) {
            NameserverSelection::query()->create([
                'software' => $software,
                'ns1' => $ns1,
                'ns2' => $ns2,
            ]);
        } else {
            $row->update([
                'software' => $software,
                'ns1' => $ns1,
                'ns2' => $ns2,
            ]);
        }
        DnsProvisioner::enqueueNameserver($software, $ns1, $ns2);
        Audit::log('dns.nameserver', 'info', 'system', null, ['software' => $software, 'ns1' => $ns1, 'ns2' => $ns2]);

        return redirect()->route('nameserver-selection.index')->with('success', 'Nameserver selection is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Nameserver Selection is a WHM tool.');
        }
    }
}
