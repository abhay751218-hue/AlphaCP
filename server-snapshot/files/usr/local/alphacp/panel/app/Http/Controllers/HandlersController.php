<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Handlers;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Apache Handlers — AddHandler via paneld handlers.set. */
class HandlersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $current = Handlers::sanitize(is_array($account?->meta['handlers'] ?? null) ? $account->meta['handlers'] : []);

        return view('handlers.index', [
            'account' => $account,
            'mappings' => $current,
            'handlers' => Handlers::ALLOWED,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['handler' => 'Cannot change handlers on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'handler' => ['required', 'string', 'max:64'],
            'ext' => ['required', 'string', 'max:80'],
        ]);
        $handler = Handlers::tryHandler($data['handler']);
        $exts = preg_split('/[\s,]+/', strtolower(trim($data['ext'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleanExts = [];
        foreach ($exts as $rawExt) {
            $ext = Handlers::tryExt((string) $rawExt);
            if ($ext !== null) {
                $cleanExts[] = $ext;
            }
        }
        if ($handler === null || $cleanExts === []) {
            return back()->withErrors(['handler' => 'Invalid handler ya blocked extension (php/proxy nahi).'])->withInput();
        }

        $current = Handlers::sanitize(is_array($account->meta['handlers'] ?? null) ? $account->meta['handlers'] : []);
        foreach ($cleanExts as $ext) {
            $current[] = ['handler' => $handler, 'ext' => $ext];
        }
        $current = Handlers::sanitize($current);
        if (count($current) > Handlers::MAX) {
            return back()->withErrors(['handler' => '50 handler mappings max.'])->withInput();
        }

        $this->persist($account, $current);

        return redirect()->route('handlers.index')->with('success', 'Apache handler is queued.');
    }

    public function destroy(Request $request, string $ext): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $keep = [];
        $want = strtolower(ltrim($ext, '.'));
        foreach (Handlers::sanitize(is_array($account->meta['handlers'] ?? null) ? $account->meta['handlers'] : []) as $row) {
            if ($row['ext'] !== $want) {
                $keep[] = $row;
            }
        }
        $this->persist($account, $keep);

        return redirect()->route('handlers.index')->with('success', 'Handler is queued for removal.');
    }

    /** @param list<array{handler: string, ext: string}> $mappings */
    private function persist(Account $account, array $mappings): void
    {
        $meta = $account->meta ?? [];
        $meta['handlers'] = $mappings;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'handlers.set', [
            'username' => $account->username,
            'mappings' => $mappings,
        ]);
        $account->recordEvent('handlers.set.queued', (string) count($mappings));
        Audit::log('handlers.set', 'info', 'account', $account->id, ['count' => count($mappings)]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
