@extends('layouts.panel')

@section('title', 'Remote MySQL')
@section('subtitle', 'Access hosts — no mysql GRANT, no bind-address rewrite')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers allow remote hosts here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Remote MySQL — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mysql/remote.json</span>. Host = <span class="mono">%</span>, IPv4, or FQDN. GRANT later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Host</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->host }}</td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('remote-mysql.destroy', $row) }}" onsubmit="return confirm('Remove this host?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No remote hosts yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>Add host</h3>
    <form method="post" action="{{ route('remote-mysql.store') }}" class="stack">
        @csrf
        <label>
            Host
            <input name="host" value="{{ old('host') }}" maxlength="190" required placeholder="203.0.113.10">
        </label>
        @error('host')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Add host</button>
    </form>
</div>
@endcan
@endif
@endsection
