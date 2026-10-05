@extends('layouts.panel')

@section('title', 'MultiPHP Manager')
@section('subtitle', 'PHP version per account aur per domain — apna FPM pool + vhost socket')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. WHM se account page par PHP badlo.</p>
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

<div class="card mt">
    <h3>Per-domain PHP version</h3>
    <p class="help">cPanel MultiPHP Manager jaisa — kisi ek domain ka apna PHP-FPM pool. Khali chhodo to domain account wala PHP use karta hai.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>PHP</th>
                <th></th>
            </tr>
            @forelse ($domains as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge blue">{{ $row->type }}</span></td>
                    <td>{{ $row->php_version ?: $account->php_version }}</td>
                    <td class="right">
                        @can('software.manage')
                            <form method="post" action="{{ route('php.domain.update', $row) }}">
                                @csrf
                                <select name="php_version" required>
                                    @foreach ($versions as $php)
                                        <option value="{{ $php }}" @selected(($row->php_version ?: $account->php_version) === $php)>PHP {{ $php }}</option>
                                    @endforeach
                                </select>
                                <button class="btn small" type="submit">Apply</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No domains yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endif
@endsection
