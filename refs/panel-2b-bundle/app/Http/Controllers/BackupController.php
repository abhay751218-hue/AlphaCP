<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BackupJob;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\Files;
use App\Support\ModuleCatalog;
use App\Support\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** cPanel Backup — configuration plus real, account-scoped home archives. */
class BackupController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('backup.index', [
            'account' => $account,
            'rows' => $account?->backupJobs()->orderBy('id')->get() ?? collect(),
            'kinds' => Backup::KINDS,
            'archiveTasks' => $this->archiveTasks($account),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['kind' => 'Cannot queue backup on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:16'],
            'path' => ['nullable', 'string', 'max:240'],
        ]);
        $kind = Backup::tryKind($data['kind']);
        $pathRaw = (string) ($data['path'] ?? '');
        $path = '';
        if ($kind === null) {
            return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
        }
        if ($kind !== 'home') {
            if (trim($pathRaw) !== '') {
                return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
            }
        } else {
            $path = Files::tryRel($pathRaw);
            if ($path === null) {
                return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
            }
        }
        $exists = BackupJob::query()
            ->where('account_id', $account->id)
            ->where('kind', $kind)
            ->where('path', $path)
            ->exists();
        if (! $exists) {
            if (BackupProvisioner::limitReached($account)) {
                return back()->withErrors(['kind' => 'Backup job limit reached (10).']);
            }
            BackupJob::query()->create([
                'account_id' => $account->id,
                'kind' => $kind,
                'path' => $path,
            ]);
        }
        BackupProvisioner::enqueue($account);
        $account->recordEvent('backup.create.queued', $kind . ($path !== '' ? ':' . $path : ''));
        Audit::log('backup.create', 'info', 'account', $account->id, ['kind' => $kind, 'path' => $path]);

        return redirect()->route('backup.index')->with('success', 'Backup job configuration is saved.');
    }

    public function archive(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['archive' => 'Cannot back up a suspended/terminated account.']);
        }
        $archiveId = bin2hex(random_bytes(16));
        $taskId = BackupProvisioner::enqueueArchive($account, $archiveId);
        $account->recordEvent('backup.archive.queued', 'home', ['task_id' => $taskId]);
        Audit::log('backup.archive', 'info', 'account', $account->id, [
            'task_id' => $taskId,
            'archive_id' => $archiveId,
            'scope' => 'home',
        ]);

        return redirect()->route('backup.index')->with('success', 'Home archive queued (task #' . $taskId . '). It will appear below when ready.');
    }

    public function download(Request $request, string $archiveId): BinaryFileResponse
    {
        $account = $this->requireAccount($request);
        if (preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            abort(404);
        }

        $task = DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->where('account_id', $account->id)
            ->where('type', 'backup.archive')
            ->where('status', 'success')
            ->where('result', 'like', '%' . $archiveId . '%')
            ->orderByDesc('id')
            ->first();
        $result = json_decode((string) ($task->result ?? ''), true);
        if (!is_array($result)
            || ($result['archive_id'] ?? null) !== $archiveId
            || ($result['username'] ?? null) !== $account->username
            || ($result['scope'] ?? null) !== 'home'
            || ($result['filename'] ?? null) !== $archiveId . '.tar.gz'
            || !is_int($result['size_bytes'] ?? null)
            || !is_string($result['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $result['sha256']) !== 1) {
            abort(404);
        }

        $home = rtrim((string) config('acp.home'), '/');
        $backupBase = $home . '/backups';
        $archiveRoot = $backupBase . '/accounts';
        $accountDir = $archiveRoot . '/' . $account->username;
        $archivePath = $accountDir . '/' . $archiveId . '.tar.gz';
        if (is_link($home) || is_link($backupBase) || is_link($archiveRoot) || is_link($accountDir) || is_link($archivePath)) {
            abort(404);
        }
        $realAccountDir = realpath($accountDir);
        $realArchive = realpath($archivePath);
        if ($realAccountDir === false || $realArchive === false || !is_file($realArchive)
            || !str_starts_with($realArchive, $realAccountDir . DIRECTORY_SEPARATOR)
            || !is_readable($realArchive)) {
            abort(404);
        }
        $size = filesize($realArchive);
        $sha256 = hash_file('sha256', $realArchive);
        if ($size !== $result['size_bytes'] || !is_string($sha256) || !hash_equals($result['sha256'], $sha256)) {
            abort(404);
        }

        return response()->download(
            $realArchive,
            'alphacp-' . $account->username . '-home-' . $archiveId . '.tar.gz',
            ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store'],
        );
    }

    /** @return list<array{id:int,status:string,created_at:string,archive_id:?string,filename:?string,size_bytes:?int,downloadable:bool}> */
    private function archiveTasks(?Account $account): array
    {
        if ($account === null) {
            return [];
        }
        return DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->where('account_id', $account->id)
            ->where('type', 'backup.archive')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static function (object $task) use ($account): array {
                $result = json_decode((string) ($task->result ?? ''), true);
                $id = is_array($result) && is_string($result['archive_id'] ?? null) ? $result['archive_id'] : null;
                $downloadable = $task->status === 'success'
                    && $id !== null
                    && preg_match('/^[a-f0-9]{32}$/', $id) === 1
                    && ($result['username'] ?? null) === $account->username
                    && ($result['scope'] ?? null) === 'home'
                    && ($result['filename'] ?? null) === $id . '.tar.gz'
                    && is_int($result['size_bytes'] ?? null)
                    && is_string($result['sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1;

                return [
                    'id' => (int) $task->id,
                    'status' => (string) $task->status,
                    'created_at' => (string) $task->created_at,
                    'archive_id' => $id,
                    'filename' => $downloadable ? (string) $result['filename'] : null,
                    'size_bytes' => $downloadable ? (int) $result['size_bytes'] : null,
                    'downloadable' => $downloadable,
                ];
            })
            ->all();
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'backupJobs']);
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
