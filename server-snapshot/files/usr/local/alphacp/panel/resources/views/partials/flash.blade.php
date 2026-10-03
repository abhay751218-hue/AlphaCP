@foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'error', 'info' => 'info'] as $key => $class)
    @if (session($key))
        <div class="flash {{ $class }}">{{ session($key) }}</div>
    @endif
@endforeach

@if ($errors->any())
    <div class="flash error">
        <strong>Ruk jao — ye theek karo:</strong>
        <ul style="margin:6px 0 0 18px">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
