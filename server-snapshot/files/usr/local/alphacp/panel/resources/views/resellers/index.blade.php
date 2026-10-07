@extends('layouts.panel')

@section('title', 'Reseller Center')
@section('subtitle', 'WHM-style Reseller Center — resellers promote/demote + privileges (ACL)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Reseller banao</h3>
    @if ($candidates->isEmpty())
        <p class="muted">Koi user/mail-role panel user nahi jo promote ho sake.</p>
    @else
        <form method="POST" action="{{ route('resellers.store') }}">
            @csrf
            <label>Panel user
                <select name="user_id" required>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}">{{ $c->username }} ({{ $c->role?->name }})</option>
                    @endforeach
                </select>
            </label>
            <button class="btn" type="submit">Reseller banao</button>
        </form>
    @endif
</div>

<div class="card">
    <h3>Mere resellers ({{ $resellers->count() }})</h3>
    @if ($resellers->isEmpty())
        <p class="muted">Abhi koi reseller nahi.</p>
    @else
        <table>
            <tr><th>Username</th><th>Email</th><th>Bana</th><th></th></tr>
            @foreach ($resellers as $r)
            <tr>
                <td>{{ $r->username }}</td>
                <td>{{ $r->email ?? '—' }}</td>
                <td>{{ $r->updated_at?->format('d M Y') }}</td>
                <td>
                    <form method="POST" action="{{ route('resellers.destroy', $r) }}" onsubmit="return confirm('Demote karein?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Demote</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="card">
    <h3>Reseller privileges (ACL)</h3>
    <p class="muted">Jo checkboxes on hain, reseller role ko wo permissions milti hain.</p>
    <form method="POST" action="{{ route('resellers.privileges') }}">
        @csrf
        @foreach ($catalog as $module => $perms)
            <h4>{{ ucfirst($module) }}</h4>
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
        <button class="btn" type="submit">Privileges save karo</button>
    </form>
</div>
@endsection
