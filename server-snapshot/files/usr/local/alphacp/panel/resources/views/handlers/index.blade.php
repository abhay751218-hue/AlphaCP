@extends('layouts.panel')

@section('title', 'Apache Handlers')
@section('subtitle', 'Custom AddHandler — PHP/proxy/fcgi allowed nahi')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne Apache handlers yahin se set karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Apache Handlers — {{ $account->username }}</h3>
    <p class="help">Account-level <span class="mono">AddHandler</span>. File <span class="mono">~/etc/handlers.conf</span>. Allowlist only.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Extension</th>
                <th>Handler</th>
                <th></th>
            </tr>
            @forelse ($mappings as $row)
                <tr>
                    <td class="mono">.{{ $row['ext'] }}</td>
                    <td class="mono">{{ $row['handler'] }}</td>
                    <td class="right">
                        @can('handlers.manage')
                            <form method="post" action="{{ route('handlers.destroy', $row['ext']) }}" onsubmit="return confirm('Handler mapping hataayein?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi custom handler nahi — Apache default.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('handlers.manage')
<div class="card mt">
    <h3>Naya handler</h3>
    <p class="help">Example: <span class="mono">cgi-script</span> + <span class="mono">cgi</span>. Space/comma se kai extensions.</p>
    <form method="post" action="{{ route('handlers.store') }}">
        @csrf
        <label for="handler">Handler</label>
        <select id="handler" name="handler" required>
            @foreach ($handlers as $key => $label)
                <option value="{{ $key }}" @selected(old('handler') === $key)>{{ $key }} — {{ $label }}</option>
            @endforeach
        </select>
        <label for="ext">Extension(s)</label>
        <input id="ext" name="ext" required maxlength="80" placeholder="cgi" value="{{ old('ext') }}">
        <button class="btn mt" type="submit">Handler add karo</button>
    </form>
</div>
@endcan
@endif
@endsection
