<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PortConfig;
use App\Support\Paneld;
use App\Support\PortMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Owner Ports Control — OWNER-CTRL slice ("ek panel = ek port").
 *
 * Char mappings: WHM (2087), cPanel (2083), Webmail (2096), link-page (8090,
 * band ki ja sakti hai). Save par: DB (port_configs single row) +
 * etc/ports.json + agent task `ports.apply` (nginx vhosts regen + reload).
 *
 * Purana multi-port ssl-list shape abandon ho gaya: ek panel kai ports par
 * khulna product rule ke khilaaf tha.
 */
final class PortsController extends Controller
{
    public function index(): View
    {
        return view('ports.index', [
            'cfg'   => PortMap::all(),
            'applied' => $this->agentStatus(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whm'          => ['required', 'integer', 'min:1024', 'max:65535'],
            'cpanel'       => ['required', 'integer', 'min:1024', 'max:65535'],
            'webmail'      => ['required', 'integer', 'min:1024', 'max:65535'],
            'link'         => ['required', 'integer', 'min:1024', 'max:65535'],
            'link_enabled' => ['nullable', 'boolean'],
            'distinct'     => ['nullable'],
        ]);

        $cfg = [
            'whm'          => (int) $data['whm'],
            'cpanel'       => (int) $data['cpanel'],
            'webmail'      => (int) $data['webmail'],
            'link'         => (int) $data['link'],
            'link_enabled' => $request->boolean('link_enabled'),
        ];

        // charon ports alag-alag hone chahiye (ek panel = ek port)
        if (count(array_unique([$cfg['whm'], $cfg['cpanel'], $cfg['webmail'], $cfg['link']])) !== 4) {
            return back()->withErrors(['whm' => 'Charon ports alag-alag hone chahiye.'])->withInput();
        }

        $rec = PortConfig::query()->first();
        if ($rec) {
            $rec->update(['data' => $cfg]);
        } else {
            PortConfig::query()->create(['data' => $cfg]);
        }

        try {
            File::put($this->portsFile(), json_encode($cfg, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // DB source-of-truth hai; agent/apply-step file sync bhi karta hai.
        }

        PortMap::flush();

        // agent se nginx vhosts turant regen karwao
        $applied = true;
        try {
            $res     = Paneld::run('ports.apply', [], 60);
            $applied = (bool) ($res['applied'] ?? false);
        } catch (\Throwable) {
            $applied = false;
        }

        return redirect('/ports')->with(
            $applied ? 'success' : 'warning',
            $applied
                ? 'Ports save + nginx par apply ho gaye (vhosts regen + reload).'
                : 'Ports save ho gaye magar agent apply fail hua — dobara try karein ya agent log dekhein.',
        );
    }

    /** @return array<string,mixed>|null */
    private function agentStatus(): ?array
    {
        try {
            $res = Paneld::run('ports.apply', ['action' => 'status'], 15);

            return is_array($res) ? $res : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function portsFile(): string
    {
        return (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
    }
}
