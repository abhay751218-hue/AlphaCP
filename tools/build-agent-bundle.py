#!/usr/bin/env python3
"""Build a reproducible paneld tarball from agent/.

Output: artifacts/agent-<version>.tar.gz
Version is ACP_AGENT_VERSION in agent/src/Bootstrap.php.
Prefix inside the tar: agent/
"""

from __future__ import annotations

import gzip
import hashlib
import io
import pathlib
import re
import tarfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "agent"
BOOT = (SOURCE / "src" / "Bootstrap.php").read_text()
match = re.search(r"define\('ACP_AGENT_VERSION',\s*'([^']+)'\)", BOOT)
if not match:
    raise SystemExit("ACP_AGENT_VERSION missing in agent/src/Bootstrap.php")
VERSION = match.group(1)
OUTPUT = ROOT / "artifacts" / f"agent-{VERSION}.tar.gz"
EXCLUDED = {".phpunit.result.cache"}


def build() -> tuple[bytes, int]:
    raw = io.BytesIO()
    files = [p for p in sorted(SOURCE.rglob("*")) if p.is_file()]
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.GNU_FORMAT) as tar:
        count = 0
        for path in files:
            relative = path.relative_to(SOURCE).as_posix()
            if relative in EXCLUDED:
                continue
            info = tar.gettarinfo(str(path), arcname=f"agent/{relative}")
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = 0
            info.mode = 0o755 if path.name == "paneld" or path.suffix == ".php" and path.parent.name == "bin" else 0o644
            if path.name == "paneld":
                info.mode = 0o755
            with path.open("rb") as handle:
                tar.addfile(info, handle)
            count += 1
    compressed = io.BytesIO()
    with gzip.GzipFile(filename="", mode="wb", fileobj=compressed, compresslevel=9, mtime=0) as stream:
        stream.write(raw.getvalue())
    return compressed.getvalue(), count


def main() -> None:
    payload, count = build()
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(payload)
    print(f"built {OUTPUT.relative_to(ROOT)}")
    print(f"  files  : {count}")
    print(f"  bytes  : {len(payload)}")
    print(f"  sha256 : {hashlib.sha256(payload).hexdigest()}")


if __name__ == "__main__":
    main()
