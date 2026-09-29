@extends('layouts.panel')

@section('title', 'Indexes')
@section('subtitle', 'Directory listing — default off (secure)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne folders ka listing yahin se set karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
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
