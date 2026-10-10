@extends('layouts.panel')

@section('title', 'Edit Zone Templates')
@section('subtitle', 'Server Manager templates — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Zone templates</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/templates.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Name</th>
                <th>Body</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="mono">{{ \Illuminate\Support\Str::limit($row->body, 80) }}</td>
                    <td class="right">
                        @can('accounts.view')
                            <form method="post" action="{{ route('zone-templates.destroy', $row) }}" onsubmit="return confirm('Remove this template?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No zone templates yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Add template</h3>
    <form method="post" action="{{ route('zone-templates.store') }}" class="stack">
        @csrf
        <label>
            Name
            <input name="name" value="{{ old('name', 'standard') }}" maxlength="32" required placeholder="standard">
        </label>
        <label>
            Body
            <textarea name="body" rows="6" maxlength="2000" required placeholder="%domain%. IN A %ip%">{{ old('body') }}</textarea>
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save template</button>
    </form>
</div>
@endcan
@endsection
