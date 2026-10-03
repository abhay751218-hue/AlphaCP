@extends('layouts.panel')

@section('title', 'Backup')
@section('subtitle', 'Queue a home / mail / mysql job — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers queue backup jobs here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Backup — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/backup/jobs.json</span>. No tar/shell. Kind allowlist only — pipe/path fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Kind</th>
                <th>Path</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->kind }}</td>
                    <td class="mono">{{ $row->path === '' ? '—' : $row->path }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No backup jobs yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>Queue backup</h3>
    <form method="post" action="{{ route('backup.store') }}" class="stack">
        @csrf
        <label>
            Kind
            <select name="kind" required>
                @foreach ($kinds as $kind)
                    <option value="{{ $kind }}" @selected(old('kind', 'home') === $kind)>{{ $kind }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Path (home only, optional)
            <input name="path" value="{{ old('path', '') }}" maxlength="240" placeholder="public_html">
        </label>
        @error('kind')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue backup</button>
    </form>
</div>
@endcan
@endif
@endsection
