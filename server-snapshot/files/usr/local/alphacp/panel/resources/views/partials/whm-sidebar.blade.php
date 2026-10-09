{{--
  Admin left sidebar — navigation tree: search box sabse
  upar, collapsible category groups (ModuleCatalog ke whm-audience sections),
  har live module apni route par, baqi "soon" chip ke saath.
--}}
@php
    $whmSections = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
@endphp
<nav class="whm-side" id="whm-side" aria-label="WHM navigation">
  <input type="search" id="whm-search" class="whm-search" placeholder="Search features…" autocomplete="off" aria-label="Search features">
  <a class="whm-home" href="{{ route('dashboard') }}">@include('partials.icons', ['icon' => 'home', 'cls' => 'hico']) Home</a>
  <div class="whm-tree" id="whm-tree">
    <section class="whm-group" id="whm-favs" style="display:none">
      <button type="button" class="whm-groupbtn" aria-expanded="true">Favorites</button>
      <div class="whm-items"></div>
    </section>
    @foreach ($whmSections as $key => $sec)
      <section class="whm-group">
        <button type="button" class="whm-groupbtn" aria-expanded="true">{{ $sec['label'] }}</button>
        <div class="whm-items">
          @foreach ($sec['items'] as $item)
            @if (!empty($item['route']) && \Illuminate\Support\Facades\Route::has($item['route']))
              <div class="whm-item"><a href="{{ route($item['route']) }}" data-favname="{{ $item['name'] }}">{{ $item['name'] }}</a><button type="button" class="whm-fav" title="Favorites me add/remove karo" aria-label="Favorite">☆</button></div>
            @else
              <span class="whm-soon" title="ye module is roadmap slice ke baad aayega">{{ $item['name'] }} <em>soon</em></span>
            @endif
          @endforeach
        </div>
      </section>
    @endforeach
  </div>
</nav>
