@extends('layouts.panel')

@section('title', 'MultiPHP Manager')
@section('subtitle', 'PHP version for this account — same Apache vhost socket, new FPM pool')

@section('actions')
    @can('software.view')
        <a class="btn small secondary" href="{{ route('php.ini') }}">MultiPHP INI Editor</a>
    @endcan
    <a class="btn small secondary" href="{{ route('optimize.index') }}">Optimize Website</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'chip', 'cls' => 'hico']) Current PHP</h3>
        <div class="stat"><span class="num mono" style="font-size:28px">{{ $account->php_version }}</span>
            <span class="unit">{{ $account->username }} · {{ $account->main_domain }}</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Versions available</h3>
        <p class="mt">
            @foreach ($versions as $php)
                <span class="badge {{ $php === $account->php_version ? 'green' : 'blue' }} mono">PHP {{ $php }}</span>
            @endforeach
        </p>
        <p class="help">Change par naya FPM pool banta hai — downtime nahi.</p>
    </div>
</div>

@can('software.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'chip', 'cls' => 'hico']) Change PHP Version</h3>
    <form method="post" action="{{ route('php.update') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="php_version">PHP version</label>
                <select id="php_version" name="php_version" required style="min-width:180px">
                    @foreach ($versions as $php)
                        <option value="{{ $php }}" @selected(old('php_version', $account->php_version) === $php)>PHP {{ $php }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit">Apply</button>
        </div>
    </form>
    @can('software.view')
        <p class="help mt">php.ini values (memory_limit, upload size…) <a href="{{ route('php.ini') }}">MultiPHP INI Editor</a> me badlo.</p>
    @endcan
</div>
@endcan
@endif
@endsection
