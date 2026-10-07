#!/usr/bin/env python3
"""Current server-snapshot ModuleCatalog ko patch karta hai: installed features -> live + route.
Output: features/dashboard/ModuleCatalog.php (installer isse server par replace karta hai)."""
import pathlib
import re

repo = pathlib.Path(__file__).resolve().parents[2]
src = repo / "server-snapshot/files/usr/local/alphacp/panel/app/Support/ModuleCatalog.php"
text = src.read_text()

FLIP = {
    "FTP Accounts": "ftp.index",
    "Git Version Control": "git.index",
    "Terminal": "terminal.index",
    "Visitors": "metrics.index",
    "Bandwidth": "metrics.index",
    "Resource Usage": "monitoring.index",
    "IP Blocker": "ip-blocker.index",
    "ModSecurity": "security-tools.index",
    "Hotlink Protection": "secextra.hotlink",
    "App Installer": "apps.index",
    "WordPress Toolkit": "apps.index",
    "API Tokens": "api-tokens.index",
    "License": "license.index",
    "Images": "images.index",
    "Trash": "trash.index",
    "Optimize Website": "optimize.index",
}

changed = 0
for name, route in FLIP.items():
    pattern = re.compile(r"('name' => '" + re.escape(name) + r"',[^\n]*?'status' => ')step(')")
    text, n = pattern.subn(r"\1live', 'route' => '" + route + r"\2", text)
    changed += n

# Naye tiles insert (marker ke baad, agar maujood nahi)
INSERTS = [
    ("'FTP Accounts'", "['name' => 'Web Disk',          'step' => 'S6',  'status' => 'live', 'route' => 'webdisk.index'],"),
]
for marker, item in INSERTS:
    name = item.split("'")[3]
    if f"'{name}'" in text:
        continue
    lines = text.split("\n")
    for i, line in enumerate(lines):
        if marker in line:
            lines.insert(i + 1, "                    " + item)
            break
    text = "\n".join(lines)
    changed += 1

# NOTE: SSL/TLS Status + AutoSSL base (0.83.0) me PEHLE SE live hai — alag se add NAHI karna.

out = repo / "features/dashboard/ModuleCatalog.php"
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(text)
print(f"FLIPPED {changed} tiles -> live; wrote {out}")
