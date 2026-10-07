@extends('layouts.panel')

@section('title', 'IP Blocker')
@section('subtitle', 'Account ke liye IPs deny karo (ufw/iptables) — Account Panel IP Blocker jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Block an IP</h3>
    <form method="POST" action="{{ route('ip-blocker.store') }}">
        @csrf
        <label>IP address
            <input type="text" name="ip" placeholder="203.0.113.7" required>
        </label>
        <label>Note (optional)
            <input type="text" name="note" placeholder="brute-force" maxlength="190">
        </label>
        <button class="btn" type="submit">Block</button>
    </form>
</div>

<div class="card">
    <h3>Blocked IPs ({{ $rows->count() }})</h3>
    @if ($rows->isEmpty())
        <p class="muted">Koi IP blocked nahi hai.</p>
    @else
        <table>
            <tr><th>IP</th><th>Note</th><th></th></tr>
            @foreach ($rows as $row)
            <tr>
                <td><code>{{ $row->ip }}</code></td>
                <td>{{ $row->note }}</td>
                <td>
                    <form method="POST" action="{{ route('ip-blocker.destroy', $row) }}" style="display:inline" onsubmit="return confirm('Unblock?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Unblock</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
