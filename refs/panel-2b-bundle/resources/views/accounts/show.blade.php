@extends('layouts.panel')

@section('title', $account->username)
@section('subtitle', $account->main_domain)

@section('actions')
    <a class="btn small secondary" href="{{ route('accounts.index') }}">← Accounts</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>Account</h3>
        <dl class="kv">
            <dt>Username</dt><dd class="mono">{{ $account->username }}</dd>
            <dt>Domain</dt><dd>{{ $account->main_domain }}</dd>
            <dt>Status</dt>
            <dd>
                <span class="badge {{ $account->status === 'active' ? 'green' : ($account->status === 'suspended' ? 'amber' : ($account->status === 'terminated' ? 'red' : 'blue')) }}">
                    {{ $account->status }}
                </span>
            </dd>
            <dt>Package</dt><dd>{{ $account->package?->name ?? '—' }}</dd>
            <dt>Quota</dt><dd>{{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</dd>
            <dt>PHP</dt><dd class="mono">{{ $account->php_version }}</dd>
            <dt>Home</dt><dd class="mono">{{ $account->home_path }}</dd>
            <dt>Email</dt><dd>{{ $account->contact_email }}</dd>
            <dt>Owner login</dt><dd class="mono">{{ $account->owner?->username ?? '—' }}</dd>
            @if ($account->suspend_reason)
                <dt>Suspend reason</dt><dd>{{ $account->suspend_reason }}</dd>
            @endif
            @if (!empty($account->meta['last_error']))
                <dt>Last error</dt><dd class="error">{{ $account->meta['last_error'] }}</dd>
            @endif
        </dl>
        @if ($task)
            <p class="help mt">Aakhri task: <span class="mono">{{ $task->type }}</span> · {{ $task->status }}
                @if ($task->error) — {{ $task->error }} @endif
            </p>
        @endif
    </div>

    <div class="card">
        <h3>Actions</h3>
        @can('accounts.suspend')
            @if (! $account->isTerminated())
                <form method="post" action="{{ route('accounts.suspend', $account) }}" class="mb">
                    @csrf
                    <label for="reason">Suspend reason</label>
                    <input id="reason" name="reason" maxlength="255" value="{{ old('reason') }}">
                    <button class="btn small mt" type="submit">Suspend</button>
                </form>
                <form method="post" action="{{ route('accounts.unsuspend', $account) }}" class="mb">
                    @csrf
                    <button class="btn small secondary" type="submit">Unsuspend</button>
                </form>
            @endif
        @endcan

        @can('accounts.terminate')
            @if (! $account->isTerminated())
                <form method="post" action="{{ route('accounts.terminate', $account) }}">
                    @csrf
                    <label for="confirm_username">Terminate — username type karo</label>
                    <input id="confirm_username" name="confirm_username" required autocapitalize="none" spellcheck="false">
                    <p class="help">Ye undo nahi hota: Linux user, home, vhost, pool hatt jaayenge.</p>
                    <button class="btn small danger mt" type="submit">Terminate account</button>
                </form>
            @endif
        @endcan
    </div>
</div>

<div class="card mt">
    <h3>Lifecycle</h3>
    <div class="table-wrap">
        <table>
            <tr><th>When</th><th>Event</th><th>Message</th></tr>
            @forelse ($account->events as $event)
                <tr>
                    <td class="muted">{{ $event->created_at }}</td>
                    <td class="mono">{{ $event->event }}</td>
                    <td>{{ $event->message }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi event nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
