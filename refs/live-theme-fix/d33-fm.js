/* AlphaCP File Manager pro (D33) — selection + right-click menu + upload progress.
   CSP: script-src 'self' — sab yahin, koi inline JS nahi. */
(function () {
  'use strict';
  var sel = null;

  function rows() { return document.querySelectorAll('#acp-files tbody tr[data-fm]'); }
  function select(tr) {
    rows().forEach(function (r) { r.classList.remove('fm-sel'); });
    sel = tr || null;
    if (sel) { sel.classList.add('fm-sel'); }
  }
  function rowForm(tr, piece) {
    var f = null;
    tr.querySelectorAll('form').forEach(function (fm) {
      if ((fm.getAttribute('action') || '').indexOf(piece) !== -1) { f = fm; }
    });
    return f;
  }
  function doAct(act, tr) {
    if (!tr) { return; }
    if (act === 'open') {
      var href = tr.getAttribute('data-open');
      if (href) { window.location.href = href; }
      return;
    }
    if (act === 'rename') {
      var from = document.getElementById('ren-from');
      var to = document.getElementById('ren-to');
      if (from) { from.value = tr.getAttribute('data-path') || ''; }
      if (to) { to.value = tr.getAttribute('data-path') || ''; }
      var card = document.getElementById('fm-rename');
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      if (to) { to.focus(); }
      return;
    }
    if (act === 'perm') {
      var d = tr.querySelector('details.fm-act');
      if (d) { d.open = true; }
      tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (act === 'compress' || act === 'extract') {
      var f = rowForm(tr, act === 'compress' ? 'files/compress' : 'files/extract')
           || rowForm(tr, act === 'compress' ? 'compress' : 'extract');
      if (f) { f.submit(); }
      return;
    }
    if (act === 'delete') {
      var df = rowForm(tr, 'destroy') || rowForm(tr, 'delete');
      if (df && window.confirm('Delete ' + (tr.getAttribute('data-name') || '') + '?' +
          (tr.getAttribute('data-dir') === '1' ? ' Folder + andar ka sab kuch delete hoga!' : ''))) {
        df.submit();
      }
    }
  }

  /* ---------------- selection by click ---------------- */
  document.addEventListener('click', function (e) {
    var tr = e.target.closest ? e.target.closest('#acp-files tbody tr[data-fm]') : null;
    if (tr && !e.target.closest('a, button, form, details, input, select, label')) {
      select(tr === sel ? null : tr);
    }
  });

  /* ---------------- toolbar wiring ---------------- */
  document.querySelectorAll('.fm-bar [data-act]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (!sel) { return; } /* bina selection: normal anchor scroll */
      e.preventDefault();
      doAct(btn.getAttribute('data-act'), sel);
    });
  });

  /* ---------------- right-click / long-press context menu ---------------- */
  var ctx = document.createElement('div');
  ctx.id = 'fm-ctx';
  document.body.appendChild(ctx);

  function hideCtx() { ctx.style.display = 'none'; }

  function showCtx(tr, x, y) {
    select(tr);
    var isDir = tr.getAttribute('data-dir') === '1';
    var isArch = tr.getAttribute('data-arch') === '1';
    var items = [];
    if (isDir) { items.push(['open', '📂 Open']); }
    items.push(['rename', '✏️ Rename / Move']);
    items.push(['perm', '🔐 Permissions']);
    items.push([isArch ? 'extract' : 'compress', isArch ? '📦 Extract' : '🗜️ Compress']);
    items.push(['sep', '']);
    items.push(['delete', '🗑️ Delete']);
    ctx.innerHTML = '';
    items.forEach(function (it) {
      if (it[0] === 'sep') {
        var s = document.createElement('div'); s.className = 'sep'; ctx.appendChild(s); return;
      }
      var bt = document.createElement('button');
      bt.type = 'button';
      bt.textContent = it[1];
      if (it[0] === 'delete') { bt.className = 'danger'; }
      bt.addEventListener('click', function () { hideCtx(); doAct(it[0], tr); });
      ctx.appendChild(bt);
    });
    ctx.style.display = 'block';
    var w = ctx.offsetWidth, h = ctx.offsetHeight;
    ctx.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 6)) + 'px';
    ctx.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 6)) + 'px';
  }

  document.addEventListener('contextmenu', function (e) {
    var tr = e.target.closest ? e.target.closest('#acp-files tbody tr[data-fm]') : null;
    if (tr) { e.preventDefault(); showCtx(tr, e.clientX, e.clientY); }
    else { hideCtx(); }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#fm-ctx')) { hideCtx(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideCtx(); } });
  window.addEventListener('scroll', hideCtx, true);

  /* ---------------- upload progress toast ---------------- */
  var form = document.getElementById('fm-upload-form');
  if (form) {
    var toast = document.createElement('div');
    toast.id = 'fm-toast';
    toast.innerHTML = '<span id="fm-toast-text"></span><div class="bar"><span id="fm-toast-bar"></span></div>';
    document.body.appendChild(toast);
    var txt = toast.querySelector('#fm-toast-text');
    var bar = toast.querySelector('#fm-toast-bar');

    form.addEventListener('submit', function (e) {
      var fileInput = form.querySelector('input[type=file]');
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) { return; }
      if (!window.XMLHttpRequest || !window.FormData) { return; } /* fallback: normal submit */
      e.preventDefault();
      var f = fileInput.files[0];
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action'), true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      toast.style.display = 'block';
      txt.textContent = 'Uploading ' + f.name + ' — 0%';
      bar.style.width = '0%';
      var btn = form.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; }
      xhr.upload.addEventListener('progress', function (ev) {
        if (ev.lengthComputable) {
          var p = Math.round((ev.loaded / ev.total) * 100);
          bar.style.width = p + '%';
          txt.textContent = 'Uploading ' + f.name + ' — ' + p + '%';
        }
      });
      xhr.addEventListener('load', function () {
        bar.style.width = '100%';
        txt.textContent = '✅ Upload ho gaya — file list refresh...';
        window.setTimeout(function () { window.location.reload(); }, 1600);
      });
      xhr.addEventListener('error', function () {
        txt.textContent = '❌ Upload fail — dobara try karo (ya page refresh)';
        if (btn) { btn.disabled = false; }
      });
      xhr.send(new FormData(form));
    });
  }
}());
