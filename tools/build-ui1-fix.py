#!/usr/bin/env python3
"""
installer/ui1-fix.sh.in + 4 payload files  ->  installer/ui1-fix.sh

build-ftp-fix.py ka 4-file version (P-UI-1: theme+dashboard). Server par sirf
EK self-contained script download hoti hai; readable source repo me rehta hai.

Usage:  python3 tools/build-ui1-fix.py [--check]
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "ui1-fix.sh.in"
OUTPUT = REPO / "installer" / "ui1-fix.sh"
A = REPO / "agent"
P = REPO / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"

PAYLOADS: dict[str, pathlib.Path] = {
    "@@PANEL_CSS@@": P / "public" / "assets" / "panel.css",
    "@@LAYOUT_BLADE@@": P / "resources" / "views" / "layouts" / "panel.blade.php",
    "@@DASHBOARD_BLADE@@": P / "resources" / "views" / "dashboard.blade.php",
    "@@DASHSECTIONS_BLADE@@": P / "resources" / "views" / "partials" / "dash-sections.blade.php",
}
DELIMITERS = ("PHPEOF", "CSSEOF", "SMOKE")


def build() -> str:
    text = TEMPLATE.read_text(encoding="utf-8")
    for token, path in PAYLOADS.items():
        if token not in text:
            raise SystemExit(f"[x] template me {token} nahi mila")
        if not path.is_file():
            raise SystemExit(f"[x] payload file nahi mili: {path.relative_to(REPO)}")
        body = path.read_text(encoding="utf-8").rstrip("\n")
        for bad in DELIMITERS:
            if any(line.strip() == bad for line in body.splitlines()):
                raise SystemExit(f"[x] {path.name} me '{bad}' line hai — heredoc toot jayega")
        text = text.replace(token, body)
        if token in text:
            raise SystemExit(f"[x] {token} substitute nahi hua")
    return text


def main() -> int:
    text = build()
    if "--check" in sys.argv:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if current != text:
            print("[x] installer/ui1-fix.sh PURANA hai — python3 tools/build-ui1-fix.py chalao")
            return 1
        print("[OK] installer/ui1-fix.sh payloads ke saath sync me hai")
        return 0
    OUTPUT.write_text(text, encoding="utf-8")
    OUTPUT.chmod(0o755)
    digest = hashlib.sha256(OUTPUT.read_bytes()).hexdigest()
    print(f"[OK] built {OUTPUT.relative_to(REPO)}  ({len(OUTPUT.read_bytes())} bytes)")
    print(f"     sha256: {digest}")
    missing = [p.relative_to(REPO).as_posix() for p in PAYLOADS.values()
               if p.read_text(encoding="utf-8").rstrip("\n") not in text]
    if missing:
        print(f"[x] byte-for-byte embed FAIL: {', '.join(missing)}")
        return 1
    print(f"     embedded OK: {len(PAYLOADS)} payload files (byte-for-byte)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
