<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WebDiskAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Web Disk" — WebDAV accounts (read-only / read-write). */
final class WebDiskController extends Controller
{
    public function index(Request $request): View
    {
        return view('webdisk.index', [
            'accounts' => WebDiskAccount::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('login')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login'       => 'required|string|regex:/^[a-z0-9._-]+$/i|max:60',
            'permissions' => 'required|in:ro,rw',
        ]);

        WebDiskAccount::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'login' => $data['login']],
            ['permissions' => $data['permissions']],
        );

        return redirect('/webdisk');
    }

    public function destroy(WebDiskAccount $webDiskAccount, Request $request): RedirectResponse
    {
        if ($webDiskAccount->user_id === $request->user()->id) {
            $webDiskAccount->delete();
        }

        return redirect('/webdisk');
    }
}
