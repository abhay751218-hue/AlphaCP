/* AlphaCP File Manager pro v2 (D34) — demo-1 rebuild.
   Checkbox multi-select + sort + toolbar + right-click menu + fetch ops + upload progress.
   CSP: script-src 'self' — koi inline JS nahi. */
(function () {
  'use strict';
  var table = document.getElementById('acp-files');
  if (!table) { return; }
  var tb = table.tBodies[0];
  var csrfEl = document.getElementById('fm-csrf');
  var csrf = csrfEl ? csrfEl.value : '';
  var curPath = table.getAttribute('data-path') || '';
  var url = function (k) { return table.getAttribute('data-url-' + k) || ''; };

  /* ---------------- toast ---------------- */
  var toast = document.createElement('div');
  toast.id = 'fm-toast';
  toast.innerHTML = '<span id="fm-toast-text"></span><div class="bar"><span id="fm-toast-bar"></span></div>';
  document.body.appendChild(toast);
  var tTxt = toast.querySelector('#fm-toast-text');
  var tBar = toast.querySelector('#fm-toast-bar');
  var tBarWrap = toast.querySelector('.bar');
  var tTimer = null;
  function showToast(msg, sticky) {
    tTxt.textContent = msg;
    tBarWrap.style.display = 'none';
    toast.style.display = 'block';
    if (tTimer) { window.clearTimeout(tTimer); }
    if (!sticky) { tTimer = window.setTimeout(function () { toast.style.display = 'none'; }, 3200); }
  }

  /* ---------------- selection ---------------- */
  var active = null;
  function rows() { return Array.prototype.slice.call(tb.querySelectorAll('tr[data-fm]')); }
  function checked() { return rows().filter(function (r) { return r.querySelector('.fm-ck').checked; }); }
  function paint() {
    rows().forEach(function (r) {
      r.classList.toggle('fm-sel', r.querySelector('.fm-ck').checked);
    });
  }
  function setCk(tr, on) { tr.querySelector('.fm-ck').checked = on; if (on) { active = tr; } paint(); }
  function needSel() {
    var c = checked();
    if (c.length === 0) { showToast('Pehle file select karo — row par tap ya checkbox'); return null; }
    active = active && c.indexOf(active) !== -1 ? active : c[c.length - 1];
    return c;
  }
  var ckAll = document.getElementById('fm-ck-all');
  if (ckAll) {
    ckAll.addEventListener('change', function () {
      rows().forEach(function (r) { r.querySelector('.fm-ck').checked = ckAll.checked; });
      paint();
    });
  }
  tb.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('fm-ck')) {
      if (e.target.checked) { active = e.target.closest('tr'); }
      paint();
    }
  });
  tb.addEventListener('click', function (e) {
    var tr = e.target.closest ? e.target.closest('tr[data-fm]') : null;
    if (tr && !e.target.closest('a, input, button, select, label')) {
      setCk(tr, !tr.querySelector('.fm-ck').checked);
    }
  });
  tb.addEventListener('dblclick', function (e) {
    var tr = e.target.closest ? e.target.closest('tr[data-fm]') : null;
    if (tr && tr.getAttribute('data-open')) { window.location.href = tr.getAttribute('data-open'); }
  });

  /* ---------------- fetch ops ---------------- */
  function post(u, data) {
    var fd = new FormData();
    fd.append('_token', csrf);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return window.fetch(u, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  }
  function reload(msg) {
    showToast(msg + ' — list refresh...', true);
    window.setTimeout(function () { window.location.reload(); }, 1400);
  }
  function parentOf(p) {
    var i = p.lastIndexOf('/');
    return i === -1 ? '' : p.slice(0, i);
  }
  function join(dir, name) { return dir === '' ? name : dir.replace(/\/+$/, '') + '/' + name; }
  function seq(list, fn) { /* ek-ek karke POST (order safe) */
    return list.reduce(function (p, item) { return p.then(function () { return fn(item); }); }, Promise.resolve());
  }

  /* ---------------- actions ---------------- */
  function doAct(act) {
    if (act === 'restore') { window.location.href = (document.getElementById('fm-toolbar') || {}).getAttribute ? document.getElementById('fm-toolbar').getAttribute('data-restore') : '#'; return; }
    var c = needSel();
    if (!c) { return; }
    var a = active;
    var apath = a.getAttribute('data-path');
    var aname = a.getAttribute('data-name');

    if (act === 'open') {
      if (a.getAttribute('data-open')) { window.location.href = a.getAttribute('data-open'); }
      return;
    }
    if (act === 'download') {
      showToast('Bade files FTP/SFTP se download karo — panel download agle update me aa raha hai');
      return;
    }
    if (act === 'edit') {
      var nameEl = document.getElementById('file-name');
      if (nameEl) { nameEl.value = aname; }
      var card = document.getElementById('fm-newfile');
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      return;
    }
    if (act === 'rename') {
      var nn = window.prompt('New name:', aname);
      if (!nn || nn === aname) { return; }
      post(url('rename'), { path: apath, to: join(parentOf(apath), nn) })
        .then(function () { reload('Rename queued'); });
      return;
    }
    if (act === 'move') {
      var dest = window.prompt('Move to folder (path):', curPath);
      if (dest === null) { return; }
      seq(c, function (r) {
        return post(url('rename'), { path: r.getAttribute('data-path'), to: join(dest.trim(), r.getAttribute('data-name')) });
      }).then(function () { reload('Move queued (' + c.length + ')'); });
      return;
    }
    if (act === 'copy') {
      var cdest = window.prompt('Copy to folder (path):', curPath);
      if (cdest === null) { return; }
      seq(c, function (r) {
        return post(url('copy'), { path: r.getAttribute('data-path'), to: join(cdest.trim(), r.getAttribute('data-name')) });
      }).then(function () { reload('Copy queued (' + c.length + ')'); });
      return;
    }
    if (act === 'perm') {
      var pm = window.prompt('Permissions (octal, jaise 644 / 755):', a.getAttribute('data-mode') || '644');
      if (!pm) { return; }
      pm = pm.trim();
      if (!/^[0-7]{3}$/.test(pm)) { showToast('Mode 3 octal digits hona chahiye (jaise 644)'); return; }
      seq(c, function (r) { return post(url('chmod'), { path: r.getAttribute('data-path'), mode: pm }); })
        .then(function () { reload('Permissions queued (' + c.length + ')'); });
      return;
    }
    if (act === 'compress') {
      post(url('compress'), { path: apath }).then(function () { reload('Compress queued'); });
      return;
    }
    if (act === 'extract') {
      if (a.getAttribute('data-arch') !== '1') { showToast('Ye archive nahi hai (.tar.gz / .zip chahiye)'); return; }
      post(url('extract'), { path: apath }).then(function () { reload('Extract queued'); });
      return;
    }
    if (act === 'delete') {
      var names = c.map(function (r) { return r.getAttribute('data-name'); }).join(', ');
      if (!window.confirm('Delete ' + c.length + ' item(s)? ' + names.slice(0, 120) +
          (c.some(function (r) { return r.getAttribute('data-dir') === '1'; }) ? '\nFolders ke andar ka sab kuch delete hoga!' : ''))) { return; }
      seq(c, function (r) { return post(url('destroy'), { path: r.getAttribute('data-path') }); })
        .then(function () { reload('Delete queued (' + c.length + ')'); });
    }
  }

  document.querySelectorAll('#fm-toolbar [data-act]').forEach(function (btn) {
    btn.addEventListener('click', function (e) { e.preventDefault(); doAct(btn.getAttribute('data-act')); });
  });

  /* ---------------- right-click / long-press menu ---------------- */
  var ctx = document.createElement('div');
  ctx.id = 'fm-ctx';
  document.body.appendChild(ctx);
  function hideCtx() { ctx.style.display = 'none'; }
  function showCtx(tr, x, y) {
    rows().forEach(function (r) { r.querySelector('.fm-ck').checked = false; });
    setCk(tr, true);
    var isDir = tr.getAttribute('data-dir') === '1';
    var isArch = tr.getAttribute('data-arch') === '1';
    var items = [];
    if (isDir) { items.push(['open', '📂 Open']); }
    items.push(['download', '⬇️ Download']);
    items.push(['rename', '✏️ Rename']);
    items.push(['copy', '📋 Copy']);
    items.push(['move', '➡️ Move']);
    items.push(['perm', '🔑 Permissions']);
    items.push([isArch ? 'extract' : 'compress', isArch ? '📦 Extract' : '🗜️ Compress']);
    items.push(['sep', '']);
    items.push(['delete', '🗑️ Delete']);
    ctx.innerHTML = '';
    items.forEach(function (it) {
      if (it[0] === 'sep') { var s = document.createElement('div'); s.className = 'sep'; ctx.appendChild(s); return; }
      var bt = document.createElement('button');
      bt.type = 'button';
      bt.textContent = it[1];
      if (it[0] === 'delete') { bt.className = 'danger'; }
      bt.addEventListener('click', function () { hideCtx(); doAct(it[0]); });
      ctx.appendChild(bt);
    });
    ctx.style.display = 'block';
    var w = ctx.offsetWidth, h = ctx.offsetHeight;
    ctx.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 6)) + 'px';
    ctx.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 6)) + 'px';
  }
  document.addEventListener('contextmenu', function (e) {
    var tr = e.target.closest ? e.target.closest('#acp-files tbody tr[data-fm]') : null;
    if (tr) { e.preventDefault(); showCtx(tr, e.clientX, e.clientY); } else { hideCtx(); }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#fm-ctx')) { hideCtx(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideCtx(); } });
  window.addEventListener('scroll', hideCtx, true);

  /* ---------------- column sort ---------------- */
  var sortDir = {};
  document.querySelectorAll('#acp-files thead th[data-sort]').forEach(function (th) {
    th.addEventListener('click', function () {
      var key = th.getAttribute('data-sort');
      sortDir[key] = !sortDir[key];
      var asc = sortDir[key];
      var list = rows();
      list.sort(function (a, b) {
        var va, vb;
        if (key === 'name') {
          /* folders pehle (cPanel-style) */
          var da = a.getAttribute('data-dir'), db = b.getAttribute('data-dir');
          if (da !== db) { return db - da; }
          va = (a.getAttribute('data-name') || '').toLowerCase();
          vb = (b.getAttribute('data-name') || '').toLowerCase();
          return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        }
        va = parseInt(a.getAttribute('data-' + key) || '0', 10);
        vb = parseInt(b.getAttribute('data-' + key) || '0', 10);
        return asc ? va - vb : vb - va;
      });
      list.forEach(function (r) { tb.appendChild(r); });
    });
  });

  /* ---------------- upload progress ---------------- */
  var form = document.getElementById('fm-upload-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      var fileInput = form.querySelector('input[type=file]');
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) { return; }
      if (!window.XMLHttpRequest || !window.FormData) { return; }
      e.preventDefault();
      var f = fileInput.files[0];
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action'), true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      toast.style.display = 'block';
      tBarWrap.style.display = 'block';
      tTxt.textContent = 'Uploading ' + f.name + ' — 0%';
      tBar.style.width = '0%';
      var btn = form.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; }
      xhr.upload.addEventListener('progress', function (ev) {
        if (ev.lengthComputable) {
          var p = Math.round((ev.loaded / ev.total) * 100);
          tBar.style.width = p + '%';
          tTxt.textContent = 'Uploading ' + f.name + ' — ' + p + '%';
        }
      });
      xhr.addEventListener('load', function () {
        tBar.style.width = '100%';
        tTxt.textContent = '✅ Upload ho gaya — file list refresh...';
        window.setTimeout(function () { window.location.reload(); }, 1600);
      });
      xhr.addEventListener('error', function () {
        tTxt.textContent = '❌ Upload fail — dobara try karo';
        if (btn) { btn.disabled = false; }
      });
      xhr.send(new FormData(form));
    });
  }
}());
