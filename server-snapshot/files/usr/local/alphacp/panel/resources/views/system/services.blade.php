@extends('layouts.panel')

@section('title', 'Service Status')
@section('subtitle', 'WHM-style service monitor — data root agent se, read-only')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index') }}">System info</a>
    <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
@endsection

@section('content')
<div class="card">
    <h3>🧩 Hosting stack</h3>
    @if ($services)
        <div class="table-wrap">
            <table>
                <tr><th>Service</th><th>Running</th><th>Enabled at boot</th><th>Notes</th></tr>
                @foreach ($services as $name => $state)
                    <tr>
                        <td class="mono">{{ $name }}</td>
                        <td><span class="badge {{ $state['active'] === 'active' ? 'green' : ($state['active'] === 'inactive' ? 'amber' : 'red') }}">{{ $state['active'] }}</span></td>
                        <td class="muted">{{ $state['enabled'] }}</td>
                        <td class="muted">
                            @if (in_array($name, ['nginx', 'exim4', 'dovecot', 'pure-ftpd'], true))
                                apne step ke saath activate hoga
                            @elseif ($state['active'] === 'active')
                                theek chal raha hai
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @else
        <p class="empty">Agent se service status nahi aaya.</p>
    @endif
    <p class="help mt">Ye page sirf dekhta hai — start/stop Step 13 (Service Manager) me aayega, aur wo bhi
        task queue ke through (panel kabhi root kaam khud nahi karta).</p>
</div>
@endsection
