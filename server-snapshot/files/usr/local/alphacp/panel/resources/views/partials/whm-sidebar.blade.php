{{--
  WHM left sidebar — cPanel WHM ke navigation tree jaisa: search box sabse
  upar, collapsible category groups (ModuleCatalog ke whm-audience sections),
  har live module apni route par, baqi "soon" chip ke saath.
--}}
@php
    $whmSections = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
@endphp
<nav class="whm-side" id="whm-side" aria-label="WHM navigation">
  <input type="search" id="whm-search" class="whm-search" placeholder="Search WHM features…" autocomplete="off" aria-label="Search WHM features">
  <a class="whm-home" href="{{ route('dashboard') }}">⌂ Home</a>
  <div class="whm-tree" id="whm-tree">
    @foreach ($whmSections as $key => $sec)
      <section class="whm-group">
        <button type="button" class="whm-groupbtn" aria-expanded="true">{{ $sec['label'] }}</button>
        <div class="whm-items">
          @foreach ($sec['items'] as $item)
            @if (!empty($item['route']) && \Illuminate\Support\Facades\Route::has($item['route']))
              <a href="{{ route($item['route']) }}">{{ $item['name'] }}</a>
            @else
              <span class="whm-soon" title="ye module is roadmap slice ke baad aayega">{{ $item['name'] }} <em>soon</em></span>
            @endif
          @endforeach
        </div>
      </section>
    @endforeach
  </div>
</nav>
<script>
/* WHM sidebar: live search filter + group collapse (vanilla JS, no build step). */
(function () {
  var q = document.getElementById('whm-search');
  var tree = document.getElementById('whm-tree');
  if (!q || !tree) { return; }
  q.addEventListener('input', function () {
    var needle = q.value.trim().toLowerCase();
    tree.querySelectorAll('section.whm-group').forEach(function (sec) {
      var any = false;
      sec.querySelectorAll('a, span.whm-soon').forEach(function (el) {
        var hit = needle === '' || el.textContent.toLowerCase().indexOf(needle) !== -1;
        el.style.display = hit ? '' : 'none';
        if (hit) { any = true; }
      });
      var btn = sec.querySelector('.whm-groupbtn');
      var nameHit = needle !== '' && btn.textContent.toLowerCase().indexOf(needle) !== -1;
      sec.style.display = (needle === '' || any || nameHit) ? '' : 'none';
    });
  });
  tree.addEventListener('click', function (e) {
    var btn = e.target.closest('button.whm-groupbtn');
    if (!btn) { return; }
    var items = btn.nextElementSibling;
    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    items.style.display = open ? 'none' : '';
  });
})();
</script>
