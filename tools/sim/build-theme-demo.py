#!/usr/bin/env python3
"""Build demo/cpanel-theme-demo.html — a self-contained static preview of the
AlphaCP "Paper Lantern" theme (cPanel-style: #FF6C2C accent, light canvas,
icon grid, right-rail info panels).

The demo is generated FROM the real sources so it can never drift:
  · CSS   ← panel/public/css/panel.css
  · icons ← panel/resources/views/partials/icons.blade.php (SVG sprite)
  · tools ← panel/config/panel_modules.php (sections, colors, per-tile icons)

Usage:  python3 tools/sim/build-theme-demo.py
Output: demo/cpanel-theme-demo.html  (open directly in any browser)
"""
from __future__ import annotations

import html
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CSS_PATH = ROOT / "panel/public/css/panel.css"
SPRITE_PATH = ROOT / "panel/resources/views/partials/icons.blade.php"
CONFIG_PATH = ROOT / "panel/config/panel_modules.php"
OUT_PATH = ROOT / "demo/cpanel-theme-demo.html"

DEMO_CSS = """
/* ---------- demo-only additions (not part of panel.css) ---------- */
.demo-banner {
  background: linear-gradient(90deg, #ff6c2c, #ff9142); color: #fff;
  font-size: 12.5px; font-weight: 650; padding: 7px 18px;
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.demo-banner a { color: #fff; text-decoration: underline; font-weight: 700; }
.demo-banner .spacer { flex: 1; }
.demo-banner .tag {
  background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.4);
  border-radius: 99px; padding: 1px 10px; font-size: 11px; letter-spacing: .08em;
}
[id] { scroll-margin-top: 76px; }
.swatches { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: 10px; }
.swatch { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; box-shadow: var(--shadow); }
.swatch .chip-color { height: 52px; }
.swatch .meta { padding: 8px 10px; }
.swatch .meta b { display: block; font-size: 12px; }
.swatch .meta span { font-family: var(--mono); font-size: 10.5px; color: var(--muted); }
.igrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 10px; }
.itile {
  background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
  padding: 12px 8px; text-align: center; box-shadow: var(--shadow); color: var(--ink);
}
.itile .ic { color: var(--cp-orange-dark); }
.itile span { display: block; font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 7px; word-break: break-all; }
.login-demo-wrap { background: var(--panel-2); border: 1px dashed var(--line-2); border-radius: 12px; padding: 26px; display: grid; place-items: center; }
.login-demo-wrap .login-card { box-shadow: 0 2px 10px rgba(16,24,40,.06); }
.mock-note { font-size: 11.5px; color: var(--faint); }
"""

PALETTE = [
    ("cPanel Orange (brand)", "#FF6C2C", "brand accent — buttons, active nav, logo"),
    ("Orange light", "#FF9142", "gradients, hovers"),
    ("Canvas", "#EEF1F5", "page background"),
    ("Panel white", "#FFFFFF", "cards, sidebar, topbar"),
    ("Ink", "#1F2937", "primary text"),
    ("Muted", "#6B7280", "secondary text"),
    ("Border", "#E3E8EF", "card / section lines"),
    ("Green", "#16A34A", "success / active"),
    ("Amber", "#F59E0B", "warning"),
    ("Red", "#DC2626", "danger / failed"),
    ("Blue", "#3B82F6", "Files section, stat bars"),
]


def parse_config(text: str):
    """Parse panel_modules.php into sections with tiles (regular file format)."""
    chunks = re.split(r"\[\s*'key' =>", text)
    sections = []
    for chunk in chunks[1:]:
        m = re.match(
            r"\s*'(\w+)',\s*'name' => '((?:[^'\\]|\\.)*)',\s*'icon' => '([\w-]+)',\s*'color' => '(#[0-9a-fA-F]{6})'",
            chunk,
        )
        if not m:
            raise SystemExit(f"config parse failed at chunk: {chunk[:80]!r}")
        key, name, icon, color = m.groups()
        tiles = []
        for tm in re.finditer(
            r"\[\s*'((?:[^'\\]|\\.)*)',\s*'(S\d+B?)',\s*'icon' => '([\w-]+)'(\s*,\s*'live' => true)?\s*\]",
            chunk,
        ):
            tname, step, ticon, live = tm.groups()
            tiles.append({
                "name": tname.replace("\\'", "'"),
                "step": step,
                "icon": ticon,
                "live": bool(live),
            })
        sections.append({
            "key": key,
            "name": html.escape(name.replace("\\'", "'")),
            "icon": icon,
            "color": color,
            "tiles": tiles,
        })
    return sections


def icon(name: str, size: int = 18) -> str:
    return (
        f'<svg class="ic" width="{size}" height="{size}" viewBox="0 0 24 24" '
        f'aria-hidden="true"><use href="#i-{name}"/></svg>'
    )


def build_sidebar(sections) -> str:
    out = ['<h4>Home</h4>',
           f'<a href="#dash-demo" class="active" data-nav>{icon("home", 17)} Dashboard</a>',
           '<h4>Tools (cPanel layout)</h4>']
    for s in sections:
        out.append(
            f'<a href="#{s["key"]}" data-nav style="--sec: {s["color"]}">'
            f'{icon(s["icon"], 17)} {s["name"]} <span class="count">{len(s["tiles"])}</span></a>'
        )
    return "\n      ".join(out)


def build_tiles(sections) -> str:
    out = []
    for s in sections:
        tiles = []
        for t in s["tiles"]:
            cls = "tile live" if t["live"] else "tile"
            step = "Active now" if t["live"] else f"Roadmap {t['step']}"
            tiles.append(
                f'<a class="{cls}" href="#dash-demo" data-tool style="--sec: {s["color"]}">'
                f'<span class="tico">{icon(t["icon"], 18)}</span>'
                f'<span><span class="tname">{html.escape(t["name"])}</span>'
                f'<span class="tstep">{step}</span></span></a>'
            )
        out.append(f"""
    <div data-section>
      <div class="section" id="{s['key']}" style="--sec: {s['color']}">
        <span class="sec-ico">{icon(s["icon"], 15)}</span>
        <h2>{s['name']}</h2><span class="rule"></span>
        <span class="small muted">{len(s['tiles'])} tools</span>
      </div>
      <div class="tiles">{''.join(tiles)}</div>
    </div>""")
    return "\n".join(out)


def build_swatches() -> str:
    out = []
    for name, hexv, use in PALETTE:
        out.append(
            f'<div class="swatch"><div class="chip-color" style="background:{hexv}"></div>'
            f'<div class="meta"><b>{name}</b><span>{hexv} · {use}</span></div></div>'
        )
    return "\n      ".join(out)


def build_icon_grid(sprite: str) -> str:
    ids = re.findall(r'<symbol id="i-([\w-]+)"', sprite)
    out = []
    for i in ids:
        out.append(f'<div class="itile">{icon(i, 22)}<span>i-{i}</span></div>')
    return "\n      ".join(out), len(ids)


TEMPLATE = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AlphaCP — Paper Lantern Theme Demo (cPanel-style)</title>
<style>
/*__PANEL_CSS__*/
/*__DEMO_CSS__*/
</style>
</head>
<body>
<!--__SPRITE__-->

<div class="demo-banner">
  <span class="tag">DEMO</span>
  <span><b>AlphaCP · Paper Lantern theme</b> — static preview of the live panel design (cPanel-style: #FF6C2C accent, icon grid, right-rail info panels)</span>
  <span class="spacer"></span>
  <a href="#palette">Palette</a>
  <a href="#iconset">Icons</a>
  <a href="#logindemo">Login</a>
  <a href="#dash-demo">Dashboard</a>
</div>

<header class="topbar">
  <div class="brand">
    <div class="mark">A</div>
    <div class="bname">AlphaCP<small>dev-srv1 · demo</small></div>
  </div>
  <div class="tsearch">{icon_search}<input id="toolSearch" type="search" placeholder="Search tools…" autocomplete="off"><kbd>/</kbd></div>
  <div class="spacer"></div>
  <span class="chip hide-sm"><span class="dot"></span> agent <b>idle</b></span>
  <span class="chip hide-sm">queued <b>3</b></span>
  <div class="userbox">
    <span class="avatar">A</span>
    <span><span class="uname">admin</span><br><span class="urole">administrator</span></span>
    <button class="btn" type="button">{icon_logout}<span class="hide-sm">Logout</span></button>
  </div>
</header>

<div class="shell">
  <nav class="sidebar" aria-label="Demo navigation">
      __SIDEBAR__
  </nav>

  <main class="content">
    <div class="page-head">
      <h1>Theme demo</h1>
      <span class="sub">generated from <code>panel/public/css/panel.css</code> + <code>panel/config/panel_modules.php</code> · __N_SECTIONS__ sections · __N_TILES__ tools · __N_ICONS__ icons</span>
    </div>

    <div class="section" id="palette" style="--sec: #ff6c2c">
      <span class="sec-ico">{icon_star}</span>
      <h2>Theme palette</h2><span class="rule"></span>
      <span class="small muted">cPanel company-grade colour system</span>
    </div>
    <div class="swatches">
      __SWATCHES__
    </div>

    <div class="section" id="iconset" style="--sec: #3b82f6">
      <span class="sec-ico">{icon_folder}</span>
      <h2>Icon set</h2><span class="rule"></span>
      <span class="small muted">inline SVG stroke icons · no emoji, no icon font</span>
    </div>
    <div class="igrid">
      __IGRID__
    </div>

    <div class="section" id="logindemo" style="--sec: #6366f1">
      <span class="sec-ico">{icon_lock}</span>
      <h2>Login page</h2><span class="rule"></span>
      <span class="small muted">light card · orange accent · audited logins</span>
    </div>
    <div class="login-demo-wrap">
      <div class="login-card" style="max-width:380px">
        <div class="mark-lg">A</div>
        <h1>AlphaCP</h1>
        <div class="tag">Server: <b>dev-srv1</b> · panel v0.3.2 · Paper Lantern theme</div>
        <form onsubmit="return false">
          <label for="d-username">Username</label>
          <input id="d-username" type="text" value="admin" readonly>
          <label for="d-password">Password</label>
          <input id="d-password" type="password" value="••••••••" readonly>
          <button class="btn primary" type="submit">{icon_lock_s} Login</button>
        </form>
        <div class="login-foot">{icon_shield} Secure area — all logins are audited.</div>
      </div>
    </div>

    <div class="section" id="dash-demo" style="--sec: #14b8a6">
      <span class="sec-ico">{icon_gauge}</span>
      <h2>Dashboard</h2><span class="rule"></span>
      <span class="small muted">Paper Lantern layout · live search · right rail</span>
    </div>

    <div class="dash-grid">
      <div class="dash-main">
        <div class="section" style="margin-top:2px">
          {icon_gauge20}<h2>Statistics</h2><span class="rule"></span>
          <span class="small muted">live from paneld · demo values</span>
        </div>
        <div class="grid cols-4">
          <div class="card">
            <h3><span class="stat-ico">{icon_memory}</span> Memory</h3>
            <div class="big">62%</div>
            <div class="sub">2458 MB / 3948 MB used</div>
            <div class="bar blue warn" style="margin-top:10px"><span style="width:62%"></span></div>
            <div class="sub" style="margin-top:8px">swap 120 / 2048 MB</div>
          </div>
          <div class="card">
            <h3><span class="stat-ico blue">{icon_hdd}</span> Disk (/)</h3>
            <div class="big">41%</div>
            <div class="sub">32 GB / 78 GB · 46 GB free</div>
            <div class="bar blue" style="margin-top:10px"><span style="width:41%"></span></div>
          </div>
          <div class="card">
            <h3><span class="stat-ico teal">{icon_cpu}</span> CPU load</h3>
            <div class="big">0.42</div>
            <div class="sub">2 cores · 5m 0.51 · 15m 0.38</div>
            <div class="sub" style="margin-top:10px">dev-srv1 · x86_64 · PHP 8.3</div>
          </div>
          <div class="card">
            <h3><span class="stat-ico green">{icon_list}</span> Task queue</h3>
            <div class="big">3</div>
            <div class="sub">queued · 0 running · 128 done · 0 failed</div>
            <div class="sub" style="margin-top:10px">last: <code>#412 system.info</code> → <span class="pill on">success</span> (18ms)</div>
          </div>
        </div>

        <div class="section">
          {icon_box20}<h2>Tools</h2><span class="rule"></span>
          <span class="small muted">search with the box above · every tile carries its roadmap step</span>
        </div>
        __TILES__
      </div>

      <aside class="dash-side">
        <div class="card">
          <h3>{icon_info} General information</h3>
          <div class="kv"><span class="k">Current user</span><span class="v">admin</span></div>
          <div class="kv"><span class="k">Server</span><span class="v">dev-srv1</span></div>
          <div class="kv"><span class="k">Home directory</span><span class="v">/home/admin</span></div>
          <div class="kv"><span class="k">Last login IP</span><span class="v">13.207.123.177</span></div>
          <div class="kv"><span class="k">Panel version</span><span class="v">0.3.2</span></div>
          <div class="kv"><span class="k">Theme</span><span class="v"><span class="dot" style="display:inline-block;vertical-align:0;margin-right:5px;background:var(--cp-orange);box-shadow:0 0 0 3px rgba(255,108,44,.18)"></span>Paper Lantern</span></div>
          <div class="kv"><span class="k">Agent</span><span class="v">paneld · <b style="color:var(--green)">idle</b></span></div>
        </div>
        <div class="card">
          <h3>{icon_server} Services</h3>
          <p class="sub" style="margin:0 0 8px"><span class="dot"></span> <b style="color:var(--ink)">4</b> active</p>
          <table class="svc">
            <thead><tr><th>Service</th><th>State</th><th>Boot</th></tr></thead>
            <tbody>
              <tr><td><code>nginx</code></td><td><span class="pill on">active</span></td><td class="muted small">enabled</td></tr>
              <tr><td><code>php-fpm</code></td><td><span class="pill on">active</span></td><td class="muted small">enabled</td></tr>
              <tr><td><code>mysql</code></td><td><span class="pill on">active</span></td><td class="muted small">enabled</td></tr>
              <tr><td><code>exim</code></td><td><span class="pill on">active</span></td><td class="muted small">enabled</td></tr>
              <tr><td><code>dovecot</code></td><td><span class="pill err">failed</span></td><td class="muted small">enabled</td></tr>
              <tr><td><code>paneld</code></td><td><span class="pill on">active</span></td><td class="muted small">enabled</td></tr>
            </tbody>
          </table>
        </div>
        <div class="card">
          <h3>{icon_clock} Recent activity</h3>
          <div class="activity">
            <div class="row"><code>auth.login</code><span class="pill ">info</span><span class="when">2026-10-09 06:31</span></div>
            <div class="row"><code>agent.task</code><span class="pill ">info</span><span class="when">2026-10-09 06:30</span></div>
            <div class="row"><code>panel.update</code><span class="pill err">warning</span><span class="when">2026-09-29 00:17</span></div>
          </div>
          <p class="sub muted small" style="margin:10px 0 0">Immutable audit trail · <code>docs/03-security-matrix.md</code></p>
        </div>
      </aside>
    </div>

    <p class="mock-note" style="margin-top:26px">Demo generated by <code>tools/sim/build-theme-demo.py</code> — open this file directly in a browser, or serve it: <code>python3 -m http.server 8000</code> → <code>http://localhost:8000/demo/cpanel-theme-demo.html</code></p>
  </main>
</div>

<script>
/* same live-search behaviour as the real panel layout */
(function () {
  var input = document.getElementById('toolSearch');
  if (!input) return;
  var tiles = Array.prototype.slice.call(document.querySelectorAll('[data-tool]'));
  var navs = Array.prototype.slice.call(document.querySelectorAll('[data-nav]'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-section]'));
  var heads = Array.prototype.slice.call(document.querySelectorAll('.sidebar h4'));
  function norm(s) { return (s || '').toLowerCase(); }
  input.addEventListener('input', function () {
    var q = norm(input.value).trim();
    tiles.forEach(function (t) {
      t.style.display = (!q || norm(t.textContent).indexOf(q) !== -1) ? '' : 'none';
    });
    navs.forEach(function (n) {
      n.style.display = (!q || norm(n.textContent).indexOf(q) !== -1) ? '' : 'none';
    });
    sections.forEach(function (s) {
      var anyVisible = Array.prototype.some.call(
        s.querySelectorAll('[data-tool]'),
        function (t) { return t.style.display !== 'none'; }
      );
      s.style.display = anyVisible ? '' : 'none';
    });
    heads.forEach(function (h) { h.style.display = q ? 'none' : ''; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== input && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
      e.preventDefault();
      input.focus();
    }
  });
})();
</script>
</body>
</html>
"""


def main() -> int:
    css = CSS_PATH.read_text(encoding="utf-8")
    sprite_m = re.search(r"<svg xmlns.*?</svg>", SPRITE_PATH.read_text(encoding="utf-8"), re.S)
    if not sprite_m:
        raise SystemExit("could not extract SVG sprite from partials/icons.blade.php")
    sprite = sprite_m.group(0)
    sections = parse_config(CONFIG_PATH.read_text(encoding="utf-8"))
    if not sections:
        raise SystemExit("no sections parsed from panel_modules.php")

    igrid, n_icons = build_icon_grid(sprite)
    n_tiles = sum(len(s["tiles"]) for s in sections)

    page = (TEMPLATE
            .replace("/*__PANEL_CSS__*/", css)
            .replace("/*__DEMO_CSS__*/", DEMO_CSS)
            .replace("<!--__SPRITE__-->", sprite)
            .replace("{icon_search}", icon("search", 15))
            .replace("{icon_logout}", icon("logout", 15))
            .replace("{icon_star}", icon("star", 15))
            .replace("{icon_folder}", icon("folder", 15))
            .replace("{icon_lock}", icon("lock", 15))
            .replace("{icon_lock_s}", icon("lock", 15))
            .replace("{icon_shield}", icon("shield-check", 14))
            .replace("{icon_gauge}", icon("gauge", 15))
            .replace("{icon_gauge20}", icon("gauge", 20))
            .replace("{icon_box20}", icon("box", 20))
            .replace("{icon_memory}", icon("memory", 15))
            .replace("{icon_hdd}", icon("hdd", 15))
            .replace("{icon_cpu}", icon("cpu", 15))
            .replace("{icon_list}", icon("list", 15))
            .replace("{icon_info}", icon("info", 14))
            .replace("{icon_server}", icon("server", 14))
            .replace("{icon_clock}", icon("clock", 14))
            .replace("__SIDEBAR__", build_sidebar(sections))
            .replace("__SWATCHES__", build_swatches())
            .replace("__IGRID__", igrid)
            .replace("__TILES__", build_tiles(sections))
            .replace("__N_SECTIONS__", str(len(sections)))
            .replace("__N_TILES__", str(n_tiles))
            .replace("__N_ICONS__", str(n_icons)))

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(page, encoding="utf-8")
    print(f"demo written: {OUT_PATH.relative_to(ROOT)} "
          f"({len(page) / 1024:.0f} KB · {len(sections)} sections · {n_tiles} tiles · {n_icons} icons)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
