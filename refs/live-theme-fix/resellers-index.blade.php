@extends('layouts.panel')

@section('title', 'Reseller Center')
@section('subtitle', 'Resellers promote/demote + privileges (ACL)')

@section('actions')
    <a class="btn small secondary" href="{{ route('users.index') }}">User Manager</a>
    <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Resellers</h3>
        <div class="stat"><span class="num">{{ $resellers->count() }}</span><span class="unit">active resellers</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'user', 'cls' => 'hico']) Promote</h3>
        @if ($candidates->isEmpty())
            <p class="help" style="margin:6px 0 0">Koi user/mail-role panel user nahi jo promote ho sake.</p>
        @else
            <form method="POST" action="{{ route('resellers.store') }}">
                @csrf
                <div class="row" style="flex-wrap:wrap; align-items:flex-end">
                    <div style="flex:1; min-width:160px">
                        <label for="rs-user">Panel user</label>
                        <select id="rs-user" name="user_id" required>
                            @foreach ($candidates as $c)
                                <option value="{{ $c->id }}">{{ $c->username }} ({{ $c->role?->name }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn" type="submit">Reseller banao</button>
                </div>
            </form>
        @endif
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Mere Resellers</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-resellers tbody tr" aria-label="Search resellers">
    </div>
    <div class="table-wrap">
        <table id="acp-resellers">
            <thead><tr><th>Username</th><th>Email</th><th>Bana</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($resellers as $r)
                <tr>
                    <td class="mono">@include('partials.icons', ['icon' => 'user', 'cls' => 'hico']) {{ $r->username }}</td>
                    <td>{{ $r->email ?? '—' }}</td>
                    <td class="muted">{{ $r->updated_at?->format('d M Y') }}</td>
                    <td class="right">
                        <form method="POST" action="{{ route('resellers.destroy', $r) }}" onsubmit="return confirm('Demote karein?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Demote</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Abhi koi reseller nahi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Reseller Privileges (ACL)</h3>
    <p class="help">Jo checkboxes on hain, reseller role ko wo permissions milti hain.</p>
    <form method="POST" action="{{ route('resellers.privileges') }}">
        @csrf
        @foreach ($catalog as $module => $perms)
            <h4 class="mt">{{ ucfirst($module) }}</h4>
            <div style="display:flex;flex-wrap:wrap;gap:12px">
                @foreach ($perms as $p)
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" name="permissions[]" value="{{ $p['key'] }}"
                            @checked(in_array($p['key'], $current, true))>
                        {{ $p['label'] }}
                    </label>
                @endforeach
            </div>
        @endforeach
        <button class="btn mt" type="submit">Privileges Save Karo</button>
    </form>
</div>
@endsection
