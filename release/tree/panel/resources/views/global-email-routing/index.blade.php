@extends('layouts.panel')

@section('title', 'Email Routing Configuration')
@section('subtitle', 'Server Manager global MX mode — no Exim rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Global email routing</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/mail/global-routing.json</span>. Exim later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Mode</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->mode }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No global routes yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set routing</h3>
    <form method="post" action="{{ route('global-email-routing.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="example.com">
        </label>
        <label>
            Mode
            <select name="mode" required>
                @foreach ($modes as $mode)
                    <option value="{{ $mode }}" @selected(old('mode', 'auto') === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save routing</button>
    </form>
</div>
@endcan
@endsection
