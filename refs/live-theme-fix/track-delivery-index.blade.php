@extends('layouts.panel')

@section('title', 'Track Delivery')
@section('subtitle', 'Mail logs me kisi address ki delivery trace karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('deliverability.index') }}">Email Deliverability</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Kya karta hai</h3>
        <p class="help" style="margin:6px 0 0">Email address do — server mail logs me uski recent delivery attempts
            (accepted / deferred / bounced) dhundh kar dikhata hai.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Mail nahi pahunch rahi?</h3>
        <p class="help" style="margin:6px 0 0">Pehle <a href="{{ route('deliverability.index') }}">Email Deliverability</a> me
            SPF / DKIM / DMARC check karo — galat DNS records sabse common wajah hai.</p>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Search Mail Logs — {{ $account->username }}</h3>
    <form method="post" action="{{ route('track-delivery.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:240px">
                <label for="query">Email address (sender ya recipient)</label>
                <input id="query" name="query" type="email" required maxlength="190" placeholder="bob@example.com" value="{{ old('query') }}">
            </div>
            <button class="btn" type="submit">@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Run Trace</button>
        </div>
        <p class="help">Result isi page par flash message me aata hai — recent log entries is address ke liye.</p>
    </form>
</div>
@endcan
@endif
@endsection
