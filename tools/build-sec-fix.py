#!/usr/bin/env python3
"""
installer/sec-fix.sh.in + 11 payload files  ->  installer/sec-fix.sh

build-ftp-fix.py ka 11-file version (B1-ext: Security suite). Server par sirf
EK self-contained script download hoti hai; readable source repo me rehta hai.

Usage:  python3 tools/build-sec-fix.py [--check]
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "sec-fix.sh.in"
OUTPUT = REPO / "installer" / "sec-fix.sh"
A = REPO / "agent"
P = REPO / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"

PAYLOADS: dict[str, pathlib.Path] = {
    "@@AGENT_SECTASK_PHP@@": A / "src" / "Tasks" / "SecurityTask.php",
    "@@AGENT_IPBLOCK_PHP@@": A / "src" / "Tasks" / "IpBlock.php",
    "@@AGENT_IPUNBLOCK_PHP@@": A / "src" / "Tasks" / "IpUnblock.php",
    "@@AGENT_WAFSTATUS_PHP@@": A / "src" / "Tasks" / "WafStatus.php",
    "@@AGENT_WAFENABLE_PHP@@": A / "src" / "Tasks" / "WafEnable.php",
    "@@AGENT_WAFDISABLE_PHP@@": A / "src" / "Tasks" / "WafDisable.php",
    "@@AGENT_VIRUSSCAN_PHP@@": A / "src" / "Tasks" / "VirusScan.php",
    "@@AGENT_COMMANDRUNNER_PHP@@": A / "src" / "CommandRunner.php",
    "@@AGENT_TASKS_PHP@@": A / "config" / "tasks.php",
    "@@PANEL_FIREWALL_PHP@@": P / "app" / "Support" / "Firewall.php",
    "@@PANEL_WAF_PHP@@": P / "app" / "Support" / "Waf.php",
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
            print("[x] installer/sec-fix.sh PURANA hai — python3 tools/build-sec-fix.py chalao")
            return 1
        print("[OK] installer/sec-fix.sh payloads ke saath sync me hai")
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
