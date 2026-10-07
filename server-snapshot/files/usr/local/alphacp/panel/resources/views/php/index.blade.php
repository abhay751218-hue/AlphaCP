@extends('layouts.panel')

@section('title', 'MultiPHP Manager')
@section('subtitle', 'PHP version for this account — same Apache vhost socket, new FPM pool')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Server Manager se account page par PHP badlo.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
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
    @can('software.view')
        <p class="mt"><a href="{{ route('php.ini') }}">MultiPHP INI Editor →</a></p>
    @endcan
</div>
@endif
@endsection
