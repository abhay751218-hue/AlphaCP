@extends('layouts.panel')

@section('title', 'Email Routing')
@section('subtitle', 'Per-domain MX mode — auto / local / backup / remote')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set per-domain mail routing here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Email Routing — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/routing.json</span>. Exim localdomains later. Mode enum only — no host/pipe.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Mode</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->mode }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No routing rows yet — default is auto.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Set routing</h3>
    <form method="post" action="{{ route('email-routing.store') }}">
        @csrf
        <label for="domain">Domain</label>
        <select id="domain" name="domain" required>
            @forelse ($domains as $d)
                <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
            @empty
                <option value="" disabled>No domain</option>
            @endforelse
        </select>
        <label for="mode">Mode</label>
        <select id="mode" name="mode" required>
            @foreach ($modes as $m)
                <option value="{{ $m }}" @selected(old('mode', 'auto') === $m)>{{ $m }}</option>
            @endforeach
        </select>
        <button class="btn mt" type="submit">Save routing</button>
    </form>
</div>
@endcan
@endif
@endsection
