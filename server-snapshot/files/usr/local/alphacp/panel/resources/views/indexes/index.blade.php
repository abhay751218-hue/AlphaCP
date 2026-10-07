@extends('layouts.panel')

@section('title', 'Indexes')
@section('subtitle', 'Directory listing — default off (secure)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set folder listing here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Indexes — {{ $account->username }}</h3>
    <p class="help">Account-level Apache Options. Per-folder picker File Manager (S6) ke saath aayega.</p>
    @can('indexes.manage')
        <form method="post" action="{{ route('indexes.update') }}" class="mt">
            @csrf
            @foreach ($modes as $key => $label)
                <label>
                    <input type="radio" name="mode" value="{{ $key }}" @checked(old('mode', $current) === $key)>
                    <span class="mono">{{ $key }}</span> — {{ $label }}
                </label>
            @endforeach
            <button class="btn mt" type="submit">Apply indexes</button>
        </form>
    @endcan
</div>
@endif
@endsection
