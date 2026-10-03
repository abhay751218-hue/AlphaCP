<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TransferReview;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Review Transfers and Restores via paneld backup.review. No tar, no rsync, no shell, no pipe. */
class TransferReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-review.index', [
            'row' => TransferReview::query()->orderByDesc('id')->first(),
            'statuses' => Backup::REVIEW_STATUSES,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'status' => ['required', 'string', 'max:16'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $status = Backup::tryReviewStatus($data['status']);
        if ($username === null || $status === null) {
            return back()->withErrors(['status' => 'Invalid review job. Status pending/ok/failed. Username 3–16 a-z/0-9. No pipe/path.'])->withInput();
        }
        $row = TransferReview::query()->orderByDesc('id')->first();
        if ($row === null) {
            TransferReview::query()->create([
                'username' => $username,
                'status' => $status,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'status' => $status,
            ]);
        }
        BackupProvisioner::enqueueReview($username, $status);
        Audit::log('backup.review', 'info', 'system', null, ['username' => $username, 'status' => $status]);

        return redirect()->route('transfer-review.index')->with('success', 'Review job is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Review Transfers and Restores is a WHM tool.');
        }
    }
}
