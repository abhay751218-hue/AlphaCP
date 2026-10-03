<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\View\View;

/** Server information, service status and the task-queue monitor (WHM-style). */
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
        return view('system.services', [
            'services' => Paneld::run('service.status', [], 12)['services'] ?? [],
        ]);
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
            ->with('success', "Task #{$id} queue me daala gaya — agent uthayega aur result yahin dikhega.");
    }
}
