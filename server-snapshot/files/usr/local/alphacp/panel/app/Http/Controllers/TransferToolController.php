<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TransferTool;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\Dns;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Transfer Tool — cPanel→AlphaCP via paneld backup.transfer. No tar, no rsync, no shell, no pipe. */
class TransferToolController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-tool.index', [
            'row' => TransferTool::query()->orderByDesc('id')->first(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'source' => ['required', 'string', 'max:190'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $source = Dns::tryDomain($data['source']);
        if ($username === null || $source === null) {
            return back()->withErrors(['source' => 'Invalid transfer. Username 3–16 a-z/0-9. Source FQDN. No pipe/path.'])->withInput();
        }
        $row = TransferTool::query()->orderByDesc('id')->first();
        if ($row === null) {
            TransferTool::query()->create([
                'username' => $username,
                'source' => $source,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'source' => $source,
            ]);
        }
        BackupProvisioner::enqueueTransfer($username, $source);
        Audit::log('backup.transfer', 'info', 'system', null, ['username' => $username, 'source' => $source]);

        return redirect()->route('transfer-tool.index')->with('success', 'Transfer is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Transfer Tool is a WHM tool.');
        }
    }
}
