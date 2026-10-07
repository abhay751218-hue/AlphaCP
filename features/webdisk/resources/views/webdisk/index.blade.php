@extends('layouts.panel')

@section('title', 'Web Disk')
@section('subtitle', 'WebDAV accounts — files ko desktop se access karo (read-only / read-write)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Naya Web Disk account</h3>
    <form method="POST" action="{{ route('webdisk.store') }}">
        @csrf
        <label>Login
            <input type="text" name="login" placeholder="designer" pattern="[A-Za-z0-9._-]+" required>
        </label>
        <label>Permissions
            <select name="permissions" required>
                <option value="rw">Read-Write</option>
                <option value="ro">Read-Only</option>
            </select>
        </label>
        <button class="btn" type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h3>Web Disk accounts ({{ $accounts->count() }})</h3>
    @if ($accounts->isEmpty())
        <p class="muted">Koi Web Disk account nahi.</p>
    @else
        <table>
            <tr><th>Login</th><th>Permissions</th><th></th></tr>
            @foreach ($accounts as $a)
            <tr>
                <td>{{ $a->login }}</td>
                <td>{{ $a->permissions === 'rw' ? 'Read-Write' : 'Read-Only' }}</td>
                <td>
                    <form method="POST" action="{{ route('webdisk.destroy', $a) }}" onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
