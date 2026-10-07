#!/usr/bin/env python3
"""Build the reproducible code-only panel artifact from the deployed panel source.

Source of truth (docs/MASTER-PLAN.md §0.4):
    server-snapshot/files/usr/local/alphacp/panel/     (live panel code mirror)

Output:
    artifacts/panel-code-<version>.tar.gz              (version = panel/MANIFEST.json)

The installer/updater runs Composer on the target server, so `vendor/` is
intentionally NOT included. Runtime state (storage/, bootstrap/cache, .env,
database/*.sqlite) is excluded as well — those must survive an update.

Reproducibility: uid/gid 0, zero mtime, fixed modes, gzip mtime 0 → same input
tree ⇒ same sha256 (this is what the pinned `panel-update.sh` verifies).

Usage:
    python3 tools/build-panel-artifact.py            # newest MANIFEST.json version
    python3 tools/build-panel-artifact.py --list     # file count + sha256 only
"""

from __future__ import annotations

import gzip
import hashlib
import io
import json
import pathlib
import sys
import tarfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"

# Runtime state — never ship these in an update artifact.
EXCLUDED_DIRS = {
    "vendor",
    "node_modules",
    "storage",
    "bootstrap/cache",
    ".git",
}
EXCLUDED_FILES = {
    ".env",
    "database/database.sqlite",
    ".phpunit.result.cache",
    "MANIFEST.json.bak",
}


def iter_files():
    for path in sorted(SOURCE.rglob("*")):
        if not path.is_file():
            continue
        relative = path.relative_to(SOURCE).as_posix()
        if relative in EXCLUDED_FILES:
            continue
        if any(relative == d or relative.startswith(d + "/") for d in EXCLUDED_DIRS):
            continue
        yield path, relative


def build() -> tuple[bytes, int, str]:
    version = json.loads((SOURCE / "MANIFEST.json").read_text())["version"]
    raw = io.BytesIO()
    count = 0
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.GNU_FORMAT) as tar:
        for path, relative in iter_files():
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
    payload = compressed.getvalue()
    return payload, count, version


def main() -> int:
    if not SOURCE.is_dir():
        print(f"ERROR: panel source nahi mila: {SOURCE}", file=sys.stderr)
        return 2

    payload, count, version = build()
    digest = hashlib.sha256(payload).hexdigest()

    if "--list" in sys.argv:
        print(f"version {version} · files {count} · sha256 {digest}")
        return 0

    out = ROOT / "artifacts" / f"panel-code-{version}.tar.gz"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_bytes(payload)
    print(f"wrote  {out.relative_to(ROOT)}")
    print(f"panel  {version} · files {count}")
    print(f"sha256 {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
