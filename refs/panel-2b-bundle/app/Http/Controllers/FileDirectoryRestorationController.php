<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FileDirectoryRestoration;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\Files;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM File and Directory Restoration — username+path via paneld backup.filedir. No tar, no shell, no pipe. */
class FileDirectoryRestorationController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('file-directory-restoration.index', [
            'row' => FileDirectoryRestoration::query()->orderByDesc('id')->first(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'path' => ['required', 'string', 'max:240'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $path = Files::tryRel($data['path']);
        if ($username === null || $path === null || $path === '') {
            return back()->withErrors(['path' => 'Invalid file/directory restoration. Username 3–16 a-z/0-9. Relative path, no pipe/path escape.'])->withInput();
        }
        $row = FileDirectoryRestoration::query()->orderByDesc('id')->first();
        if ($row === null) {
            FileDirectoryRestoration::query()->create([
                'username' => $username,
                'path' => $path,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'path' => $path,
            ]);
        }
        BackupProvisioner::enqueueFiledir($username, $path);
        Audit::log('backup.filedir', 'info', 'system', null, ['username' => $username, 'path' => $path]);

        return redirect()->route('file-directory-restoration.index')->with('success', 'File and directory restoration is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'File and Directory Restoration is a WHM tool.');
        }
    }
}
