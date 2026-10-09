<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Server information, service status/restart and the task-queue monitor (WHM-style). */
class SystemController extends Controller
{
    public function index(): View
    {
        $fresh = request()->boolean('refresh');

        return view('system.index', [
            'system'   => Paneld::run('system.info', [], 12),
            'server'   => Panel::server(),
            'versions' => Panel::versions(),
            'refreshed' => $fresh,
        ]);
    }

    public function services(): View
    {
        $registry = Paneld::registry();

        return view('system.services', [
            'services'    => Paneld::run('service.status', [], 12)['services'] ?? [],
            'restartable' => (array) ($registry['service.restart']['services'] ?? []),
            'canRestart'  => isset($registry['service.restart']),
        ]);
    }

    /** D11 — WHM "Restart Services": agent allowlist se validate + audit. */
    public function restartService(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:60'],
        ]);

        $registry  = Paneld::registry();
        $allowlist = (array) ($registry['service.restart']['services'] ?? []);

        if (! isset($registry['service.restart'])) {
            return redirect()->route('system.services')
                ->withErrors(['service' => 'Agent par service.restart task nahi hai — paneld update/restart chahiye.']);
        }
        if (! in_array($data['service'], $allowlist, true)) {
            return redirect()->route('system.services')
                ->withErrors(['service' => 'Ye service restart allowlist me nahi hai.']);
        }

        $res = Paneld::run('service.restart', ['service' => $data['service']], 75);
        Audit::log('service.restart', 'warning', 'system', null, ['service' => $data['service'], 'ok' => (bool) ($res['ok'] ?? false)]);

        if (is_array($res) && (bool) ($res['ok'] ?? false)) {
            return redirect()->route('system.services')
                ->with('success', $data['service'] . ' restart ho gaya — state: ' . (string) ($res['active'] ?? 'active'));
        }

        $why = is_array($res) ? (string) ($res['error'] ?? 'agent ne fail bataya') : 'agent se jawab nahi mila (timeout)';

        return redirect()->route('system.services')
            ->withErrors(['service' => $data['service'] . ' restart FAIL — ' . $why]);
    }

    public function tasks(): View
    {
        $taskId = request()->integer('task');
        $task   = null;
        $logs   = collect();

        if ($taskId) {
            $task = \Illuminate\Support\Facades\DB::table('tasks')->where('id', $taskId)->first();
            $logs = $task ? Paneld::taskLogs($taskId) : collect();
        }

        return view('system.tasks', [
            'tasks' => Paneld::recentTasks(25),
            'queue' => Panel::queueStats(),
            'task'  => $task,
            'logs'  => $logs,
        ]);
    }

    /** Queue a read-only task from the UI (proves the panel→agent contract). */
    public function runTask(): \Illuminate\Http\RedirectResponse
    {
        $data = request()->validate([
            'type' => ['required', 'string', 'max:80'],
        ]);

        $registry = Paneld::registry();
        abort_unless(isset($registry[$data['type']]), 422, 'Unknown task type');
        abort_if(($registry[$data['type']]['safety'] ?? '') === 'destructive', 403, 'Destructive tasks need a typed confirmation (Step 3+)');

        $id = Paneld::enqueue($data['type']);

        return redirect()->route('system.tasks', ['task' => $id])
            ->with('success', "Task #{$id} is queued — the agent will run it and the result shows here.");
    }
}
