<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TransferReview;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM Review Transfers and Restores — the REAL job history of every cpmove
 * import (paneld `backup.cpanel` / `backup.transfer`) with the agent's result:
 * file/dir counts, skipped sections (mysql/mail/dns) and errors.
 *
 * The manual review-note form below it stays (audit trail), but the table is
 * the source of truth for transfer status.
 */
class TransferReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-review.index', [
            'row' => TransferReview::query()->orderByDesc('id')->first(),
            'statuses' => Backup::REVIEW_STATUSES,
            'jobs' => $this->jobs(),
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

    /**
     * @return list<array{id:int,type:string,status:string,username:string,archive:string,source:string,host:string,fingerprint:string,files:int,bytes:int,sections:string,error:string,created_at:string}>
     */
    private function jobs(): array
    {
        return Paneld::recentJobs(['backup.cpanel', 'backup.transfer', 'db.restore', 'backup.pull'], 25)
            ->map(static function (object $task): array {
                $payload = json_decode((string) $task->payload, true);
                $result = json_decode((string) ($task->result ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $result = is_array($result) ? $result : [];
                $sections = $result['sections'] ?? [];
                $sections = is_array($sections) ? implode(', ', array_map('strval', $sections)) : '';

                return [
                    'id' => (int) $task->id,
                    'type' => (string) $task->type,
                    'status' => (string) $task->status,
                    'username' => (string) ($payload['username'] ?? ''),
                    'archive' => (string) ($payload['archive_path'] ?? $result['path'] ?? $payload['remote_path'] ?? ''),
                    'host' => (string) ($result['host'] ?? $payload['host'] ?? ''),
                    'fingerprint' => (string) ($result['fingerprint'] ?? ''),
                    'source' => (string) ($result['source'] ?? $payload['source'] ?? ($task->type === 'backup.pull' ? ($payload['host'] ?? '') : '')),
                    'files' => (int) ($result['files'] ?? 0),
                    'bytes' => (int) ($result['bytes'] ?? 0),
                    'sections' => $sections,
                    'error' => trim((string) ($task->error ?? '')),
                    'created_at' => (string) ($task->created_at ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Review Transfers and Restores is a WHM tool.');
        }
    }
}
