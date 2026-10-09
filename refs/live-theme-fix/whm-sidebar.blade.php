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
        var row = el.closest('.whm-item') || el;
        var hit = needle === '' || el.textContent.toLowerCase().indexOf(needle) !== -1;
        row.style.display = hit ? '' : 'none';
        if (hit) { any = true; }
      });
      var btn = sec.querySelector('.whm-groupbtn');
      var nameHit = needle !== '' && btn.textContent.toLowerCase().indexOf(needle) !== -1;
      sec.style.display = (needle === '' || any || nameHit) ? '' : 'none';
    });
  });
  tree.addEventListener('click', function (e) {
    var btn = e.target.closest('button.whm-groupbtn');
    if (btn) {
      var items = btn.nextElementSibling;
      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      items.style.display = open ? 'none' : '';
      return;
    }
    var star = e.target.closest('button.whm-fav');
    if (star) { toggleFav(star.closest('.whm-item').querySelector('a')); }
  });

  /* ---- Favorites (localStorage; cPanel server-side store parity note: docs) ---- */
  var KEY = 'acp_whm_favs';
  function load() { try { return JSON.parse(window.localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function save(list) { window.localStorage.setItem(KEY, JSON.stringify(list)); }
  function toggleFav(a) {
    var list = load();
    var href = a.getAttribute('href');
    var name = a.getAttribute('data-favname') || a.textContent.trim();
    var at = list.findIndex(function (f) { return f.href === href; });
    if (at >= 0) { list.splice(at, 1); } else { list.push({ href: href, name: name }); }
    save(list);
    render();
  }
  function render() {
    var list = load();
    var sec = document.getElementById('whm-favs');
    var box = sec.querySelector('.whm-items');
    box.innerHTML = '';
    list.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'whm-item';
      var a = document.createElement('a');
      a.href = f.href; a.textContent = f.name; a.setAttribute('data-favname', f.name);
      var star = document.createElement('button');
      star.type = 'button'; star.className = 'whm-fav on'; star.textContent = '★';
      star.title = 'Favorites se hatao';
      row.appendChild(a); row.appendChild(star);
      box.appendChild(row);
    });
    sec.style.display = list.length ? '' : 'none';
    tree.querySelectorAll('section.whm-group:not(#whm-favs) .whm-item > a').forEach(function (a) {
      var hit = list.some(function (f) { return f.href === a.getAttribute('href'); });
      var star = a.parentElement.querySelector('.whm-fav');
      star.textContent = hit ? '★' : '☆';
      star.classList.toggle('on', hit);
    });
  }
  render();
})();
</script>
