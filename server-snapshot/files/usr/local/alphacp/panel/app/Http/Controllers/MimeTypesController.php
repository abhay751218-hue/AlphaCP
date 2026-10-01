<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\MimeTypes;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel MIME Types — Apache AddType via paneld mime.set. */
class MimeTypesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $current = MimeTypes::sanitize(is_array($account?->meta['mime_types'] ?? null) ? $account->meta['mime_types'] : []);

        return view('mime.index', [
            'account' => $account,
            'mappings' => $current,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['mime' => 'Cannot change MIME types on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'mime' => ['required', 'string', 'max:80'],
            'ext' => ['required', 'string', 'max:80'],
        ]);
        $mime = MimeTypes::tryMime($data['mime']);
        $exts = preg_split('/[\s,]+/', strtolower(trim($data['ext'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleanExts = [];
        foreach ($exts as $rawExt) {
            $ext = MimeTypes::tryExt((string) $rawExt);
            if ($ext !== null) {
                $cleanExts[] = $ext;
            }
        }
        if ($mime === null || $cleanExts === []) {
            return back()->withErrors(['mime' => 'Invalid MIME type ya blocked extension (php/cgi/ssi nahi).'])->withInput();
        }

        $current = MimeTypes::sanitize(is_array($account->meta['mime_types'] ?? null) ? $account->meta['mime_types'] : []);
        foreach ($cleanExts as $ext) {
            $current[] = ['mime' => $mime, 'ext' => $ext];
        }
        $current = MimeTypes::sanitize($current);
        if (count($current) > MimeTypes::MAX) {
            return back()->withErrors(['mime' => '50 MIME mappings max.'])->withInput();
        }

        $this->persist($account, $current);

        return redirect()->route('mime.index')->with('success', 'MIME type is queued.');
    }

    public function destroy(Request $request, string $ext): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $keep = [];
        $want = strtolower(ltrim($ext, '.'));
        foreach (MimeTypes::sanitize(is_array($account->meta['mime_types'] ?? null) ? $account->meta['mime_types'] : []) as $row) {
            if ($row['ext'] !== $want) {
                $keep[] = $row;
            }
        }
        $this->persist($account, $keep);

        return redirect()->route('mime.index')->with('success', 'MIME type is queued for removal.');
    }

    /** @param list<array{mime: string, ext: string}> $mappings */
    private function persist(Account $account, array $mappings): void
    {
        $meta = $account->meta ?? [];
        $meta['mime_types'] = $mappings;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'mime.set', [
            'username' => $account->username,
            'mappings' => $mappings,
        ]);
        $account->recordEvent('mime.set.queued', (string) count($mappings));
        Audit::log('mime.set', 'info', 'account', $account->id, ['count' => count($mappings)]);
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
