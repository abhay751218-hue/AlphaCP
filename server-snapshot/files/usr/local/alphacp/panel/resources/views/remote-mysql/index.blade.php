@extends('layouts.panel')

@section('title', 'Remote MySQL')
@section('subtitle', 'Access hosts — bahar ke servers ko DB access do')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">MySQL Databases</a>
    <a class="btn small secondary" href="{{ route('mysql-users.index') }}">MySQL Users</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Access hosts</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">remote hosts allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Host formats</h3>
        <p class="help" style="margin:6px 0 0"><span class="mono">%</span> = sab jagah se ·
            IPv4 (<span class="mono">203.0.113.10</span>) · FQDN (<span class="mono">app.example.com</span>).
            JSON <span class="mono">~/etc/mysql/remote.json</span> — pipe/shell fail-closed.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Allowed Hosts — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search hosts…" data-filter-rows="#acp-rmysql tbody tr" aria-label="Search hosts">
    </div>
    <div class="table-wrap">
        <table id="acp-rmysql">
            <thead><tr><th>Host</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono"><span class="badge green">allow</span> {{ $row->host }}</td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('remote-mysql.destroy', $row) }}" onsubmit="return confirm('Remove this host?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No remote hosts yet — neeche se add karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Add Access Host</h3>
    <form method="post" action="{{ route('remote-mysql.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:220px">
                <label for="rm-host">Host</label>
                <input id="rm-host" name="host" value="{{ old('host') }}" maxlength="190" required placeholder="203.0.113.10">
            </div>
            <button class="btn" type="submit">+ Add Host</button>
        </div>
        @error('host')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endif
@endsection
