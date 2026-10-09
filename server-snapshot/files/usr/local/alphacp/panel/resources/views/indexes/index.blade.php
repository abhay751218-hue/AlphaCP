@extends('layouts.panel')

@section('title', 'Indexes')
@section('subtitle', 'Directory listing — default off (secure)')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('privacy.index') }}">Directory Privacy</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Current mode — {{ $account->username }}</h3>
        <p class="mt"><span class="badge {{ $current === 'off' ? 'green' : 'amber' }} mono">{{ $current }}</span></p>
        <p class="help">Account-level Apache <span class="mono">Options</span> setting.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kya hota hai</h3>
        <p class="help" style="margin:6px 0 0">Jis folder me <span class="mono">index.html/php</span> nahi hai, wahan
            browser ko file-listing dikhani hai ya nahi — <strong>off</strong> sabse secure hai.</p>
    </div>
</div>

@can('indexes.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Set Indexing Mode</h3>
    <form method="post" action="{{ route('indexes.update') }}">
        @csrf
        @foreach ($modes as $key => $label)
            <label style="display:block; margin-top:8px">
                <input type="radio" name="mode" value="{{ $key }}" @checked(old('mode', $current) === $key)>
                <span class="mono">{{ $key }}</span> — {{ $label }}
            </label>
        @endforeach
        <button class="btn mt" type="submit">Apply Indexes</button>
    </form>
    <p class="help">Per-folder picker File Manager (S6) ke saath aayega.</p>
</div>
@endcan
@endif
@endsection
