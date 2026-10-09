@extends('layouts.panel')

@section('title', 'Apache Handlers')
@section('subtitle', 'Custom AddHandler — PHP/proxy/fcgi not allowed')

@section('actions')
    <a class="btn small secondary" href="{{ route('mime.index') }}">MIME Types</a>
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Custom handlers</h3>
        <div class="stat"><span class="num">{{ count($mappings) }}</span><span class="unit">mappings defined</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Allowlist only</h3>
        <p class="help" style="margin:6px 0 0">Apache ko batata hai extension kaise process kare.
            Account-level <span class="mono">AddHandler</span>, file <span class="mono">~/etc/handlers.conf</span> —
            PHP/proxy/fcgi handlers security ke liye blocked.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Handlers — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-handlers tbody tr" aria-label="Search handlers">
    </div>
    <div class="table-wrap">
        <table id="acp-handlers">
            <thead><tr><th>Extension</th><th>Handler</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($mappings as $row)
                <tr>
                    <td><span class="badge blue mono">.{{ $row['ext'] }}</span></td>
                    <td class="mono">{{ $row['handler'] }}</td>
                    <td class="right">
                        @can('handlers.manage')
                            <form method="post" action="{{ route('handlers.destroy', $row['ext']) }}" onsubmit="return confirm('Remove this handler mapping?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No custom handlers yet — Apache default.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('handlers.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Create a Handler</h3>
    <form method="post" action="{{ route('handlers.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:240px">
                <label for="handler">Handler</label>
                <select id="handler" name="handler" required>
                    @foreach ($handlers as $key => $label)
                        <option value="{{ $key }}" @selected(old('handler') === $key)>{{ $key }} — {{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1; min-width:160px">
                <label for="ext">Extension(s)</label>
                <input id="ext" name="ext" required maxlength="80" placeholder="cgi" value="{{ old('ext') }}">
            </div>
            <button class="btn" type="submit">+ Add</button>
        </div>
        <p class="help">Example: <span class="mono">cgi-script</span> + <span class="mono">cgi</span>. Space/comma se kai extensions.</p>
    </form>
</div>
@endcan
@endif
@endsection
