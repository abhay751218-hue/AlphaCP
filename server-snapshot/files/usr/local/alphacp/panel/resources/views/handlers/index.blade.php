@extends('layouts.panel')

@section('title', 'Apache Handlers')
@section('subtitle', 'Custom AddHandler — PHP/proxy/fcgi not allowed')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set Apache handlers here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
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
                            <form method="post" action="{{ route('handlers.destroy', $row['ext']) }}" onsubmit="return confirm('Remove this handler mapping?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No custom handlers yet — Apache default.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('handlers.manage')
<div class="card mt">
    <h3>New handler</h3>
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
        <button class="btn mt" type="submit">Add handler</button>
    </form>
</div>
@endcan
@endif
@endsection
