/* ==========================================================================
   AlphaCP panel JS — chhota, dependency-free, offline (koi CDN nahi).

   1) Sidebar toggle (cPanel/WHM jaisa hamburger, mobile par nav-open)
   2) "Find functions quickly…" search — JSON index se client-side filter
      (index layout me #acpNavIndex <script type="application/json"> se aata hai)
   ========================================================================== */
(function () {
    'use strict';

    // ---------------------------------------------------------------- sidebar
    var toggle = document.getElementById('acpNavToggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var mobile = window.matchMedia('(max-width: 900px)').matches;
            document.body.classList.toggle(mobile ? 'nav-open' : 'nav-collapsed');
            toggle.setAttribute(
                'aria-expanded',
                String(document.body.classList.contains(mobile ? 'nav-open' : 'nav-collapsed'))
            );
        });
    }

    // ------------------------------------------------------------- tool search
    var input = document.querySelector('[data-nav-search]');
    var box = document.getElementById('acpSearchResults');
    var data = document.getElementById('acpNavIndex');
    if (!input || !box || !data) {
        return;
    }

    var index = [];
    try {
        index = JSON.parse(data.textContent || '[]');
    } catch (err) {
        index = [];
    }

    function render(matches, query) {
        box.innerHTML = '';
        if (!query) {
            box.hidden = true;
            return;
        }
        if (!matches.length) {
            var none = document.createElement('div');
            none.className = 'none';
            none.textContent = 'Koi tool nahi mila: ' + query;
            box.appendChild(none);
            box.hidden = false;
            return;
        }
        matches.slice(0, 12).forEach(function (item) {
            var el = document.createElement(item.url ? 'a' : 'span');
            el.className = 'item';
            if (item.url) {
                el.href = item.url;
            }
            var name = document.createElement('span');
            name.textContent = item.name;
            var sect = document.createElement('span');
            sect.className = 'sect';
            sect.textContent = item.section;
            el.appendChild(name);
            el.appendChild(sect);
            box.appendChild(el);
        });
        box.hidden = false;
    }

    function search(query) {
        var q = query.trim().toLowerCase();
        if (q.length < 1) {
            render([], '');
            return;
        }
        var matches = index.filter(function (item) {
            return (
                (item.name || '').toLowerCase().indexOf(q) !== -1 ||
                (item.section || '').toLowerCase().indexOf(q) !== -1
            );
        });
        render(matches, query.trim());
    }

    input.addEventListener('input', function () {
        search(input.value);
    });
    input.addEventListener('focus', function () {
        search(input.value);
    });
    document.addEventListener('click', function (event) {
        if (!box.contains(event.target) && event.target !== input) {
            box.hidden = true;
        }
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            input.value = '';
            render([], '');
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            var first = box.querySelector('a');
            if (first) {
                window.location.href = first.href;
            }
        }
    });
})();
