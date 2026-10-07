#!/usr/bin/env python3
"""
installer/ftp-fix.sh.in + 9 payload files  ->  installer/ftp-fix.sh

build-agent-fix.py ka multi-file version: template me `@@TOKEN@@` hote hain aur
build step unhe payload files ke BYTE-FOR-BYTE content se badal deta hai. Isse
server par sirf EK self-contained script download hoti hai, jabki readable
source (agent handlers + panel controller) repo me hi rehta hai.

Payloads:
  AGENT : src/Ftp.php, src/Tasks/{FtpTask,FtpAdd,FtpPasswd,FtpDel}.php,
          src/CommandRunner.php, config/tasks.php
  PANEL : app/Support/Ftp.php, app/Http/Controllers/FtpController.php
          (server-snapshot/.../panel/ = panel ka source of truth)

Usage:  python3 tools/build-ftp-fix.py [--check]
        --check = dobara build karke compare karo (CI/sim); file likhta nahi
"""
from __future__ import annotations

import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "ftp-fix.sh.in"
OUTPUT = REPO / "installer" / "ftp-fix.sh"
PANEL = REPO / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"

PAYLOADS: dict[str, pathlib.Path] = {
    "@@AGENT_FTP_PHP@@": REPO / "agent" / "src" / "Ftp.php",
    "@@AGENT_FTPTASK_PHP@@": REPO / "agent" / "src" / "Tasks" / "FtpTask.php",
    "@@AGENT_FTPADD_PHP@@": REPO / "agent" / "src" / "Tasks" / "FtpAdd.php",
    "@@AGENT_FTPPASSWD_PHP@@": REPO / "agent" / "src" / "Tasks" / "FtpPasswd.php",
    "@@AGENT_FTPDEL_PHP@@": REPO / "agent" / "src" / "Tasks" / "FtpDel.php",
    "@@AGENT_COMMANDRUNNER_PHP@@": REPO / "agent" / "src" / "CommandRunner.php",
    "@@AGENT_TASKS_PHP@@": REPO / "agent" / "config" / "tasks.php",
    "@@PANEL_FTP_PHP@@": PANEL / "app" / "Support" / "Ftp.php",
    "@@PANEL_FTPCONTROLLER_PHP@@": PANEL / "app" / "Http" / "Controllers" / "FtpController.php",
}

# heredoc delimiters jo template use karta hai — payload me LINE ke roop me nahi aane chahiye
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
    if "@@" in text and "@@TOKEN" in text:
        raise SystemExit("[x] koi token bacha hua hai")
    return text


def main() -> int:
    text = build()

    if "--check" in sys.argv:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if current != text:
            print("[x] installer/ftp-fix.sh PURANA hai — python3 tools/build-ftp-fix.py chalao")
            return 1
        print("[OK] installer/ftp-fix.sh payloads ke saath sync me hai")
        return 0

    OUTPUT.write_text(text, encoding="utf-8")
    OUTPUT.chmod(0o755)
    digest = hashlib.sha256(OUTPUT.read_bytes()).hexdigest()
    print(f"[OK] built {OUTPUT.relative_to(REPO)}  ({len(text)} bytes)")
    print(f"     sha256: {digest}")

    missing = [p.relative_to(REPO).as_posix()
               for p in PAYLOADS.values()
               if p.read_text(encoding="utf-8").rstrip("\n") not in text]
    if missing:
        print(f"[x] byte-for-byte embed FAIL: {', '.join(missing)}")
        return 1
    print(f"     embedded OK: {len(PAYLOADS)} payload files (byte-for-byte)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
