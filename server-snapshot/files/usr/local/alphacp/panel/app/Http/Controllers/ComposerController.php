<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "PHP Composer" v1 — account ki ASLI composer.json padh kar
 * dependencies dikhata hai (root agent `terminal.run cat`, read-only,
 * PathGuard /home). `composer install/update` SSH se chalta hai.
 */
final class ComposerController extends Controller
{
    public function index(Request $request): View
    {
        $account  = $this->accountFor($request);
        $agentOk  = in_array('terminal.run', Paneld::taskTypes(), true);
        $found    = null;
        $packages = [];
        $devPackages = [];
        $parseError  = null;

        if ($account !== null && $agentOk) {
            foreach ([
                '/home/' . $account->username . '/public_html/composer.json',
                '/home/' . $account->username . '/composer.json',
            ] as $candidate) {
                $res = Paneld::run('terminal.run', ['command' => 'cat ' . $candidate], 20);
                if (is_array($res) && ($res['status'] ?? '') === 'ok' && trim((string) ($res['output'] ?? '')) !== '') {
                    $decoded = json_decode(trim((string) $res['output']), true);
                    if (is_array($decoded)) {
                        $found    = $candidate;
                        $packages = $this->packList($decoded['require'] ?? []);
                        $devPackages = $this->packList($decoded['require-dev'] ?? []);
                    } else {
                        $found      = $candidate;
                        $parseError = 'composer.json mili lekin valid JSON nahi hai.';
                    }
                    break;
                }
            }
        }

        return view('composer.index', [
            'account'     => $account,
            'agentOk'     => $agentOk,
            'found'       => $found,
            'packages'    => $packages,
            'devPackages' => $devPackages,
            'parseError'  => $parseError,
            'panelMode'   => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    /** @return list<array{name: string, constraint: string}> */
    private function packList(mixed $require): array
    {
        if (! is_array($require)) {
            return [];
        }
        $rows = [];
        foreach ($require as $name => $constraint) {
            if (! is_string($name) || ! is_string($constraint)) {
                continue;
            }
            $rows[] = ['name' => $name, 'constraint' => $constraint];
        }

        return $rows;
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
