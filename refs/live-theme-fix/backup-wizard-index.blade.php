@extends('layouts.panel')

@section('title', 'Backup Wizard')
@section('subtitle', 'Guided backup or restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('backup.index') }}">Backup</a>
    <a class="btn small secondary" href="{{ route('file-restoration.index') }}">File Restoration</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Current plan — {{ $account->username }}</h3>
        @if ($row)
            <p class="mt"><span class="badge green">{{ $row->action }}</span> <span class="badge blue">{{ $row->scope }}</span></p>
        @else
            <p class="empty mt">No wizard plan yet — neeche se set karo.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">Plan JSON <span class="mono">~/etc/backup/wizard.json</span> me save hota hai.
            No tar/shell — action/scope allowlist, pipe/path fail-closed.</p>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Set Wizard Plan</h3>
    <form method="post" action="{{ route('backup-wizard.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="bw-action">Action</label>
                <select id="bw-action" name="action" required style="min-width:160px">
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(old('action', $row?->action ?? 'backup') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="bw-scope">Scope</label>
                <select id="bw-scope" name="scope" required style="min-width:160px">
                    @foreach ($scopes as $scope)
                        <option value="{{ $scope }}" @selected(old('scope', $row?->scope ?? 'home') === $scope)>{{ $scope }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit">Save Wizard</button>
        </div>
        @error('action')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endif
@endsection
