<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Network Tools" — DNS lookup (dig-jaisa) pure-PHP dns_get_record se.
 * Koi shell/agent call nahi — sirf resolver queries (read-only, fail-closed).
 */
final class NetworkToolsController extends Controller
{
    private const TYPES = [
        'A'     => DNS_A,
        'AAAA'  => DNS_AAAA,
        'MX'    => DNS_MX,
        'NS'    => DNS_NS,
        'TXT'   => DNS_TXT,
        'CNAME' => DNS_CNAME,
    ];

    public function index(): View
    {
        return view('network-tools.index', [
            'types'   => array_keys(self::TYPES),
            'query'   => session('nt_query'),
            'type'    => session('nt_type'),
            'records' => session('nt_records'),
            'error'   => session('nt_error'),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:190'],
            'type'  => ['required', 'string', 'in:' . implode(',', array_keys(self::TYPES))],
        ]);

        $host = strtolower(trim($data['query']));
        if (preg_match('/^(?=.{1,190}$)[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?)+$/', $host) !== 1) {
            return redirect()->route('network-tools.index')->with('nt_error', 'Domain name sahi nahi lag raha (e.g. example.com).');
        }

        $records = @dns_get_record($host, self::TYPES[$data['type']]);
        if (! is_array($records)) {
            $records = [];
        }

        $rows = [];
        foreach (array_slice($records, 0, 50) as $r) {
            $rows[] = [
                'host'  => (string) ($r['host'] ?? $host),
                'type'  => (string) ($r['type'] ?? $data['type']),
                'ttl'   => (int) ($r['ttl'] ?? 0),
                'value' => (string) ($r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? (is_array($r['txt'] ?? null) ? implode(' ', $r['txt']) : ($r['txt'] ?? ''))),
                'prio'  => isset($r['pri']) ? (int) $r['pri'] : null,
            ];
        }

        return redirect()->route('network-tools.index')->with([
            'nt_query'   => $host,
            'nt_type'    => $data['type'],
            'nt_records' => $rows,
            'nt_error'   => $rows === [] ? 'Koi record nahi mila (' . $data['type'] . ' @ ' . $host . ').' : null,
        ]);
    }
}
