@extends('layouts.panel')

@section('title', 'Address Importer')
@section('subtitle', 'CSV se bulk mailboxes banao')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('forwarders.index') }}">Forwarders</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Mailbox limit</h3>
        <div class="stat"><span class="num">{{ $maxPop }}</span><span class="unit">max mailboxes per account</span></div>
        <p class="help">Import limit tak hi mailboxes banata hai — baaki rows skip ho jati hain.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) CSV format</h3>
        <p class="help" style="margin:6px 0 0">Har line: <span class="mono">address,password,quota_mb</span><br>
            Example: <span class="mono">bob@example.com,S3cret!pass,512</span><br>
            Quota optional hai — khali chhodo to default lagta hai.</p>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Import Addresses — {{ $account->username }}</h3>
    <form method="post" action="{{ route('address-importer.store') }}">
        @csrf
        <label for="csv">CSV data (one mailbox per line, max 32000 chars)</label>
        <textarea id="csv" name="csv" required maxlength="32000" rows="10" class="mono" placeholder="bob@example.com,S3cret!pass,512&#10;alice@example.com,An0ther!pass,256">{{ old('csv') }}</textarea>
        <div class="row mt">
            <button class="btn" type="submit">@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Import Mailboxes</button>
            <span class="help" style="margin:0">Result flash message me aata hai — kitni bani, kitni skip hui.</span>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
