{{-- Single stroke icon. Usage: @include('partials.icon', ['name' => 'folder', 'size' => 18]) --}}
<svg class="ic" width="{{ $size ?? 18 }}" height="{{ $size ?? 18 }}" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#i-{{ $name }}"/></svg>
