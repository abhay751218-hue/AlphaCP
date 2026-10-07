@extends('layouts.panel')

@section('title', 'Git Version Control')
@section('subtitle', 'cPanel-style Git — repos clone/pull/status')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Repository clone karo</h3>
    <form method="POST" action="{{ route('git.clone') }}">
        @csrf
        <label>Git URL
            <input type="url" name="url" placeholder="https://github.com/user/repo.git" required>
        </label>
        <label>Folder name
            <input type="text" name="dir" placeholder="myrepo" pattern="[A-Za-z0-9._-]+" required>
        </label>
        <button class="btn" type="submit">Clone</button>
    </form>
</div>

<div class="card">
    <h3>Repositories ({{ count($repos) }})</h3>
    @if (empty($repos))
        <p class="muted">Koi git repository nahi.</p>
    @else
        <table>
            <tr><th>Repo</th><th></th><th></th></tr>
            @foreach ($repos as $r)
            <tr>
                <td>{{ $r }}</td>
                <td><a class="btn small" href="{{ route('git.status', $r) }}">Status</a></td>
                <td>
                    <form method="POST" action="{{ route('git.pull', $r) }}">
                        @csrf
                        <button class="btn small secondary" type="submit">Pull</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
