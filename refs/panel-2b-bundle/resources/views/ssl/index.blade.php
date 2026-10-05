@extends('layouts.panel')

@section('title', 'SSL/TLS')
@section('subtitle', 'Manage SSL certificates for your domains')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')

@if ($account && $domains->count())
<div class="card">
    <h3>SSL Certificates</h3>
    <p class="help">Issue {{ "Let's Encrypt" }} or self-signed certificates for your domains.</p>
    <form method="post" action="{{ route('ssl.autossl') }}" class="mt">
        @csrf
        <button class="btn" type="submit">Run AutoSSL</button>
    </form>
    <table class="table mt">
        <thead><tr><th>Domain</th><th>SSL Status</th><th>Issuer</th><th>AutoSSL</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($domains as $domain)
            <tr>
                <td>{{ $domain->domain }}</td>
                <td>{{ $domain->ssl_status ?? 'none' }}</td>
                <td>{{ $domain->ssl_issuer ?? '—' }}</td>
                <td>{{ $domain->ssl_autossl ? 'ON' : 'OFF' }}</td>
                <td>
                    <form method="post" action="{{ route('ssl.issue', ['domain' => $domain->id]) }}" style="display:inline">@csrf<input type="hidden" name="mode" value="letsencrypt"><button class="btn small" type="submit">{{ "Let's Encrypt" }}</button></form>
                    <form method="post" action="{{ route('ssl.issue', ['domain' => $domain->id]) }}" style="display:inline">@csrf<input type="hidden" name="mode" value="selfsigned"><button class="btn small secondary" type="submit">self-signed</button></form>
                    <form method="post" action="{{ route('ssl.toggle', ['domain' => $domain->id]) }}" style="display:inline">@csrf<button class="btn small secondary" type="submit">{{ $domain->ssl_autossl ? 'Exclude' : 'Include' }}</button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@else
<div class="card">
    <h3>SSL/TLS</h3>
    <p class="empty">No domains found. Add a domain first to manage SSL certificates.</p>
</div>
@endif

@endsection