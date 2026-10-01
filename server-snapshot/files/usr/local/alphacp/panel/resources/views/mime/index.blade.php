@extends('layouts.panel')

@section('title', 'MIME Types')
@section('subtitle', 'Custom Content-Type — Apache AddType (no PHP/CGI/SSI)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set MIME types here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>MIME Types — {{ $account->username }}</h3>
    <p class="help">Account-level <span class="mono">AddType</span>. File <span class="mono">~/etc/mime.conf</span>. Handlers alag tool (Apache Handlers) me aayenge.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Extension</th>
                <th>MIME type</th>
                <th></th>
            </tr>
            @forelse ($mappings as $row)
                <tr>
                    <td class="mono">.{{ $row['ext'] }}</td>
                    <td class="mono">{{ $row['mime'] }}</td>
                    <td class="right">
                        @can('mime.manage')
                            <form method="post" action="{{ route('mime.destroy', $row['ext']) }}" onsubmit="return confirm('Remove this MIME mapping?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No custom MIME types yet — browser/Apache default.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('mime.manage')
<div class="card mt">
    <h3>New MIME type</h3>
    <p class="help">Example: <span class="mono">application/json</span> + <span class="mono">json</span>. Space/comma se kai extensions.</p>
    <form method="post" action="{{ route('mime.store') }}">
        @csrf
        <label for="mime">MIME Type</label>
        <input id="mime" name="mime" required maxlength="80" placeholder="application/json" value="{{ old('mime') }}">
        <label for="ext">Extension(s)</label>
        <input id="ext" name="ext" required maxlength="80" placeholder="json" value="{{ old('ext') }}">
        <button class="btn mt" type="submit">Add MIME type</button>
    </form>
</div>
@endcan
@endif
@endsection
