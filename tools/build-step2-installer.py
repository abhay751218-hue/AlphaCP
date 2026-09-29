#!/usr/bin/env python3
"""
Build installer/step2-install.sh from installer/step2-install.sh.in

Packs the real repo sources (agent/, cli/, db/migrations/) into a base64
tarball and injects it into the template, so the shipped script is a true
one-file install — but the repo stays the single source of truth.

Run after ANY change to agent/, cli/ or db/:
    python3 tools/build-step2-installer.py

Output: installer/step2-install.sh  (self-contained, checksum-protected)
"""

from __future__ import annotations

import base64
import hashlib
import io
import pathlib
import subprocess
import sys
import tarfile
import textwrap

ROOT = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = ROOT / "installer" / "step2-install.sh.in"
OUTPUT = ROOT / "installer" / "step2-install.sh"

# What ships to the server (keep this list tight — every file is audited)
PAYLOAD_PATHS = [
    "agent/bin",
    "agent/config",
    "agent/src",
    "agent/systemd",
    "agent/tests",
    "cli",
    "db/migrations",
]

# Things that must NEVER end up in the payload
FORBIDDEN_SUFFIXES = {".md", ".log", ".env", ".bak", ".old"}


def collect_files() -> list[pathlib.Path]:
    files: list[pathlib.Path] = []
    for rel in PAYLOAD_PATHS:
        path = ROOT / rel
        if not path.exists():
            print(f"!! missing payload path: {rel}", file=sys.stderr)
            sys.exit(1)
        if path.is_file():
            files.append(path)
            continue
        for f in sorted(path.rglob("*")):
            if f.is_file() and "__pycache__" not in f.parts:
                files.append(f)
    bad = [f for f in files if f.suffix in FORBIDDEN_SUFFIXES]
    if bad:
        print("!! refusing to ship these files:", *bad, sep="\n   ", file=sys.stderr)
        sys.exit(1)
    return files


def build_tarball(files: list[pathlib.Path]) -> tuple[bytes, str]:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz", format=tarfile.GNU_FORMAT) as tar:
        for f in files:
            arcname = str(f.relative_to(ROOT))
            info = tar.gettarinfo(str(f), arcname=arcname)
            # deterministic + sane permissions
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = 0
            is_exec = f.name == "paneld" or f.name == "alphacp"
            info.mode = 0o755 if is_exec else 0o644
            with open(f, "rb") as fh:
                tar.addfile(info, fh)
    data = buf.getvalue()
    return data, hashlib.sha256(data).hexdigest()


def main() -> int:
    if not TEMPLATE.exists():
        print(f"template not found: {TEMPLATE}", file=sys.stderr)
        return 1

    files = collect_files()
    tar_bytes, sha = build_tarball(files)
    b64 = base64.b64encode(tar_bytes).decode("ascii")
    b64_wrapped = "\n".join(textwrap.wrap(b64, 76))

    template = TEMPLATE.read_text()
    if "${ACP_PAYLOAD_B64}" not in template:
        print("template has no ${ACP_PAYLOAD_B64} marker", file=sys.stderr)
        return 1

    script = template.replace("${ACP_PAYLOAD_B64}", b64_wrapped)
    script = script.replace("${ACP_TAR_SHA256}", sha)
    OUTPUT.write_text(script)
    OUTPUT.chmod(0o755)

    print(f"built {OUTPUT.relative_to(ROOT)}")
    print(f"  files   : {len(files)}")
    print(f"  tarball : {len(tar_bytes) / 1024:.1f} KB")
    print(f"  script  : {len(script) / 1024:.1f} KB")
    print(f"  sha256  : {sha}")

    # cheap sanity: the shipped script must be valid bash
    check = subprocess.run(["bash", "-n", str(OUTPUT)], capture_output=True, text=True)
    if check.returncode != 0:
        print("!! generated script fails `bash -n`:\n" + check.stderr, file=sys.stderr)
        return 1
    print("  bash -n : OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
