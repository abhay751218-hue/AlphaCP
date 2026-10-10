<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM "Updates" v1 — asli component versions + ab tak install hue update
 * waves ki history (har installer /var/log/alphacp-*.log chhodta hai —
 * root agent `terminal.run ls /var/log` se wahi padhte hain).
 */
final class UpdatesController extends Controller
{
    public function index(Request $request): View
    {
        $agentOk = in_array('terminal.run', Paneld::taskTypes(), true);

        $versions = [
            'panel'     => (string) config('acp.version', '?'),
            'agent'     => (string) config('acp.agent_version', '?'),
            'php'       => PHP_VERSION,
            'framework' => app()->version(),
        ];

        $node = null;
        $waves = [];
        if ($agentOk) {
            $res = Paneld::run('terminal.run', ['command' => 'node -v'], 15);
            if (is_array($res) && ($res['status'] ?? '') === 'ok') {
                $out = trim((string) ($res['output'] ?? ''));
                if (preg_match('/^v\d+\.\d+\.\d+/', $out, $m) === 1) {
                    $node = $m[0];
                }
            }

            $res = Paneld::run('terminal.run', ['command' => 'ls /var/log'], 15);
            if (is_array($res) && ($res['status'] ?? '') === 'ok') {
                foreach (preg_split('/\s+/', trim((string) ($res['output'] ?? ''))) ?: [] as $file) {
                    if (preg_match('/^alphacp-([a-z0-9][a-z0-9-]*)\.log$/', $file, $m) === 1) {
                        $waves[] = $m[1];
                    }
                }
                sort($waves);
            }
        }

        return view('updates.index', [
            'agentOk'  => $agentOk,
            'versions' => $versions,
            'node'     => $node,
            'waves'    => $waves,
        ]);
    }
}
