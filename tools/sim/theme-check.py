#!/usr/bin/env python3
"""theme-check — static "no error" gate for the AlphaCP Paper Lantern theme.

Checks (all must pass):
  1. panel.css braces balanced, no template leftovers
  2. SVG sprite (partials/icons.blade.php) is valid XML; collects icon ids
  3. every icon referenced by config/panel_modules.php + all blade views exists in the sprite
  4. blade directive balance (@if/@endif, @foreach/@endforeach, @section/@endsection, …)
  5. demo HTML parses; every <use href="#i-…"> resolves; sidebar anchors have targets
  6. config colors are valid hex; tile steps match S<n>[B]

Usage:  python3 tools/sim/theme-check.py     (exit 0 = pass)
"""
from __future__ import annotations

import re
import sys
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PANEL = ROOT / "panel"

failures: list[str] = []
checks = 0


def ok(cond: bool, label: str, detail: str = "") -> bool:
    global checks
    checks += 1
    if cond:
        print(f"  ✅ {label}")
    else:
        print(f"  ❌ {label}" + (f" — {detail}" if detail else ""))
        failures.append(label + (f" ({detail})" if detail else ""))
    return cond


# ---------------------------------------------------------------- 1. CSS
print("== panel.css ==")
css = (PANEL / "public/css/panel.css").read_text(encoding="utf-8")
ok(css.count("{") == css.count("}"), "braces balanced", f"{css.count('{')} vs {css.count('}')}")
ok("{{" not in css and "}}" not in css, "no blade/template leftovers")
ok("--cp-orange:      #ff6c2c" in css or "--cp-orange: #ff6c2c" in css.replace("  ", " "),
   "cPanel orange #ff6c2c token present")

# ---------------------------------------------------------------- 2. sprite
print("== icon sprite ==")
sprite_src = (PANEL / "resources/views/partials/icons.blade.php").read_text(encoding="utf-8")
m = re.search(r"<svg xmlns.*?</svg>", sprite_src, re.S)
if ok(m is not None, "sprite block found"):
    sprite_xml = m.group(0)
    try:
        ET.fromstring(sprite_xml)
        ok(True, "sprite is valid XML")
    except ET.ParseError as e:
        ok(False, "sprite is valid XML", str(e))
icon_ids = set(re.findall(r'<symbol id="i-([\w-]+)"', sprite_xml)) if m else set()
ok(len(icon_ids) >= 40, f"icon count >= 40 (have {len(icon_ids)})")

# ---------------------------------------------------------------- 3. icon refs
print("== icon references ==")
config_src = (PANEL / "config/panel_modules.php").read_text(encoding="utf-8")
config_icons = set(re.findall(r"'icon' => '([\w-]+)'", config_src))
blade_icons: set[str] = set()
blade_files = sorted((PANEL / "resources/views").rglob("*.blade.php"))
for bf in blade_files:
    blade_icons |= set(re.findall(r"partials\.icon', \['name' => '([\w-]+)'", bf.read_text(encoding="utf-8")))
referenced = config_icons | blade_icons
missing = sorted(referenced - icon_ids)
ok(not missing, f"all {len(referenced)} referenced icons exist in sprite", f"missing: {missing}")

# ---------------------------------------------------------------- 4. blade balance
print("== blade directive balance ==")
PAIRS = [
    ("if", "endif"), ("foreach", "endforeach"), ("forelse", "endforelse"),
    ("switch", "endswitch"), ("php", "endphp"), ("error", "enderror"),
    ("auth", "endauth"), ("isset", "endisset"), ("unless", "endunless"),
]
for bf in blade_files:
    src = bf.read_text(encoding="utf-8")
    rel = bf.relative_to(PANEL)
    good = True
    detail = []
    for opener, closer in PAIRS:
        o = len(re.findall(rf"@{opener}\b", src))
        c = len(re.findall(rf"@{closer}\b", src))
        if o != c:
            good = False
            detail.append(f"@{opener}={o} @{closer}={c}")
    # @section: inline form @section('x', 'y') has no @endsection
    block_sec = len(re.findall(r"@section\(\s*'[^']+'\s*\)", src))
    end_sec = len(re.findall(r"@endsection\b", src))
    if block_sec != end_sec:
        good = False
        detail.append(f"@section(block)={block_sec} @endsection={end_sec}")
    ok(good, f"{rel}", "; ".join(detail))

# ---------------------------------------------------------------- 5. demo html
print("== demo html ==")
demo_path = ROOT / "demo/cpanel-theme-demo.html"
if ok(demo_path.exists(), "demo file exists"):
    demo = demo_path.read_text(encoding="utf-8")

    class P(HTMLParser):
        def __init__(self):
            super().__init__(convert_charrefs=True)
            self.stack: list[str] = []
            self.errors: list[str] = []
            self.ids: set[str] = set()
            self.uses: list[str] = []
            self.hrefs: list[str] = []
            self.void = {"area", "base", "br", "col", "embed", "hr", "img", "input",
                         "link", "meta", "param", "source", "track", "wbr", "use"}

        def handle_starttag(self, tag, attrs):
            d = dict(attrs)
            if "id" in d:
                self.ids.add(d["id"])
            if tag == "use" and d.get("href", "").startswith("#i-"):
                self.uses.append(d["href"][1:])
            if tag == "a" and d.get("href", "").startswith("#"):
                self.hrefs.append(d["href"][1:])
            if tag not in self.void:
                self.stack.append(tag)

        def handle_startendtag(self, tag, attrs):
            d = dict(attrs)
            if tag == "use" and d.get("href", "").startswith("#i-"):
                self.uses.append(d["href"][1:])

        def handle_endtag(self, tag):
            if tag in self.void:
                return
            if self.stack and self.stack[-1] == tag:
                self.stack.pop()
            elif tag in self.stack:
                self.errors.append(f"misnested </{tag}> (stack top: {self.stack[-1] if self.stack else '∅'})")
                while self.stack and self.stack[-1] != tag:
                    self.stack.pop()
                if self.stack:
                    self.stack.pop()
            else:
                self.errors.append(f"stray </{tag}>")

    p = P()
    p.feed(demo)
    ok(not p.errors and not p.stack, "html tags balanced",
       f"{p.errors[:3]} open={p.stack[:5]}")
    bad_uses = sorted({u for u in p.uses if u[2:] not in icon_ids})
    ok(not bad_uses, f"all {len(set(p.uses))} <use> icons resolve", f"bad: {bad_uses[:5]}")
    bad_anchors = sorted({h for h in p.hrefs if h and h not in p.ids and not h.startswith('i-')})
    ok(not bad_anchors, "sidebar/breadcrumb anchors resolve", f"bad: {bad_anchors[:5]}")

# ---------------------------------------------------------------- 6. config sanity
print("== config sanity ==")
bad_colors = [c for c in re.findall(r"'color' => '(#[0-9a-fA-F]+)'", config_src)
              if not re.fullmatch(r"#[0-9a-fA-F]{6}", c)]
ok(not bad_colors, "section colors valid hex", str(bad_colors))
bad_steps = [s for s in re.findall(r"'(S\d+B?)'", config_src) if not re.fullmatch(r"S\d+B?", s)]
ok(not bad_steps, "tile steps match S<n>[B]", str(bad_steps))
n_sections = len(re.findall(r"'key' => '", config_src))
n_tiles = len(re.findall(r"'icon' => '", config_src)) - n_sections  # tiles have icon, sections have icon too
ok(n_sections == 9, f"9 sections (have {n_sections})")
ok(n_tiles >= 70, f"tile count >= 70 (have {n_tiles})")

# ---------------------------------------------------------------- summary
print()
if failures:
    print(f"❌ theme-check FAILED: {len(failures)} of {checks} checks failed")
    for f in failures:
        print(f"   - {f}")
    sys.exit(1)
print(f"✅ theme-check PASSED: {checks}/{checks} checks green")
