/* ============================================================================
   AlphaCP panel.js — saara panel JavaScript EK external file me (CSP-safe).
   Kyun: PanelSecurityHeaders CSP bhejta hai `script-src 'self'` — inline
   <script> blocks browser block kar deta tha (hamburger/search/favorites
   kuch nahi chalta tha). External file 'self' se allow hai.
   No build step, no dependencies. Sab features guarded hain — jo element
   page par nahi hai, wo block chup-chaap skip ho jata hai.
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------- mobile hamburger (☰)
     Capture-phase delegation: koi aur handler stopPropagation() kare to bhi
     ye chalega. Sidebar ke bahar tap / Escape / link tap -> drawer band. */
  function isOpen() { return document.body.classList.contains('nav-open'); }
  function setOpen(open) {
    document.body.classList.toggle('nav-open', open);
    var b = document.getElementById('acp-nav-toggle');
    if (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('#acp-nav-toggle') : null;
    if (t) { e.preventDefault(); setOpen(!isOpen()); return; }
    if (!isOpen()) { return; }
    if (e.target.closest && e.target.closest('.sidenav')) {
      if (e.target.closest('.sidenav a[href]')) { setOpen(false); }
      return;
    }
    setOpen(false);
  }, true);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) { setOpen(false); }
  });

  /* ------------------------------------------------- collapsible sections */
  document.addEventListener('click', function (e) {
    var c = e.target.closest ? e.target.closest('.sect-head .chev') : null;
    if (c && c.closest('.sect-card')) { c.closest('.sect-card').classList.toggle('collapsed'); }
  });

  /* ------------------------------------------------- top search (cPanel) */
  var q = document.getElementById('acp-search');
  if (q) {
    q.addEventListener('input', function () {
      var v = q.value.trim().toLowerCase();
      document.querySelectorAll('.grid.tiles').forEach(function (grid) {
        var visible = 0;
        grid.querySelectorAll('.tile').forEach(function (tile) {
          var name = tile.querySelector('.name');
          var hit = v === '' || (name && name.textContent.toLowerCase().indexOf(v) !== -1);
          tile.classList.toggle('hidden', !hit);
          if (hit) { visible++; }
        });
        var sect = grid.closest('.sect-card');
        if (sect) { sect.classList.toggle('hidden', visible === 0 && v !== ''); }
        var head = grid.previousElementSibling;
        if (head && head.classList && head.classList.contains('section-title')) {
          head.classList.toggle('hidden', visible === 0 && v !== '');
        }
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && document.activeElement !== q
          && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '')) {
        e.preventDefault();
        q.focus();
      }
    });
  }

  /* ------------------------------------------------- WHM sidebar */
  var ws = document.getElementById('whm-search');
  var tree = document.getElementById('whm-tree');
  if (ws && tree) {
    ws.addEventListener('input', function () {
      var needle = ws.value.trim().toLowerCase();
      tree.querySelectorAll('section.whm-group').forEach(function (sec) {
        var any = false;
        sec.querySelectorAll('a, span.whm-soon').forEach(function (el) {
          var row = el.closest('.whm-item') || el;
          var hit = needle === '' || el.textContent.toLowerCase().indexOf(needle) !== -1;
          row.style.display = hit ? '' : 'none';
          if (hit) { any = true; }
        });
        var btn = sec.querySelector('.whm-groupbtn');
        var nameHit = needle !== '' && btn && btn.textContent.toLowerCase().indexOf(needle) !== -1;
        sec.style.display = (needle === '' || any || nameHit) ? '' : 'none';
      });
    });

    tree.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('button.whm-groupbtn') : null;
      if (btn) {
        var items = btn.nextElementSibling;
        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (items) { items.style.display = open ? 'none' : ''; }
        return;
      }
      var star = e.target.closest ? e.target.closest('button.whm-fav') : null;
      if (star) {
        var link = star.closest('.whm-item') && star.closest('.whm-item').querySelector('a');
        if (link) { toggleFav(link); }
      }
    });

    /* Favorites (localStorage) */
    var KEY = 'acp_whm_favs';
    var load = function () { try { return JSON.parse(window.localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
    var save = function (list) { try { window.localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* private mode */ } };
    var toggleFav = function (a) {
      var list = load();
      var href = a.getAttribute('href');
      var name = a.getAttribute('data-favname') || a.textContent.trim();
      var at = list.findIndex(function (f) { return f.href === href; });
      if (at >= 0) { list.splice(at, 1); } else { list.push({ href: href, name: name }); }
      save(list);
      render();
    };
    var render = function () {
      var sec = document.getElementById('whm-favs');
      if (!sec) { return; }
      var list = load();
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
        if (star) {
          star.textContent = hit ? '★' : '☆';
          star.classList.toggle('on', hit);
        }
      });
    };
    render();
  }
})();
