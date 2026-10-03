@extends('layouts.panel')

@section('title', 'Active Sessions')
@section('subtitle', 'Kaunse device se panel khula hua hai — kisi bhi device ko logout karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('security.index') }}">← Security</a>
@endsection

@section('content')
<div class="card">
    <div class="table-wrap">
        <table>
            <tr><th>Device</th><th>IP</th><th>Last activity</th><th>User agent</th><th></th></tr>
            @foreach ($sessions as $session)
                <tr>
                    <td>{{ $session->browser }}</td>
                    <td class="mono">{{ $session->ip_address }}</td>
                    <td class="muted">{{ $session->last_seen }}</td>
                    <td class="muted mono" style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">{{ $session->user_agent }}</td>
                    <td class="right">
                        @if ($session->id === $currentSid)
                            <span class="badge green">ye session</span>
                        @else
                            <form method="post" action="{{ route('security.sessions.destroy', $session->id) }}">
                                @csrf @method('DELETE')
                                <button class="btn small ghost" type="submit">Log out</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endsection
