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
}

changed = 0
for name, route in FLIP.items():
    pattern = re.compile(r"('name' => '" + re.escape(name) + r"',[^\n]*?'status' => ')step(')")
    text, n = pattern.subn(r"\1live', 'route' => '" + route + r"\2", text)
    changed += n

out = repo / "features/dashboard/ModuleCatalog.php"
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(text)
print(f"FLIPPED {changed} tiles -> live; wrote {out}")
