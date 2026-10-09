<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Trash" — deleted files ki list + permanent delete (realpath-guarded). */
final class TrashController extends Controller
{
    private function base(): string
    {
        return rtrim((string) (config('acp.trash_base') ?: storage_path('app/trash')), '/');
    }

    public function index(): View
    {
        $base  = $this->base();
        $files = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $files[] = $f;
                }
            }
        }

        return view('trash.index', ['files' => $files]);
    }

    public function destroy(Request $request, string $file): RedirectResponse
    {
        $base = realpath($this->base());
        $path = $base !== false ? realpath($base . '/' . $file) : false;

        if ($path !== false && str_starts_with($path, $base . '/') && is_file($path)) {
            unlink($path);
        }

        return redirect('/trash');
    }
}
