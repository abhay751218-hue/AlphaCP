@extends('layouts.panel')

@section('title', 'Deliverability')
@section('subtitle', 'SPF / DMARC copy-paste — DNS write nahi')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne SPF/DMARC records yahin dekhega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Deliverability — {{ $account->username }}</h3>
    <p class="help">Recommended TXT. File <span class="mono">~/etc/mail/deliverability.json</span>. Zone Editor DNS later. DKIM keys later.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>SPF</th>
                <th>DMARC (_dmarc)</th>
                <th>DKIM selector</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row['domain'] }}</td>
                    <td class="mono">{{ $row['spf'] }}</td>
                    <td class="mono">{{ $row['dmarc'] }}</td>
                    <td class="mono">{{ $row['dkim_selector'] }}._domainkey</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Koi domain nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Queue recommended records</h3>
    <form method="post" action="{{ route('deliverability.store') }}">
        @csrf
        <button class="btn mt" type="submit">Write deliverability.json</button>
    </form>
</div>
@endcan
@endif
@endsection
