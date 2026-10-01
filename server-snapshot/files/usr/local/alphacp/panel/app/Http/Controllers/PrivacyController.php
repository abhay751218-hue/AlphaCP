<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Files;
use App\Support\ModuleCatalog;
use App\Support\Privacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Directory Privacy — Apache Basic Auth via paneld privacy.set. */
class PrivacyController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $entries = Privacy::sanitize(is_array($account?->meta['privacy'] ?? null) ? $account->meta['privacy'] : []);

        return view('privacy.index', [
            'account' => $account,
            'entries' => $entries,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['path' => 'Suspended/terminated account par Directory Privacy nahi.']);
        }
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
            'realm' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:5', 'max:72'],
        ]);
        $path = Files::tryRel($data['path']);
        $realm = Privacy::tryRealm((string) ($data['realm'] ?? 'Protected'));
        $name = Privacy::tryUser($data['name']);
        $hash = Privacy::hashPassword($data['password']);
        if ($path === null || $path === '' || $realm === null || $name === null || $hash === null) {
            return back()->withErrors(['path' => 'Invalid path/user (.. nahi) ya password 5–72 chars.'])->withInput();
        }

        $current = Privacy::sanitize(is_array($account->meta['privacy'] ?? null) ? $account->meta['privacy'] : []);
        $found = false;
        foreach ($current as &$row) {
            if ($row['path'] !== $path) {
                continue;
            }
            $found = true;
            $row['realm'] = $realm;
            $users = [];
            foreach ($row['users'] as $u) {
                $users[$u['name']] = $u;
            }
            $users[$name] = ['name' => $name, 'hash' => $hash];
            $row['users'] = array_values($users);
        }
        unset($row);
        if (! $found) {
            $current[] = [
                'path' => $path,
                'realm' => $realm,
                'users' => [['name' => $name, 'hash' => $hash]],
            ];
        }
        $current = Privacy::sanitize($current);
        if (count($current) > Privacy::MAX) {
            return back()->withErrors(['path' => '20 protected folders max.'])->withInput();
        }
        $this->persist($account, $current);

        return redirect()->route('privacy.index')->with('success', 'Directory Privacy queue me hai.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
        ]);
        $want = Files::tryRel($data['path']);
        $keep = [];
        foreach (Privacy::sanitize(is_array($account->meta['privacy'] ?? null) ? $account->meta['privacy'] : []) as $row) {
            if ($want === null || $row['path'] !== $want) {
                $keep[] = $row;
            }
        }
        $this->persist($account, $keep);

        return redirect()->route('privacy.index')->with('success', 'Protection hataane ke liye queue me hai.');
    }

    /** @param list<array{path: string, realm: string, users: list<array{name: string, hash: string}>}> $entries */
    private function persist(Account $account, array $entries): void
    {
        $meta = $account->meta ?? [];
        $meta['privacy'] = $entries;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'privacy.set', [
            'username' => $account->username,
            'entries' => $entries,
        ]);
        $account->recordEvent('privacy.set.queued', (string) count($entries));
        Audit::log('privacy.set', 'info', 'account', $account->id, ['count' => count($entries)]);
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
