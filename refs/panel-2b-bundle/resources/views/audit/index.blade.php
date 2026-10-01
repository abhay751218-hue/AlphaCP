@extends('layouts.panel')

@section('title', 'Audit Log')
@section('subtitle', 'Har state change yahan aata hai — immutable (append-only)')

@section('actions')
    <form method="get" class="row">
        <input type="text" name="action" value="{{ $action }}" placeholder="action filter (e.g. auth.)" style="width:200px">
        <select name="severity" style="width:150px">
            <option value="">all severities</option>
            @foreach (['info', 'warning', 'critical'] as $s)
                <option value="{{ $s }}" @selected($severity === $s)>{{ $s }}</option>
            @endforeach
        </select>
        <button class="btn small secondary" type="submit">Filter</button>
    </form>
@endsection

@section('content')
<div class="card">
    <div class="table-wrap">
        <table>
            <tr><th>#</th><th>Action</th><th>Severity</th><th>Actor</th><th>Target</th><th>IP</th><th>When</th></tr>
            @forelse ($events as $event)
                <tr>
                    <td class="mono">{{ $event->id }}</td>
                    <td class="mono">{{ $event->action }}</td>
                    <td><span class="badge {{ $event->severity === 'critical' ? 'red' : ($event->severity === 'warning' ? 'amber' : 'blue') }}">{{ $event->severity }}</span></td>
                    <td class="muted">{{ $event->actor_type }}{{ $event->actor_id ? '#' . $event->actor_id : '' }}</td>
                    <td class="muted">{{ $event->target_type }}{{ $event->target_id ? '#' . $event->target_id : '' }}</td>
                    <td class="mono muted">{{ $event->actor_ip }}</td>
                    <td class="muted">{{ \App\Support\Panel::ago($event->created_at) }}</td>
                </tr>
                @if ($event->meta)
                    <tr><td></td><td colspan="6" class="muted mono" style="font-size:12px">{{ $event->meta }}</td></tr>
                @endif
            @empty
                <tr><td colspan="7" class="empty">No audit events yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
