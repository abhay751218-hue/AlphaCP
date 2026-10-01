@extends('layouts.panel')

@section('title', 'Address Importer')
@section('subtitle', 'Bulk CSV mailboxes — existing mail.set, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers import mailboxes from CSV here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Address Importer — {{ $account->username }}</h3>
    <p class="help">CSV paste. Columns: <span class="mono">local,domain,password</span> ya <span class="mono">email,password</span>. Optional quota. Pipe/shell/foreign domain fail closed. MAXPOP {{ $maxPop }}.</p>
</div>

@can('email.manage')
<div class="card mt">
    <h3>CSV</h3>
    <form method="post" action="{{ route('address-importer.store') }}">
        @csrf
        <label for="csv">Rows</label>
        <textarea id="csv" name="csv" rows="8" required maxlength="32000" placeholder="bob,shop.example.com,CorrectHorse1">{{ old('csv') }}</textarea>
        <button class="btn mt" type="submit">Import</button>
    </form>
</div>
@endcan
@endif
@endsection
