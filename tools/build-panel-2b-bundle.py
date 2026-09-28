#!/usr/bin/env python3
"""Build the reproducible code-only Laravel 13 panel payload.

Source of truth: refs/panel-2b-bundle/
Output: artifacts/panel-code-0.3.1.tar.gz

The installer runs Composer on the target server, so vendor/ is intentionally
not included. A zero-mtime tar + gzip makes the SHA-256 reproducible.
"""

from __future__ import annotations

import gzip
import hashlib
import io
import pathlib
import tarfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "refs" / "panel-2b-bundle"
OUTPUT = ROOT / "artifacts" / "panel-code-0.3.1.tar.gz"
EXCLUDED = {"database/database.sqlite", ".phpunit.result.cache"}


def build() -> tuple[bytes, int]:
    raw = io.BytesIO()
    files = [p for p in sorted(SOURCE.rglob("*")) if p.is_file()]
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.GNU_FORMAT) as tar:
        count = 0
        for path in files:
            relative = path.relative_to(SOURCE).as_posix()
            if relative in EXCLUDED:
                continue
            info = tar.gettarinfo(str(path), arcname=f"panel/{relative}")
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = 0
            info.mode = 0o755 if path.name == "artisan" else 0o644
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
