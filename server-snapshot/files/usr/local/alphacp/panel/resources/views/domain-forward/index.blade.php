@extends('layouts.panel')

@section('title', 'Setup/Edit Domain Forwarding')
@section('subtitle', 'Server Manager domain forward — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Domain forwarding</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/forward.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>URL</th>
                <th>Code</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->url }}</td>
                    <td class="mono">{{ $row->code }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No domain forwards yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set forward</h3>
    <form method="post" action="{{ route('domain-forward.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="old.example.com">
        </label>
        <label>
            URL
            <input name="url" value="{{ old('url') }}" maxlength="255" required placeholder="https://example.com">
        </label>
        <label>
            Code
            <select name="code" required>
                @foreach ($codes as $code)
                    <option value="{{ $code }}" @selected((string) old('code', '301') === (string) $code)>{{ $code }}</option>
                @endforeach
            </select>
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save forward</button>
    </form>
</div>
@endcan
@endsection
