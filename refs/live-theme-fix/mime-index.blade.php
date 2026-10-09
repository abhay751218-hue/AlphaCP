@extends('layouts.panel')

@section('title', 'MIME Types')
@section('subtitle', 'Custom Content-Type — Apache AddType (no PHP/CGI/SSI)')

@section('actions')
    <a class="btn small secondary" href="{{ route('handlers.index') }}">Apache Handlers</a>
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
        <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Custom MIME types</h3>
        <div class="stat"><span class="num">{{ count($mappings) }}</span><span class="unit">mappings defined</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Kya hota hai</h3>
        <p class="help" style="margin:6px 0 0">Browser ko batata hai file kaise handle kare — e.g.
            <span class="mono">.json → application/json</span>. Account-level <span class="mono">AddType</span>,
            file <span class="mono">~/etc/mime.conf</span>.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) MIME Types — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-mime tbody tr" aria-label="Search MIME types">
    </div>
    <div class="table-wrap">
        <table id="acp-mime">
            <thead><tr><th>Extension</th><th>MIME type</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($mappings as $row)
                <tr>
                    <td><span class="badge blue mono">.{{ $row['ext'] }}</span></td>
                    <td class="mono">{{ $row['mime'] }}</td>
                    <td class="right">
                        @can('mime.manage')
                            <form method="post" action="{{ route('mime.destroy', $row['ext']) }}" onsubmit="return confirm('Remove this MIME mapping?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No custom MIME types yet — browser/Apache default.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('mime.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Create a MIME Type</h3>
    <form method="post" action="{{ route('mime.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:200px">
                <label for="mime">MIME Type</label>
                <input id="mime" name="mime" required maxlength="80" placeholder="application/json" value="{{ old('mime') }}">
            </div>
            <div style="flex:1; min-width:160px">
                <label for="ext">Extension(s)</label>
                <input id="ext" name="ext" required maxlength="80" placeholder="json" value="{{ old('ext') }}">
            </div>
            <button class="btn" type="submit">+ Add</button>
        </div>
        <p class="help">Space/comma se kai extensions ek saath.</p>
    </form>
</div>
@endcan
@endif
@endsection
