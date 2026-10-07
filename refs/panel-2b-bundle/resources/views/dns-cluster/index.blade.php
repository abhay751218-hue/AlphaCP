@extends('layouts.panel')

@section('title', 'DNS Cluster')
@section('subtitle', 'Remote DNS/nameserver nodes + zone synchronization')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Node add karo</h3>
    <form method="POST" action="{{ route('dns-cluster.store') }}">
        @csrf
        <label>Hostname
            <input type="text" name="hostname" placeholder="ns2.example.com" required>
        </label>
        <label>IP
            <input type="text" name="ip" placeholder="203.0.113.10" required>
        </label>
        <label>Role
            <select name="role" required>
                <option value="dns">DNS (standalone)</option>
                <option value="ns">Nameserver</option>
            </select>
        </label>
        <button class="btn" type="submit">Add node</button>
    </form>
</div>

<div class="card" style="display:flex;gap:12px;align-items:center">
    <form method="POST" action="{{ route('dns-cluster.sync') }}">
        @csrf
        <button class="btn secondary" type="submit">Synchronize all zones</button>
    </form>
</div>

<div class="card">
    <h3>Cluster nodes ({{ $nodes->count() }})</h3>
    @if ($nodes->isEmpty())
        <p class="muted">Koi DNS cluster node nahi.</p>
    @else
        <table>
            <tr><th>Hostname</th><th>IP</th><th>Role</th><th>Status</th><th>Last sync</th><th></th></tr>
            @foreach ($nodes as $n)
            <tr>
                <td>{{ $n->hostname }}</td>
                <td>{{ $n->ip }}</td>
                <td>{{ $n->role === 'ns' ? 'Nameserver' : 'DNS' }}</td>
                <td>{{ $n->status === 'synced' ? '✅ Synced' : '➕ Added' }}</td>
                <td>{{ $n->last_synced_at?->format('d M Y H:i') ?? '—' }}</td>
                <td>
                    <form method="POST" action="{{ route('dns-cluster.destroy', $n) }}" onsubmit="return confirm('Remove node?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Remove</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
