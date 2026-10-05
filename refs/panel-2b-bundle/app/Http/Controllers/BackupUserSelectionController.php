<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BackupUserSelection;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Backup User Selection — usernames via paneld backup.users. No tar, no shell, no pipe. */
class BackupUserSelectionController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('backup-user-selection.index', [
            'rows' => BackupUserSelection::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
        ]);
        $username = Backup::tryUsername($data['username']);
        if ($username === null) {
            return back()->withErrors(['username' => 'Invalid backup user. Username 3–16 a-z/0-9. No pipe/path.'])->withInput();
        }
        $exists = BackupUserSelection::query()->where('username', $username)->exists();
        if (! $exists) {
            if (BackupProvisioner::userSelectionLimitReached()) {
                return back()->withErrors(['username' => 'Backup user limit reached (10).'])->withInput();
            }
            BackupUserSelection::query()->create([
                'username' => $username,
            ]);
        }
        BackupProvisioner::enqueueUsers();
        Audit::log('backup.users', 'info', 'system', null, ['username' => $username]);

        return redirect()->route('backup-user-selection.index')->with('success', 'Backup user selection is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Backup User Selection is a WHM tool.');
        }
    }
}
