@extends('layouts.panel')

@section('title', 'MultiPHP INI Editor')
@section('subtitle', 'Allowlisted php.ini — FPM pool php_admin_value (open_basedir lock rahega)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('software.view')
        <a class="btn small secondary" href="{{ route('php.index') }}">MultiPHP Manager</a>
    @endcan
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set PHP INI here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>INI — {{ $account->username }}</h3>
    <p class="help">PHP {{ $account->php_version }} · empty field = PHP default. Hostile keys (auto_prepend_file, disable_functions) are not allowed.</p>
    @can('software.manage')
        <form method="post" action="{{ route('php.ini.update') }}" class="mt">
            @csrf
            @foreach ($fields as $key => $meta)
                <label for="ini-{{ $key }}">{{ $meta['label'] }}</label>
                @if ($meta['kind'] === 'flag')
                    <select id="ini-{{ $key }}" name="{{ $key }}">
                        <option value="">(default)</option>
                        <option value="On" @selected(old($key, $current[$key] ?? '') === 'On')>On</option>
                        <option value="Off" @selected(old($key, $current[$key] ?? '') === 'Off')>Off</option>
                    </select>
                @elseif ($meta['kind'] === 'reporting')
                    <select id="ini-{{ $key }}" name="{{ $key }}">
                        <option value="">(default)</option>
                        @foreach (['E_ALL', 'E_ALL & ~E_NOTICE', 'E_ALL & ~E_DEPRECATED', 'E_ERROR'] as $opt)
                            <option value="{{ $opt }}" @selected(old($key, $current[$key] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                @elseif ($meta['kind'] === 'charset')
                    <select id="ini-{{ $key }}" name="{{ $key }}">
                        <option value="">(default)</option>
                        <option value="UTF-8" @selected(old($key, $current[$key] ?? '') === 'UTF-8')>UTF-8</option>
                        <option value="ISO-8859-1" @selected(old($key, $current[$key] ?? '') === 'ISO-8859-1')>ISO-8859-1</option>
                    </select>
                @else
                    <input id="ini-{{ $key }}" name="{{ $key }}" type="text" maxlength="60" value="{{ old($key, $current[$key] ?? '') }}" placeholder="default">
                @endif
            @endforeach
            <button class="btn mt" type="submit">Apply INI</button>
        </form>
    @endcan
</div>
@endif
@endsection
