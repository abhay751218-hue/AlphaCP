@extends('layouts.panel')

@section('title', 'Domains')
@section('subtitle', 'Main, addon, subdomain, alias (parked) aur redirects — cPanel jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')

@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Create the account in WHM; the customer manages domains here.</p>
    <p class="help mt">Accounts page: <a href="{{ route('accounts.index') }}">List Accounts →</a></p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login. Domains appear here after a hosting account is created.</p>
</div>
@else
<div class="card">
    <h3>Is account ke domains</h3>
    <p class="help">{{ $account->username }} · {{ $account->main_domain }} · package {{ $account->package?->name }}</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>Document root</th>
                <th>Status</th>
                <th></th>
            </tr>
            @forelse ($domains as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge {{ $row->type === 'main' ? 'blue' : 'green' }}">{{ $row->type }}</span></td>
                    <td class="muted">{{ $row->type === 'redirect' ? ($row->redirect_code . ' → ' . $row->redirect_url) : $row->document_root }}</td>
                    <td><span class="badge {{ $row->status === 'active' ? 'green' : 'amber' }}">{{ $row->status }}</span></td>
                    <td class="right">
                        @can('domains.manage')
                            @if (! $row->isMain() && $row->status !== 'removing')
                                <form method="post" action="{{ route('domains.destroy', $row) }}" onsubmit="return confirm('Remove this domain? Files will not be deleted.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">remove</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No domains yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('domains.manage')
<div class="card mt">
    <h3>New domain</h3>
    <p class="help">Addon = alag site. Subdomain = blog.{{ $account->main_domain }}. Alias = parked. Redirect = 301/302.</p>
    <form method="post" action="{{ route('domains.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="type">Type</label>
                <select id="type" name="type" required>
                    <option value="addon">Addon domain</option>
                    <option value="sub">Subdomain</option>
                    <option value="parked">Alias (parked)</option>
                    <option value="redirect">Redirect</option>
                </select>
                <label for="domain">Domain (FQDN)</label>
                <input id="domain" name="domain" required maxlength="190" placeholder="blog.{{ $account->main_domain }}" autocapitalize="none">
            </div>
            <div>
                <label for="redirect_url">Redirect URL (sirf redirect type)</label>
                <input id="redirect_url" name="redirect_url" maxlength="500" placeholder="https://example.com/">
                <label for="redirect_code">Redirect code</label>
                <select id="redirect_code" name="redirect_code">
                    <option value="301">301 permanent</option>
                    <option value="302">302 temporary</option>
                </select>
            </div>
        </div>
        <button class="btn mt" type="submit">Add domain</button>
    </form>
</div>
@endcan
@endif

@endsection
