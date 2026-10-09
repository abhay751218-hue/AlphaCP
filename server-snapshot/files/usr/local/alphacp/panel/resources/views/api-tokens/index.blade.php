@extends('layouts.panel')

@section('title', 'API Tokens')
@section('subtitle', 'Manage API Tokens — billing software ke liye Bearer token')

@section('actions')
    <a class="btn small secondary" href="{{ route('audit.index') }}">Audit Log</a>
@endsection

@section('content')
@if ($newToken)
<div class="card" style="border:2px solid #1d8a3a">
    <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Naya token <span class="badge green">EK baar — abhi copy karo</span></h3>
    <p><code style="word-break:break-all">{{ $newToken }}</code></p>
    <p class="help">Ise apne billing software (Server ManagerCS/Blesta/Clientexec) me <em>Authorization: Bearer</em> ke roop me daalo.
        Page chhodne ke baad dobara nahi dikhega.</p>
</div>
@endif

<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Tokens</h3>
        <div class="stat"><span class="num">{{ $tokens->count() }}</span><span class="unit">active tokens</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Security</h3>
        <p class="help" style="margin:6px 0 0">Token = full API access is login ke naam par. Use na ho raha ho to
            <strong>Revoke</strong> karo. "Last used" column se audit karo.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) My Tokens</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search tokens…" data-filter-rows="#acp-tokens tbody tr" aria-label="Search tokens">
    </div>
    <div class="table-wrap">
        <table id="acp-tokens">
            <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($tokens as $t)
                <tr>
                    <td>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) {{ $t->name }}</td>
                    <td class="muted">{{ $t->created_at?->format('d M Y') }}</td>
                    <td>@if ($t->last_used_at)<span class="badge green">{{ $t->last_used_at->format('d M Y H:i') }}</span>@else<span class="badge gray">kabhi nahi</span>@endif</td>
                    <td class="right">
                        <form method="POST" action="{{ route('api-tokens.destroy', $t) }}" onsubmit="return confirm('Revoke?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Revoke</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Koi token nahi — neeche se generate karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Generate Token</h3>
    <form method="POST" action="{{ route('api-tokens.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:200px">
                <label for="tok-name">Token name</label>
                <input id="tok-name" type="text" name="name" placeholder="billing" maxlength="60" required>
            </div>
            <button class="btn" type="submit">+ Generate</button>
        </div>
    </form>
</div>
@endsection
