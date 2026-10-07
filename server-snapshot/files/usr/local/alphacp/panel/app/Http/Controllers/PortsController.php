<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PortConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Owner Ports Config — owner yahan se panel ports choose karta hai.
 * Save par DB + var/ports.json likhta hai; apply-step (installer/agent) ise nginx par lagata hai.
 * 8090 hamesha primary (brand) port hai.
 */
final class PortsController extends Controller
{
    public const PRIMARY = 8090;
    private const CPANEL_SSL  = [2083, 2087, 2096];
    private const CPANEL_HTTP = [2082, 2086, 2095];

    public function index(): View
    {
        return view('ports.index', ['cfg' => $this->current()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cpanel' => 'nullable|boolean',
            'custom' => 'nullable|string',
        ]);

        $cpanel = $request->boolean('cpanel');

        $custom = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['custom'] ?? '')) ?: [] as $p) {
            $n = (int) $p;
            if ($n > 1023 && $n < 65536 && $n !== self::PRIMARY) {
                $custom[] = $n;
            }
        }
        $custom = array_values(array_unique($custom));

        $ssl  = array_values(array_unique(array_merge([self::PRIMARY], $cpanel ? self::CPANEL_SSL : [], $custom)));
        $http = $cpanel ? self::CPANEL_HTTP : [];

        $cfg = ['ssl' => $ssl, 'http' => $http, 'cpanel' => $cpanel, 'custom' => $custom];

        // single row upsert
        $rec = PortConfig::query()->first();
        if ($rec) {
            $rec->update(['data' => $cfg]);
        } else {
            PortConfig::query()->create(['data' => $cfg]);
        }

        try {
            File::put($this->portsFile(), json_encode($cfg, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // DB source-of-truth hai; file copy agent/apply-step bhi sync karta hai.
        }

        return redirect('/ports')->with('success', 'Ports save ho gaye. Ab apply-step chalayen (ya agent auto-apply).');
    }

    /** @return array{ssl:list<int>,http:list<int>,cpanel:bool,custom:list<int>} */
    private function current(): array
    {
        $rec = PortConfig::query()->first();

        return $rec?->data ?? ['ssl' => [self::PRIMARY], 'http' => [], 'cpanel' => false, 'custom' => []];
    }

    private function portsFile(): string
    {
        // etc/ open_basedir-allowed hai (web user read/write kar sakta hai).
        return (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
    }
}
