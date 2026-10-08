#!/usr/bin/env python3
"""
installer/tests-sync.sh.in + 2 payload files  ->  installer/tests-sync.sh

build-ftp-fix.py ka 2-file version (tests-sync). Server par sirf
EK self-contained script download hoti hai; readable source repo me rehta hai.

Usage:  python3 tools/build-tests-sync.py [--check]
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "tests-sync.sh.in"
OUTPUT = REPO / "installer" / "tests-sync.sh"
A = REPO / "agent"
P = REPO / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"

PAYLOADS: dict[str, pathlib.Path] = {
    "@@TESTS_RUN_PHP@@": A / "tests" / "run-tests.php",
    "@@TESTS_FAKE_PHP@@": A / "tests" / "FakeCommandExecutor.php",
}
DELIMITERS = ("PHPEOF", "SMOKE")


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
            print("[x] installer/tests-sync.sh PURANA hai — python3 tools/build-tests-sync.py chalao")
            return 1
        print("[OK] installer/tests-sync.sh payloads ke saath sync me hai")
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
