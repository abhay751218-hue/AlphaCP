@extends('layouts.panel')

@section('title', 'Task Queue Monitor')
@section('subtitle', 'Panel → root agent contract: har privileged kaam ek task banta hai')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index') }}">System info</a>
    <a class="btn small secondary" href="{{ route('system.services') }}">Services</a>
@endsection

@section('content')

<div class="grid cols-4 mb">
    <div class="card"><h3>Queued</h3><div class="stat"><span class="num">{{ $queue['queued'] }}</span></div></div>
    <div class="card"><h3>Running</h3><div class="stat"><span class="num">{{ $queue['running'] }}</span></div></div>
    <div class="card"><h3>Success</h3><div class="stat"><span class="num">{{ $queue['success'] }}</span></div></div>
    <div class="card"><h3>Failed</h3><div class="stat"><span class="num">{{ $queue['failed'] }}</span></div></div>
</div>

@can('system.manage')
<div class="card mb">
    <h3>▶️ Run a read-only task (test)</h3>
    <form method="post" action="{{ route('system.tasks.run') }}" class="row">
        @csrf
        <select name="type" style="max-width:280px">
            @foreach (\App\Support\Paneld::registry() as $type => $cfg)
                @if (($cfg['safety'] ?? '') === 'readonly')
                    <option value="{{ $type }}">{{ $type }} — {{ \Illuminate\Support\Str::limit($cfg['description'] ?? '', 48) }}</option>
                @endif
            @endforeach
        </select>
        <button class="btn" type="submit">Enqueue</button>
        <span class="help">Safety: only <span class="mono">readonly</span> tasks can run from here.</span>
    </form>
</div>
@endcan

<div class="card">
    <h3>📋 Recent tasks</h3>
    <div class="table-wrap">
        <table>
            <tr><th>#</th><th>Type</th><th>Safety</th><th>Status</th><th>Duration</th><th>Requested</th><th></th></tr>
            @forelse ($tasks as $row)
                <tr>
                    <td class="mono">{{ $row->id }}</td>
                    <td class="mono">{{ $row->type }}</td>
                    <td><span class="badge blue">{{ $row->safety }}</span></td>
                    <td>
                        <span class="badge {{ $row->status === 'success' ? 'green' : ($row->status === 'failed' ? 'red' : 'amber') }}">{{ $row->status }}</span>
                    </td>
                    <td class="mono">{{ $row->duration_ms !== null ? $row->duration_ms . ' ms' : '—' }}</td>
                    <td class="muted">{{ \App\Support\Panel::ago($row->created_at) }} · {{ $row->requested_src }}</td>
                    <td class="right"><a class="btn small ghost" href="{{ route('system.tasks', ['task' => $row->id]) }}">logs</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No tasks yet. Enqueue a readonly task above.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($task)
    <div class="card mt">
        <h3>🔎 Task #{{ $task->id }} — {{ $task->type }}</h3>
        <dl class="kv">
            <dt>Status</dt><dd><span class="badge {{ $task->status === 'success' ? 'green' : 'amber' }}">{{ $task->status }}</span></dd>
            <dt>Safety</dt><dd class="mono">{{ $task->safety }}</dd>
            <dt>Payload</dt><dd class="mono">{{ $task->payload }}</dd>
            <dt>Error</dt><dd class="mono">{{ $task->error ?? '—' }}</dd>
            <dt>Result</dt><dd class="mono" style="white-space:pre-wrap">{{ $task->result ?? '—' }}</dd>
        </dl>

        <h3 class="mt">Agent logs</h3>
        @if ($logs->isEmpty())
            <p class="empty">No logs yet (the task may still be queued).</p>
        @else
            <pre class="mono" style="white-space:pre-wrap; background:#0d1628; border:1px solid var(--line); border-radius:10px; padding:12px">@foreach ($logs as $log)[{{ $log->level }}] {{ $log->line }}
@endforeach</pre>
        @endif
        <p class="help mt">Reload in 3 seconds for a refresh — live streaming comes in Step 11.</p>
    </div>
@endif

@endsection
