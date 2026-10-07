<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

/**
 * cPanel "Git Version Control" — repos list/clone/pull/status.
 * Sab git ops Process facade se (sandbox-testable via Process::fake).
 * Security: sirf config('acp.git_base') ke andar ke dirs (realpath guard).
 */
final class GitController extends Controller
{
    private function base(): string
    {
        return rtrim((string) (config('acp.git_base') ?: storage_path('app/git')), '/');
    }

    public function index(): View
    {
        $base  = $this->base();
        $repos = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $d) {
                if ($d === '.' || $d === '..') {
                    continue;
                }
                if (is_dir($base . '/' . $d . '/.git')) {
                    $repos[] = $d;
                }
            }
        }

        return view('git.index', ['repos' => $repos]);
    }

    public function clone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'required|url',
            'dir' => 'required|string|regex:/^[a-z0-9._-]+$/i',
        ]);

        $target = $this->base() . '/' . $data['dir'];

        Process::timeout(120)->run('git clone -- ' . escapeshellarg($data['url']) . ' ' . escapeshellarg($target));

        return redirect('/git');
    }

    public function pull(string $dir): RedirectResponse
    {
        $path = $this->resolve($dir);
        if ($path === null) {
            return redirect('/git');
        }

        Process::timeout(120)->run('git -C ' . escapeshellarg($path) . ' pull --ff-only');

        return redirect('/git');
    }

    public function status(string $dir): View|RedirectResponse
    {
        $path = $this->resolve($dir);
        if ($path === null) {
            return redirect('/git');
        }

        $result = Process::timeout(30)->run(['git', '-C', $path, 'status', '--porcelain']);

        return view('git.status', ['dir' => $dir, 'output' => $result->output()]);
    }

    private function resolve(string $dir): ?string
    {
        $base = realpath($this->base());
        if ($base === false) {
            return null;
        }

        $path = realpath($base . '/' . $dir);
        if ($path === false || ! str_starts_with($path, $base . '/')) {
            return null;
        }

        return $path;
    }
}
