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

<div class="card mt">
    <h3>Per-domain INI</h3>
    <p class="help">Domain apna alag PHP-FPM pool chalata hai, isliye INI bhi alag lagti hai. Khali field = PHP default. Saare fields khali chhodo to domain phir account pool par wapas chala jayega.</p>
    @can('software.manage')
        <div class="table-wrap mt">
            <table>
                <tr>
                    <th>Domain</th>
                    <th>Type</th>
                    <th>Overrides</th>
                </tr>
                @forelse ($domains as $row)
                    <tr>
                        <td class="mono">{{ $row->domain }}</td>
                        <td><span class="badge blue">{{ $row->type }}</span></td>
                        <td>
                            <form method="post" action="{{ route('php.ini.domain.update', $row) }}">
                                @csrf
                                <div class="grid cols-2">
                                    @foreach ($fields as $key => $meta)
                                        <div>
                                            <label for="ini-{{ $row->id }}-{{ $key }}">{{ $meta['label'] }}</label>
                                            @if ($meta['kind'] === 'flag')
                                                <select id="ini-{{ $row->id }}-{{ $key }}" name="{{ $key }}">
                                                    <option value="">(default)</option>
                                                    <option value="On" @selected(($row->php_ini[$key] ?? '') === 'On')>On</option>
                                                    <option value="Off" @selected(($row->php_ini[$key] ?? '') === 'Off')>Off</option>
                                                </select>
                                            @elseif ($meta['kind'] === 'reporting')
                                                <select id="ini-{{ $row->id }}-{{ $key }}" name="{{ $key }}">
                                                    <option value="">(default)</option>
                                                    @foreach (['E_ALL', 'E_ALL & ~E_NOTICE', 'E_ALL & ~E_DEPRECATED', 'E_ERROR'] as $opt)
                                                        <option value="{{ $opt }}" @selected(($row->php_ini[$key] ?? '') === $opt)>{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif ($meta['kind'] === 'charset')
                                                <select id="ini-{{ $row->id }}-{{ $key }}" name="{{ $key }}">
                                                    <option value="">(default)</option>
                                                    <option value="UTF-8" @selected(($row->php_ini[$key] ?? '') === 'UTF-8')>UTF-8</option>
                                                    <option value="ISO-8859-1" @selected(($row->php_ini[$key] ?? '') === 'ISO-8859-1')>ISO-8859-1</option>
                                                </select>
                                            @else
                                                <input id="ini-{{ $row->id }}-{{ $key }}" name="{{ $key }}" type="text" maxlength="60" value="{{ $row->php_ini[$key] ?? '' }}" placeholder="default">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <button class="btn small mt" type="submit">Apply INI for {{ $row->domain }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">No PHP-serving domains yet.</td></tr>
                @endforelse
            </table>
        </div>
    @endcan
</div>
@endif
@endsection
