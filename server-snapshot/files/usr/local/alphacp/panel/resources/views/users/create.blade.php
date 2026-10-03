@extends('layouts.panel')

@section('title', 'Naya Panel User')
@section('subtitle', 'Login banega — hosting account Step 3 me')

@section('actions')
    <a class="btn small secondary" href="{{ route('users.index') }}">← Users</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>👤 User details</h3>
        <form method="post" action="{{ route('users.store') }}">
            @csrf

            <label for="username">Username</label>
            <input id="username" name="username" value="{{ old('username') }}" required
                   pattern="[a-z0-9][a-z0-9_.-]*" autocapitalize="none" spellcheck="false">
            <p class="help">Chhote letters, numbers, <span class="mono">. _ -</span> allowed.</p>

            <label for="full_name">Poora naam (optional)</label>
            <input id="full_name" name="full_name" value="{{ old('full_name') }}">

            <label for="email">Email (optional — alerts ke liye)</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}">

            <label for="role_id">Role</label>
            <select id="role_id" name="role_id" required>
                @foreach ($roles as $role)
                    @continue($role->isRoot() && ! auth()->user()->isRoot())
                    <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>
                        {{ $role->label }} ({{ $role->name }})
                    </option>
                @endforeach
            </select>

            <label for="password">Temporary password</label>
            <input id="password" name="password" type="password" required>
            <p class="help">User ko pehle login par ye badalna padega (force password change).</p>

            <button class="btn mt" type="submit">User banao</button>
        </form>
    </div>

    <div class="card">
        <h3>ℹ️ Role ka matlab</h3>
        <dl class="kv">
            @foreach ($roles as $role)
                <dt class="mono">{{ $role->name }}</dt>
                <dd>{{ $role->description }}</dd>
            @endforeach
        </dl>
        <p class="help mt">Har action audit log me jaata hai — kaun, kab, kya badla.</p>
    </div>
</div>
@endsection
