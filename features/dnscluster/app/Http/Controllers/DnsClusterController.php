<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DnsClusterNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM "DNS Cluster" — remote DNS/nameserver nodes manage + zone sync. */
final class DnsClusterController extends Controller
{
    public function index(): View
    {
        return view('dns-cluster.index', [
            'nodes' => DnsClusterNode::query()->orderBy('hostname')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hostname' => 'required|string|max:120',
            'ip'       => 'required|ip',
            'role'     => 'required|in:dns,ns',
        ]);

        DnsClusterNode::query()->updateOrCreate(
            ['hostname' => $data['hostname']],
            ['ip' => $data['ip'], 'role' => $data['role'], 'status' => 'added'],
        );

        return redirect('/dns-cluster');
    }

    public function destroy(DnsClusterNode $dnsClusterNode): RedirectResponse
    {
        $dnsClusterNode->delete();

        return redirect('/dns-cluster');
    }

    /** Sab nodes par zones sync karo (server par agent BIND sync karta hai). */
    public function sync(): RedirectResponse
    {
        DnsClusterNode::query()->update([
            'status'         => 'synced',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect('/dns-cluster');
    }
}
