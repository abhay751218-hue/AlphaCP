@extends('layouts.panel')

@section('title', 'Mail Queue Manager')
@section('subtitle', 'Exim queue ka asli haal — deliver, freeze, remove, flush (root agent se)')

@section('actions')
    <a class="btn small secondary" href="{{ route('track-delivery.index') }}">Track Delivery</a>
    <a class="btn small secondary" href="{{ route('system.services') }}">Service Status</a>
@endsection

@section('content')
@if (session('success'))
    <div class="card mb"><p class="help" style="margin:0">✅ {{ session('success') }}</p></div>
@endif
@if ($errors->any())
    <div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $errors->first() }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'queue', 'cls' => 'hico']) Queue summary</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $queue['count'] }}</span><span class="unit">pending messages</span></div>
            <div class="stat"><span class="num">{{ $frozen }}</span><span class="unit">frozen</span></div>
            <div class="stat"><span class="num">{{ $agentOk && $queue['ok'] ? 'OK' : '—' }}</span><span class="unit">agent (mail.server)</span></div>
        </div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Deliver All Now</h3>
        <p class="help" style="margin:6px 0 10px">Poori queue flush (exim -qf) — har pending message ki delivery abhi try hogi.</p>
        <form method="post" action="{{ route('mail-queue.action') }}">
            @csrf
            <input type="hidden" name="op" value="flush">
            <button class="btn" type="submit" @disabled(! $agentOk || $queue['count'] === 0)>Deliver All Now</button>
        </form>
    </div>
</div>

@if (! $agentOk)
<div class="card mt"><p class="empty">Root agent offline hai — queue dekhne ke liye paneld chahiye.</p></div>
@elseif ($queue['error'])
<div class="card mt"><p class="empty">⚠️ {{ $queue['error'] }}</p></div>
@endif

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Queue messages</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter (id, sender, recipient)…" data-filter-rows="#acp-mailq tbody tr" aria-label="Filter queue">
    </div>
    @if ($queue['items'] === [])
        <p class="empty">Mail queue khali hai — koi message pending nahi ✅</p>
    @else
    <div class="table-wrap">
        <table id="acp-mailq">
            <thead><tr><th>Message ID</th><th>Age</th><th>Size</th><th>Sender</th><th>Recipients</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @foreach ($queue['items'] as $m)
                <tr>
                    <td class="mono">{{ $m['id'] ?? '' }}</td>
                    <td class="mono">{{ $m['age'] ?? '—' }}</td>
                    <td class="mono">{{ $m['size'] ?? '—' }}</td>
                    <td class="mono" style="word-break:break-all">{{ ($m['sender'] ?? '') !== '' ? $m['sender'] : '<bounce>' }}</td>
                    <td class="mono" style="word-break:break-all">{{ implode(', ', $m['recipients'] ?? []) }}</td>
                    <td>
                        @if ($m['frozen'] ?? false)
                            <span class="badge amber">frozen</span>
                        @else
                            <span class="badge blue">queued</span>
                        @endif
                    </td>
                    <td>
                        <div class="row" style="gap:4px; flex-wrap:wrap">
                            <form method="post" action="{{ route('mail-queue.action') }}">
                                @csrf
                                <input type="hidden" name="op" value="deliver">
                                <input type="hidden" name="id" value="{{ $m['id'] ?? '' }}">
                                <button class="btn small secondary" type="submit">Deliver</button>
                            </form>
                            @if ($m['frozen'] ?? false)
                            <form method="post" action="{{ route('mail-queue.action') }}">
                                @csrf
                                <input type="hidden" name="op" value="thaw">
                                <input type="hidden" name="id" value="{{ $m['id'] ?? '' }}">
                                <button class="btn small secondary" type="submit">Thaw</button>
                            </form>
                            @else
                            <form method="post" action="{{ route('mail-queue.action') }}">
                                @csrf
                                <input type="hidden" name="op" value="freeze">
                                <input type="hidden" name="id" value="{{ $m['id'] ?? '' }}">
                                <button class="btn small secondary" type="submit">Freeze</button>
                            </form>
                            @endif
                            <form method="post" action="{{ route('mail-queue.action') }}">
                                @csrf
                                <input type="hidden" name="op" value="remove">
                                <input type="hidden" name="id" value="{{ $m['id'] ?? '' }}">
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Queue ops kya karte hain</h3>
    <p class="help" style="margin:6px 0 0"><strong>Deliver</strong> = us message ki delivery abhi try karo (exim -M) ·
        <strong>Freeze</strong> = delivery roko (exim -Mf) · <strong>Thaw</strong> = freeze hatao (exim -Mt) ·
        <strong>Remove</strong> = queue se delete (exim -Mrm) · <strong>Deliver All Now</strong> = poori queue flush (exim -qf).</p>
</div>
@endsection
