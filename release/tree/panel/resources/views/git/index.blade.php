@extends('layouts.panel')

@section('title', 'Git Version Control')
@section('subtitle', 'AlphaCP Git — repos clone/pull/status')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('cron.index') }}">Cron Jobs</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'git', 'cls' => 'hico']) Repositories</h3>
        <div class="stat"><span class="num">{{ count($repos) }}</span><span class="unit">repos cloned</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) Deploy flow</h3>
        <p class="help" style="margin:6px 0 0">Clone karo → code update aane par <strong>Pull</strong> dabao →
            <strong>Status</strong> se branch/changes dekho. Auto-deploy ke liye cron me pull job bana sakte ho.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'git', 'cls' => 'hico']) Repositories</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search repos…" data-filter-rows="#acp-git tbody tr" aria-label="Search repos">
    </div>
    <div class="table-wrap">
        <table id="acp-git">
            <thead><tr><th>Repo</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($repos as $r)
                <tr>
                    <td class="mono">@include('partials.icons', ['icon' => 'git', 'cls' => 'hico']) {{ $r }}</td>
                    <td class="right">
                        <div class="row" style="justify-content:flex-end; gap:6px">
                            <a class="btn small secondary" href="{{ route('git.status', $r) }}">Status</a>
                            <form method="POST" action="{{ route('git.pull', $r) }}">
                                @csrf
                                <button class="btn small" type="submit">Pull</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">Koi git repository nahi — neeche se clone karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'git', 'cls' => 'hico']) Clone a Repository</h3>
    <form method="POST" action="{{ route('git.clone') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:260px">
                <label for="git-url">Git URL</label>
                <input id="git-url" type="url" name="url" placeholder="https://github.com/user/repo.git" required>
            </div>
            <div>
                <label for="git-dir">Folder name</label>
                <input id="git-dir" type="text" name="dir" placeholder="myrepo" pattern="[A-Za-z0-9._-]+" required style="min-width:160px">
            </div>
            <button class="btn" type="submit">Clone</button>
        </div>
    </form>
</div>
@endsection
