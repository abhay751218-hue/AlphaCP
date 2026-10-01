@extends('layouts.panel')

@section('title', 'MySQL Databases')
@section('subtitle', 'Prefixed names — no mysql binary, no GRANT')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne databases yahin banayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>MySQL Databases — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mysql/databases.json</span>. Prefix <span class="mono">{{ $account->username }}_</span>. mysql/GRANT later. Pipe/shell fail closed. MAXSQL {{ $maxSql }}.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Database</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $account->username }}_{{ $row->name }}</td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('mysql.destroy', $row) }}" onsubmit="return confirm('Database hataayein?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">Koi database nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>Create Database</h3>
    <form method="post" action="{{ route('mysql.store') }}" class="stack">
        @csrf
        <label>
            Name
            <span class="mono">{{ $account->username }}_</span>
            <input name="name" value="{{ old('name') }}" maxlength="16" required placeholder="shop">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Create Database</button>
    </form>
</div>
@endcan
@endif
@endsection
