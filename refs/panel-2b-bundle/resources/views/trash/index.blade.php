@extends('layouts.panel')

@section('title', 'Trash')
@section('subtitle', 'Deleted files — permanent delete karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Trash ({{ count($files) }})</h3>
    @if (empty($files))
        <p class="muted">Trash khaali hai.</p>
    @else
        <table>
            <tr><th>File</th><th></th></tr>
            @foreach ($files as $f)
            <tr>
                <td>{{ $f }}</td>
                <td>
                    <form method="POST" action="{{ route('trash.destroy', $f) }}" onsubmit="return confirm('Permanent delete?')">
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
