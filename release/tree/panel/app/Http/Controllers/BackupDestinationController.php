<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BackupDestination;
use App\Models\BackupDestinationPush;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupDestinations;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * WHM Backup Destinations (S10) — where this server pushes its archives.
 *
 * The panel stores the description only; every real action (save / test / push /
 * browse / remove) runs inside paneld as `backup.destination`, which keeps the
 * SSH key or password in a 0600 file of its own and re-checks the pinned host
 * key on every single connection.
 */
class BackupDestinationController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('backup-destinations.index', [
            'destinations' => BackupDestination::query()->orderBy('name')->get(),
            'archives'     => $this->pushableArchives(),
            'pushes'       => BackupDestinationPush::query()->orderByDesc('id')->limit(25)->get(),
            'jobs'         => Paneld::recentJobs(['backup.destination'], 8),
            'panelMode'    => 'whm',
        ]);
    }

    /** Create or update a destination. The host key must already be pinned. */
    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:32'],
            'host'             => ['required', 'string', 'max:253'],
            'port'             => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username'         => ['required', 'string', 'max:32'],
            'path'             => ['required', 'string', 'max:255'],
            'auth'             => ['nullable', 'string', 'in:key,password'],
            'private_key'      => ['nullable', 'string', 'max:65536'],
            'password'         => ['nullable', 'string', 'max:1024'],
            'host_fingerprint' => ['nullable', 'string', 'max:128'],
            'retention_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
            'enabled'          => ['nullable', 'boolean'],
        ]);

        $name = Backup::tryDestinationName((string) $data['name']);
        $host = Backup::tryHost((string) $data['host']);
        $user = Backup::tryRemoteUser((string) $data['username']);
        $path = Backup::tryRemotePath((string) $data['path']);
        $fingerprint = Backup::tryFingerprint((string) ($data['host_fingerprint'] ?? ''));
        $port = (int) ($data['port'] ?? 22);
        $auth = ($data['auth'] ?? 'key') === 'password' ? 'password' : 'key';
        $retention = (int) ($data['retention_days'] ?? 30);

        if ($name === null || $host === null || $user === null || $path === null
            || $fingerprint === null || $port < 1 || $port > 65535
            || $retention < 1 || $retention > 365) {
            return back()->withErrors([
                'name' => 'Destination galat hai. Naam a-z/0-9/-, host FQDN ya IP, SSH user, remote path (.. ke bina), fingerprint SHA256:..., port 1-65535, retention 1-365.',
            ])->withInput();
        }
        if ($fingerprint === '') {
            return back()->withErrors([
                'host_fingerprint' => 'Pehle Transfer Tool → "Fingerprint lao" se backup server ki host key verify karo, phir yahan pin karo.',
            ])->withInput();
        }
        if ($auth === 'password' && trim((string) ($data['password'] ?? '')) === '') {
            return back()->withErrors(['password' => 'Password auth chuna hai to password bhi do.'])->withInput();
        }

        $payload = [
            'name'             => $name,
            'host'             => $host,
            'port'             => $port,
            'user'             => $user,
            'path'             => $path,
            'auth'             => $auth,
            'retention_days'   => $retention,
            'host_fingerprint' => $fingerprint,
            'enabled'          => ($data['enabled'] ?? true) !== false,
        ];
        if ($auth === 'password') {
            $payload['password'] = (string) $data['password'];
        } elseif (trim((string) ($data['private_key'] ?? '')) !== '') {
            $payload['private_key'] = (string) $data['private_key'];
        }

        $taskId = BackupProvisioner::enqueueDestination('save', $payload);

        $row = BackupDestination::query()->where('name', $name)->first();
        $values = [
            'type'             => 'ssh',
            'host'             => $host,
            'port'             => $port,
            'username'         => $user,
            'path'             => $path,
            'auth_type'        => $auth,
            'retention_days'   => $retention,
            'host_fingerprint' => $fingerprint,
            'enabled'          => ($data['enabled'] ?? true) !== false,
        ];
        if ($row === null) {
            BackupDestination::query()->create($values + ['name' => $name]);
        } else {
            $row->update($values);
        }

        Audit::log('backup.destination.save', 'info', 'server', null, [
            'task_id' => $taskId, 'name' => $name, 'host' => $host, 'auth' => $auth, 'pinned' => true,
        ]);

        return redirect()->route('backup-destinations.index')
            ->with('success', "Destination '{$name}' queue ho gaya (task #{$taskId}) — Result me public key / status dikhega.");
    }

    /** Connectivity test: writes, reads and deletes a probe file on the far side. */
    public function test(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:32']]);
        $row = $this->destination($data);
        if ($row === null) {
            return back()->withErrors(['name' => 'Destination nahi mili.'])->withInput();
        }

        $taskId = BackupProvisioner::enqueueDestination('test', ['name' => $row->name]);
        Audit::log('backup.destination.test', 'info', 'server', null, ['task_id' => $taskId, 'name' => $row->name]);

        return redirect()->route('backup-destinations.index')
            ->with('success', "Test queue ho gaya (task #{$taskId}) — host key pin mila to hi connect hoga.");
    }

    /** Push one completed archive to a destination right now. */
    public function push(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:32'],
            'archive' => ['required', 'string', 'max:64'],   // "username:archive_id"
        ]);
        $row = $this->destination($data);
        if ($row === null) {
            return back()->withErrors(['name' => 'Destination nahi mili.'])->withInput();
        }

        // Form bhejta hai "<username>:<archive_id>" — dono ko agent ke task result se
        // milan kar ke hi maana jata hai, to koi bhi path yahan se nahi ban sakta.
        $parts = explode(':', (string) $data['archive'], 2);
        $archive = count($parts) === 2 ? $this->archiveFor($parts[0], $parts[1]) : null;
        if ($archive === null) {
            return back()->withErrors(['archive' => 'Ye archive is account ka nahi ya abhi upload ke layak nahi.'])->withInput();
        }

        $taskId = BackupProvisioner::enqueueDestination('push', [
            'name'         => $row->name,
            'archive_path' => $archive['path'],
        ]);

        BackupDestinationPush::query()->updateOrCreate(
            ['destination_id' => $row->id, 'archive_id' => $archive['archive_id']],
            [
                'username' => $archive['username'],
                'file'     => $archive['file'],
                'status'   => 'queued',
                'task_id'  => $taskId,
                'message'  => null,
            ],
        );

        Audit::log('backup.destination.push', 'info', 'server', null, [
            'task_id' => $taskId, 'name' => $row->name, 'archive' => $archive['file'],
        ]);

        return redirect()->route('backup-destinations.index')
            ->with('success', "Push queue ho gaya (task #{$taskId}) — remote sha256 match ke baad hi file asli banegi.");
    }

    /** What is already sitting on the destination (for a later restore). */
    public function browse(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:32']]);
        $row = $this->destination($data);
        if ($row === null) {
            return back()->withErrors(['name' => 'Destination nahi mili.'])->withInput();
        }

        $taskId = BackupProvisioner::enqueueDestination('browse', ['name' => $row->name]);
        Audit::log('backup.destination.browse', 'info', 'server', null, ['task_id' => $taskId, 'name' => $row->name]);

        return redirect()->route('backup-destinations.index')
            ->with('success', "Listing queue ho gayi (task #{$taskId}) — Result me file names dikhenge.");
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        $this->requireWhm($request);
        $row = $this->destination(['name' => $name]);
        if ($row === null) {
            return back()->withErrors(['name' => 'Destination nahi mili.']);
        }

        $taskId = BackupProvisioner::enqueueDestination('remove', ['name' => $row->name]);
        BackupDestinationPush::query()->where('destination_id', $row->id)->delete();
        $row->delete();

        Audit::log('backup.destination.remove', 'warning', 'server', null, ['task_id' => $taskId, 'name' => $row->name]);

        return redirect()->route('backup-destinations.index')
            ->with('success', "Destination '{$row->name}' hata di (task #{$taskId} — agent uski key bhi shred karega).");
    }

    /** @param array<string, mixed> $data */
    private function destination(array $data): ?BackupDestination
    {
        $name = Backup::tryDestinationName((string) ($data['name'] ?? ''));
        if ($name === null) {
            return null;
        }

        return BackupDestination::query()->where('name', $name)->first();
    }

    /**
     * A completed home archive that really exists on disk (agent re-checks too).
     *
     * @return array{username:string,archive_id:string,file:string,path:string}|null
     */
    private function archiveFor(string $username, string $archiveId): ?array
    {
        return BackupDestinations::archiveFor($username, $archiveId);
    }

    /** @return list<array{username:string,archive_id:string,file:string,size:?int}> */
    private function pushableArchives(): array
    {
        return BackupDestinations::pushableArchives();
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Backup Destinations is a WHM tool.');
        }
    }
}
