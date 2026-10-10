<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Node.js Selector" v1 — server ka ASLI Node runtime detect hota hai
 * (root agent `terminal.run node -v`, read-only). App process-manager
 * (PM2-style create/start/stop) agent ke agle update me aayega.
 */
final class NodejsSelectorController extends Controller
{
    public function index(Request $request): View
    {
        $agentOk     = in_array('terminal.run', Paneld::taskTypes(), true);
        $nodeVersion = null;

        if ($agentOk) {
            $res = Paneld::run('terminal.run', ['command' => 'node -v'], 20);
            if (is_array($res) && ($res['status'] ?? '') === 'ok') {
                $out = trim((string) ($res['output'] ?? ''));
                if (preg_match('/^v\d+\.\d+\.\d+/', $out, $m) === 1) {
                    $nodeVersion = $m[0];
                }
            }
        }

        return view('nodejs.index', [
            'account'     => $this->accountFor($request),
            'agentOk'     => $agentOk,
            'nodeVersion' => $nodeVersion,
            'panelMode'   => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
