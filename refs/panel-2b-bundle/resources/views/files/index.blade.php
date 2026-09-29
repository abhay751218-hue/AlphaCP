@extends('layouts.panel')

@section('title', 'File Manager')
@section('subtitle', 'Account home only — no .. , zip/chmod baad me')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apni files yahin se manage karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>File Manager — {{ $account->username }}</h3>
    <p class="help">Path: <span class="mono">~/{{ $path === '' ? '' : $path }}</span></p>
    <p>
        @if ($path !== '')
            <a href="{{ route('files.index', ['path' => $parent]) }}">↑ parent</a>
        @endif
    </p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th></th>
            </tr>
            @forelse ($entries as $row)
                <tr>
                    <td class="mono">
                        @if (($row['type'] ?? '') === 'dir')
                            <a href="{{ route('files.index', ['path' => trim($path.'/'.$row['name'], '/')]) }}">{{ $row['name'] }}/</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td>{{ $row['type'] ?? '' }}</td>
                    <td class="mono">{{ $row['size'] ?? 0 }}</td>
                    <td class="right">
                        @can('files.manage')
                            <form method="post" action="{{ route('files.destroy') }}" onsubmit="return confirm('Delete?')">
                                @csrf
                                <input type="hidden" name="path" value="{{ trim($path.'/'.($row['name'] ?? ''), '/') }}">
                                <button class="btn small danger" type="submit">delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Listing paneld se aati hai. Naya folder/file neeche se add karo.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>Naya folder</h3>
    <form method="post" action="{{ route('files.mkdir') }}">
        @csrf
        <input type="hidden" name="dir" value="{{ $path }}">
        <label for="mkdir-name">Name</label>
        <input id="mkdir-name" name="name" required maxlength="80" placeholder="docs">
        <button class="btn mt" type="submit">Folder banao</button>
    </form>
</div>
<div class="card mt">
    <h3>Nayi file (256 KiB max)</h3>
    <form method="post" action="{{ route('files.write') }}">
        @csrf
        <input type="hidden" name="dir" value="{{ $path }}">
        <label for="file-name">Name</label>
        <input id="file-name" name="name" required maxlength="80" placeholder="hello.txt">
        <label for="file-content">Content</label>
        <textarea id="file-content" name="content" rows="8" maxlength="262144"></textarea>
        <button class="btn mt" type="submit">File save karo</button>
    </form>
</div>
<div class="card mt">
    <h3>Rename</h3>
    <form method="post" action="{{ route('files.rename') }}">
        @csrf
        <label for="ren-from">From (relative)</label>
        <input id="ren-from" name="path" required maxlength="240" placeholder="{{ $path === '' ? 'public_html/old.txt' : $path.'/old.txt' }}">
        <label for="ren-to">To (relative)</label>
        <input id="ren-to" name="to" required maxlength="240" placeholder="{{ $path === '' ? 'public_html/new.txt' : $path.'/new.txt' }}">
        <button class="btn mt" type="submit">Rename</button>
    </form>
</div>
@endcan
@endif
@endsection
