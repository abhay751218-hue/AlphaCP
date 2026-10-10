<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Files;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel File Manager — home-jailed via paneld files.list / files.set. */
class FilesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $path = Files::tryRel((string) $request->query('path', 'public_html'));
        if ($path === null) {
            $path = 'public_html';
        }
        $entries = [];
        $rootDirs = [];
        if ($account !== null && ! app()->environment('testing')) {
            $result = Paneld::run('files.list', [
                'username' => $account->username,
                'path' => $path,
            ], 8);
            $entries = is_array($result['entries'] ?? null) ? $result['entries'] : [];
            // D29: left directory tree (cPanel-parity) — home-level folders
            if ($path === '') {
                $rootDirs = $entries;
            } else {
                $rootRes = Paneld::run('files.list', [
                    'username' => $account->username,
                    'path' => '',
                ], 8);
                $rootDirs = is_array($rootRes['entries'] ?? null) ? $rootRes['entries'] : [];
            }
            $rootDirs = array_values(array_filter($rootDirs, static fn ($e) => ($e['type'] ?? '') === 'dir'));
        }

        return view('files.index', [
            'account' => $account,
            'path' => $path,
            'parent' => Files::parent($path),
            'entries' => $entries,
            'rootDirs' => $rootDirs,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function mkdir(Request $request): RedirectResponse
    {
        return $this->mutate($request, 'mkdir', ['name' => ['required', 'string', 'max:80']]);
    }

    public function write(Request $request): RedirectResponse
    {
        return $this->mutate($request, 'write', [
            'name' => ['required', 'string', 'max:80'],
            'content' => ['nullable', 'string', 'max:262144'],
        ]);
    }

    public function rename(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
            'to' => ['required', 'string', 'max:240'],
        ]);
        $from = Files::tryRel($data['path']);
        $to = Files::tryRel($data['to']);
        if ($from === null || $from === '' || $to === null || $to === '') {
            return back()->withErrors(['path' => 'Invalid path (no ..).'])->withInput();
        }
        $this->enqueue($account, 'rename', $from, ['to' => $to]);

        return redirect()->route('files.index', ['path' => Files::parent($from)])
            ->with('success', 'Rename is queued.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
        ]);
        $path = Files::tryRel($data['path']);
        if ($path === null || $path === '') {
            return back()->withErrors(['path' => 'Invalid path (no ..).']);
        }
        $this->enqueue($account, 'delete', $path);

        return redirect()->route('files.index', ['path' => Files::parent($path)])
            ->with('success', 'Delete is queued.');
    }

    /** D15 — chmod (permissions). */
    public function chmod(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
            'mode' => ['required', 'string', 'in:644,600,640,664,755,750,700,775'],
        ]);
        $path = Files::tryRel($data['path']);
        if ($path === null || $path === '') {
            return back()->withErrors(['path' => 'Invalid path (no ..).']);
        }
        $this->enqueue($account, 'chmod', $path, ['mode' => $data['mode']]);

        return redirect()->route('files.index', ['path' => Files::parent($path)])
            ->with('success', "Permissions {$data['mode']} queued.");
    }

    /** D15 — compress to <path>.tar.gz (cPanel Compress). */
    public function compress(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate(['path' => ['required', 'string', 'max:236']]);
        $path = Files::tryRel($data['path']);
        if ($path === null || $path === '') {
            return back()->withErrors(['path' => 'Invalid path (no ..).']);
        }
        $this->enqueue($account, 'compress', $path);

        return redirect()->route('files.index', ['path' => Files::parent($path)])
            ->with('success', 'Compress (tar.gz) is queued.');
    }

    /** D15 — extract .tar.gz / .tgz in-place (cPanel Extract). */
    public function extract(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate(['path' => ['required', 'string', 'max:240']]);
        $path = Files::tryRel($data['path']);
        if ($path === null || $path === '' || (! str_ends_with($path, '.tar.gz') && ! str_ends_with($path, '.tgz'))) {
            return back()->withErrors(['path' => 'Sirf .tar.gz / .tgz extract hota hai.']);
        }
        $this->enqueue($account, 'extract', $path);

        return redirect()->route('files.index', ['path' => Files::parent($path)])
            ->with('success', 'Extract is queued.');
    }

    /** D15 — browser upload (staging file -> agent moves with ownership). */
    public function upload(Request $request): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate([
            'dir'  => ['nullable', 'string', 'max:236'],
            'file' => ['required', 'file', 'max:65536'], // 64 MB (KB me)
        ]);
        $dir = Files::tryRel((string) ($data['dir'] ?? 'public_html'));
        if ($dir === null) {
            return back()->withErrors(['file' => 'Invalid folder path.']);
        }
        $uploaded = $request->file('file');
        $orig = (string) $uploaded->getClientOriginalName();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'upload.bin';
        $name = ltrim(substr($name, 0, 80), '.') ?: 'upload.bin';

        $stagingDir = storage_path('app/fm-staging');
        if (! is_dir($stagingDir)) {
            @mkdir($stagingDir, 0750, true);
        }
        // purani staging files (1 ghanta+) saaf
        foreach (glob($stagingDir . '/*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $stagingName = bin2hex(random_bytes(8)) . '-' . $name;
        $uploaded->move($stagingDir, $stagingName);

        $path = $dir === '' ? $name : $dir . '/' . $name;
        $this->enqueue($account, 'upload', $path, ['staging' => $stagingDir . '/' . $stagingName]);

        return redirect()->route('files.index', ['path' => $dir])
            ->with('success', "Upload '{$name}' is queued — kuch seconds me list me aa jayegi.");
    }

    /** @param array<string, mixed> $rules */
    private function mutate(Request $request, string $op, array $rules): RedirectResponse
    {
        $account = $this->guardAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }
        $data = $request->validate($rules + [
            'dir' => ['nullable', 'string', 'max:240'],
        ]);
        $dir = Files::tryRel((string) ($data['dir'] ?? 'public_html'));
        $name = Files::tryRel((string) $data['name']);
        if ($dir === null || $name === null || $name === '' || str_contains($name, '/')) {
            return back()->withErrors(['name' => 'Invalid name (no .. or slash).'])->withInput();
        }
        $path = $dir === '' ? $name : $dir . '/' . $name;
        $extra = [];
        if ($op === 'write') {
            $content = (string) ($data['content'] ?? '');
            if (str_contains($content, "\0") || strlen($content) > Files::MAX_WRITE) {
                return back()->withErrors(['content' => 'Content 256 KiB max, no null byte.'])->withInput();
            }
            $extra['content'] = $content;
        }
        $this->enqueue($account, $op, $path, $extra);

        return redirect()->route('files.index', ['path' => $dir])
            ->with('success', "File Manager '{$op}' is queued.");
    }

    /** @param array<string, mixed> $extra */
    private function enqueue(Account $account, string $op, string $path, array $extra = []): void
    {
        AccountProvisioner::enqueue($account, 'files.set', array_merge([
            'username' => $account->username,
            'op' => $op,
            'path' => $path,
        ], $extra));
        $account->recordEvent('files.set.queued', $op . ' ' . $path);
        Audit::log('files.set', 'info', 'account', $account->id, ['op' => $op, 'path' => $path]);
    }

    private function guardAccount(Request $request): Account|RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['path' => 'Cannot change File Manager on a suspended/terminated account.']);
        }

        return $account;
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
