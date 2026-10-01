@extends('layouts.panel')

@section('title', 'DNS Zone Manager')
@section('subtitle', 'WHM zone list — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>DNS Zone Manager</h3>
    <p class="help">Lists account domains. JSON lives in each home <span class="mono">~/etc/dns/zone.json</span>. BIND later. Pipe/shell fail closed.</p>
    <form method="get" action="{{ route('dns-zones.index') }}" class="stack mt">
        <label>
            Domain
            <input name="q" value="{{ $q }}" maxlength="190" placeholder="shop.example.com">
        </label>
        @if ($invalid)
            <p class="error">Invalid domain. FQDN only. No pipe/path.</p>
        @endif
        @error('q')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Filter</button>
    </form>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Account</th>
                <th>Records</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row['domain'] }}</td>
                    <td class="mono">{{ $row['account']->username }}</td>
                    <td class="mono">{{ $row['records'] }}</td>
                    <td class="right">
                        @can('dns.manage')
                            <form method="post" action="{{ route('dns-zones.sync', $row['account']) }}">
                                @csrf
                                <button class="btn small" type="submit">sync</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No DNS zones match.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
