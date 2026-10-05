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
    <p class="help">Paste rows or upload a CSV file. Columns: <span class="mono">local,domain,password</span> ya <span class="mono">email,password</span>. Optional quota. Pipe/shell/foreign domain fail closed. MAXPOP {{ $maxPop }}.</p>
</div>

@can('email.manage')
<div class="card mt">
    <h3>CSV</h3>
    <form method="post" enctype="multipart/form-data" action="{{ route('address-importer.store') }}">
        @csrf
        <label for="csv">Paste CSV rows</label>
        <textarea id="csv" name="csv" rows="8" maxlength="32000" placeholder="bob,shop.example.com,CorrectHorse1"></textarea>
        <label for="csv_file" class="mt">Or upload a CSV file (maximum 31 KB)</label>
        <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt,text/csv,text/plain">
        <p class="help">Use either the text box or a file, not both. Uploaded/pasted passwords are never repopulated after an error.</p>
        <button class="btn mt" type="submit">Import</button>
    </form>
</div>
@endcan
@endif
@endsection
