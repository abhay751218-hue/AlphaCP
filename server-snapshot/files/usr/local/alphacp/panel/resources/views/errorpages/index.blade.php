@extends('layouts.panel')

@section('title', 'Error Pages')
@section('subtitle', 'Custom 400/401/403/404/500/503 HTML — PHP/SSI not allowed')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set error pages here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Error Pages — {{ $account->username }}</h3>
    <p class="help">Khali = Apache default. Files <span class="mono">~/errorpages/</span> me, Alias <span class="mono">/acp-errorpages/</span>.</p>
    @can('errorpages.manage')
        <form method="post" action="{{ route('errorpages.update') }}" class="mt">
            @csrf
            @foreach ($codes as $code)
                <label for="ep-{{ $code }}">{{ $code }}</label>
                <textarea id="ep-{{ $code }}" name="pages[{{ $code }}]" rows="4" maxlength="16384" placeholder="(default)">{{ old('pages.'.$code, $current[$code] ?? '') }}</textarea>
            @endforeach
            <button class="btn mt" type="submit">Save error pages</button>
        </form>
    @endcan
</div>
@endif
@endsection
