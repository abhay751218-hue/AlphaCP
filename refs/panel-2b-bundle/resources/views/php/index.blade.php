@extends('layouts.panel')

@section('title', 'MultiPHP Manager')
@section('subtitle', 'Is account ka PHP version — Apache vhost same socket, naya FPM pool')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. WHM se account page par PHP badlo.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Current PHP</h3>
    <p class="help">{{ $account->username }} · {{ $account->main_domain }} · abhi <span class="mono">{{ $account->php_version }}</span></p>
    @can('software.manage')
        <form method="post" action="{{ route('php.update') }}" class="mt">
            @csrf
            <label for="php_version">PHP version</label>
            <select id="php_version" name="php_version" required>
                @foreach ($versions as $php)
                    <option value="{{ $php }}" @selected(old('php_version', $account->php_version) === $php)>PHP {{ $php }}</option>
                @endforeach
            </select>
            <button class="btn mt" type="submit">Apply</button>
        </form>
    @endcan
</div>
@endif
@endsection
