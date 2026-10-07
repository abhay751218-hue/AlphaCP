#!/usr/bin/env python3
"""
installer/login-fix.sh.in + installer/payload/*  ->  installer/login-fix.sh

Repo convention (panel-install.sh.in / step2-install.sh.in jaisa): template me
`@@TOKEN@@` hote hain aur build step unhe payload files ke byte-for-byte content
se badalta hai. Isse ek hi self-contained "ek command" script banti hai (server
par sirf wahi download hoti hai) aur payload ka readable source repo me rehta hai.

Build ke baad script khud verify karti hai ki embedded copy == payload copy.

Usage:  python3 tools/build-login-fix.py [--check]
        --check = dobara build karke compare karo (CI/sim ke liye), likhta nahi
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "login-fix.sh.in"
OUTPUT = REPO / "installer" / "login-fix.sh"
PAYLOAD = REPO / "installer" / "payload"

TOKENS = {
    "@@ENTRY_LOGIN_CONTROLLER@@": PAYLOAD / "EntryLoginController.php",
    "@@TFA_MW@@": PAYLOAD / "EnsureTwoFactorIsVerified.php",
    "@@ACP_ENTRY_PORTS@@": PAYLOAD / "acp-entry-ports",
    "@@ENTRY_LOGIN_TEST@@": PAYLOAD / "tests" / "EntryLoginTest.php",
    "@@SESSION_AUTH_TEST@@": PAYLOAD / "tests" / "SessionAuthTest.php",
    "@@RESELLER_SCOPE_PROVIDER@@": PAYLOAD / "ResellerScopeProvider.php",
}


def build() -> str:
    text = TEMPLATE.read_text(encoding="utf-8")
    for token, path in TOKENS.items():
        if token not in text:
            raise SystemExit(f"[x] template me {token} nahi mila")
        body = path.read_text(encoding="utf-8").rstrip("\n")
        # heredoc delimiter kabhi content me aana nahi chahiye
        for bad in ("PHPEOF", "SHEOF"):
            if bad in body:
                raise SystemExit(f"[x] {path.name} me '{bad}' hai — heredoc toot jayega")
        text = text.replace(token, body)
    leftovers = [t for t in TOKENS if t in text]
    if leftovers:
        raise SystemExit(f"[x] substitute nahi hue: {leftovers}")
    return text


def main() -> int:
    text = build()
    if "--check" in sys.argv:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if current != text:
            print("[x] installer/login-fix.sh PURANA hai — python3 tools/build-login-fix.py chalao")
            return 1
        print("[OK] installer/login-fix.sh payload ke saath sync me hai")
        return 0

    OUTPUT.write_text(text, encoding="utf-8")
    OUTPUT.chmod(0o755)
    digest = hashlib.sha256(OUTPUT.read_bytes()).hexdigest()
    print(f"[OK] built {OUTPUT.relative_to(REPO)}  ({len(text)} bytes)")
    print(f"     sha256: {digest}")

    # embedded == payload (drift guard)
    for token, path in TOKENS.items():
        body = path.read_text(encoding="utf-8").rstrip("\n")
        if body not in text:
            print(f"[x] {path.name} script me byte-for-byte nahi hai")
            return 1
        print(f"     embedded OK: {path.relative_to(REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
