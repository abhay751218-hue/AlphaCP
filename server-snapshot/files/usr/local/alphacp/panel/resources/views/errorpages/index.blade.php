@extends('layouts.panel')

@section('title', 'Error Pages')
@section('subtitle', 'Custom 400/401/403/404/500/503 HTML — PHP/SSI not allowed')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('indexes.index') }}">Indexes</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Error pages — {{ $account->username }}</h3>
        <div class="stat"><span class="num">{{ count($codes) }}</span><span class="unit">codes customize ho sakte hain</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">Khali chhodo = Apache default page. HTML files
            <span class="mono">~/errorpages/</span> me, Alias <span class="mono">/acp-errorpages/</span>.
            PHP/SSI allowed nahi (security).</p>
    </div>
</div>

@can('errorpages.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Edit Error Pages</h3>
    <form method="post" action="{{ route('errorpages.update') }}">
        @csrf
        <div class="grid cols-2">
            @foreach ($codes as $code)
                <div>
                    <label for="ep-{{ $code }}"><span class="badge {{ $code >= 500 ? 'red' : 'amber' }}">{{ $code }}</span></label>
                    <textarea id="ep-{{ $code }}" name="pages[{{ $code }}]" rows="4" maxlength="16384" placeholder="(default)">{{ old('pages.'.$code, $current[$code] ?? '') }}</textarea>
                </div>
            @endforeach
        </div>
        <button class="btn mt" type="submit">Save Error Pages</button>
    </form>
</div>
@endcan
@endif
@endsection
