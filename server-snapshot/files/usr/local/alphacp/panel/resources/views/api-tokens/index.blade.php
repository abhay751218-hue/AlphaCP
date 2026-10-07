@extends('layouts.panel')

@section('title', 'API Tokens')
@section('subtitle', 'Manage API Tokens — apni billing software ke liye Bearer token (cPanel jaisa)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($newToken)
<div class="card" style="border:2px solid #2a2">
    <h3>Naya token (EK baar — abhi copy karo)</h3>
    <p><code style="word-break:break-all">{{ $newToken }}</code></p>
    <p class="muted">Ise apne billing software (WHMCS/Blesta/Clientexec) me <em>Authorization: Bearer</em> ke roop me daalo.</p>
</div>
@endif

<div class="card">
    <h3>Generate token</h3>
    <form method="POST" action="{{ route('api-tokens.store') }}">
        @csrf
        <label>Token name
            <input type="text" name="name" placeholder="billing" maxlength="60" required>
        </label>
        <button class="btn" type="submit">Generate</button>
    </form>
</div>

<div class="card">
    <h3>Mere tokens ({{ $tokens->count() }})</h3>
    @if ($tokens->isEmpty())
        <p class="muted">Koi token nahi.</p>
    @else
        <table>
            <tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr>
            @foreach ($tokens as $t)
            <tr>
                <td>{{ $t->name }}</td>
                <td>{{ $t->created_at?->format('d M Y') }}</td>
                <td>{{ $t->last_used_at?->format('d M Y H:i') ?? 'kabhi nahi' }}</td>
                <td>
                    <form method="POST" action="{{ route('api-tokens.destroy', $t) }}" style="display:inline" onsubmit="return confirm('Revoke?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Revoke</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
