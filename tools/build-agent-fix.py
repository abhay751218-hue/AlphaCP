#!/usr/bin/env python3
"""
installer/agent-fix.sh.in + agent/src/MysqlServer.php  ->  installer/agent-fix.sh

build-login-fix.py jaisa hi: template me `@@TOKEN@@` hota hai, build step use
payload file ke byte-for-byte content se badal deta hai → ek self-contained
"ek command" script banti hai (server par sirf wahi download hoti hai) aur
MysqlServer.php ka readable source repo me rehta hai.

Usage:  python3 tools/build-agent-fix.py [--check]
        --check = dobara build karke compare karo (CI/sim), likhta nahi
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "agent-fix.sh.in"
OUTPUT = REPO / "installer" / "agent-fix.sh"
PAYLOAD = REPO / "agent" / "src" / "MysqlServer.php"

TOKEN = "@@MYSQL_SERVER_PHP@@"


def build() -> str:
    text = TEMPLATE.read_text(encoding="utf-8")
    if TOKEN not in text:
        raise SystemExit(f"[x] template me {TOKEN} nahi mila")
    body = PAYLOAD.read_text(encoding="utf-8").rstrip("\n")
    # heredoc delimiter content me aana nahi chahiye
    for bad in ("PHPEOF", "SMOKE"):
        if bad in body:
            raise SystemExit(f"[x] {PAYLOAD.name} me '{bad}' hai — heredoc toot jayega")
    text = text.replace(TOKEN, body)
    if TOKEN in text:
        raise SystemExit("[x] token substitute nahi hua")
    return text


def main() -> int:
    text = build()
    if "--check" in sys.argv:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if current != text:
            print("[x] installer/agent-fix.sh PURANA hai — python3 tools/build-agent-fix.py chalao")
            return 1
        print("[OK] installer/agent-fix.sh payload ke saath sync me hai")
        return 0

    OUTPUT.write_text(text, encoding="utf-8")
    OUTPUT.chmod(0o755)
    digest = hashlib.sha256(OUTPUT.read_bytes()).hexdigest()
    print(f"[OK] built {OUTPUT.relative_to(REPO)}  ({len(text)} bytes)")
    print(f"     sha256: {digest}")

    body = PAYLOAD.read_text(encoding="utf-8").rstrip("\n")
    if body not in text:
        print(f"[x] {PAYLOAD.name} script me byte-for-byte nahi hai")
        return 1
    print(f"     embedded OK: {PAYLOAD.relative_to(REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
