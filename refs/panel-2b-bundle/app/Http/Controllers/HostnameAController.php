<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HostnameA;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Add an A Entry for Your Hostname — JSON via paneld dns.hostname. No BIND. */
class HostnameAController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('hostname-a.index', [
            'row' => HostnameA::query()->orderByDesc('id')->first(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:190'],
            'ip' => ['required', 'string', 'max:15'],
        ]);
        $hostname = Dns::tryDomain($data['hostname']);
        $ip = Dns::tryValue('A', $data['ip']);
        if ($hostname === null || $ip === null) {
            return back()->withErrors(['hostname' => 'Invalid hostname/IP. FQDN + IPv4 only. No pipe/path.'])->withInput();
        }
        $row = HostnameA::query()->orderByDesc('id')->first();
        if ($row === null) {
            $row = HostnameA::query()->create(['hostname' => $hostname, 'ip' => $ip]);
        } else {
            $row->forceFill(['hostname' => $hostname, 'ip' => $ip])->save();
        }
        DnsProvisioner::enqueueHostname($hostname, $ip);
        Audit::log('dns.hostname', 'info', 'system', $row->id, ['hostname' => $hostname]);

        return redirect()->route('hostname-a.index')->with('success', 'Hostname A entry is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Hostname A entry is a WHM tool.');
        }
    }
}
