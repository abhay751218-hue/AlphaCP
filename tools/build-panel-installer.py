#!/usr/bin/env python3
"""
Build installer/panel-install.sh from installer/panel-install.sh.in

Packs the panel app (panel/, without vendor) + db/migrations into the installer,
so the shipped file is a true one-file install while the repo stays the source
of truth.

Run after ANY change to panel/ or db/migrations/:
    python3 tools/build-panel-installer.py

Output: installer/panel-install.sh  (self-contained, checksum-protected)
"""

from __future__ import annotations

import base64
import gzip
import hashlib
import io
import pathlib
import subprocess
import sys
import tarfile
import textwrap

ROOT = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = ROOT / "installer" / "panel-install.sh.in"
OUTPUT = ROOT / "installer" / "panel-install.sh"
PANEL = ROOT / "panel"

# never ship these (they are either environment-specific or rebuild outputs)
EXCLUDED_DIRS = {
    ".git", "node_modules", "vendor", "__pycache__",
    "storage/framework/cache", "storage/framework/sessions", "storage/framework/views",
    "storage/logs", "bootstrap/cache",
}
# NOTE: composer.lock is intentionally NOT embedded — it adds ~23 KB gzip to the payload and
# push-to-paste installers have a ~70 KB limit. Dependencies resolve from composer.json
# (`laravel/framework: ^11.0`). Step 2B-2 moves releases to our own HTTPS mirror, where the
# lock file (and full determinism) comes back. The lock stays in the repo as the source of truth.
EXCLUDED_FILES = {".env", ".env.local", "panel-admin.txt", ".DS_Store", "composer.lock"}


def collect() -> list[tuple[pathlib.Path, str]]:
    if not PANEL.exists():
        print("panel/ not found", file=sys.stderr)
        sys.exit(1)

    out: list[tuple[pathlib.Path, str]] = []
    for path in sorted(PANEL.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(PANEL).as_posix()
        if any(rel == d or rel.startswith(d + "/") for d in EXCLUDED_DIRS):
            continue
        if path.name in EXCLUDED_FILES:
            continue
        out.append((path, f"panel/{rel}"))

    for sql in sorted((ROOT / "db" / "migrations").glob("*.sql")):
        out.append((sql, f"db/migrations/{sql.name}"))

    return out


def build_tarball(files: list[tuple[pathlib.Path, str]]) -> tuple[bytes, str]:
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.GNU_FORMAT) as tar:
        for src, arcname in files:
            info = tar.gettarinfo(str(src), arcname=arcname)
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = 0
            info.mode = 0o755 if src.name in {"artisan", "paneld", "alphacp"} else 0o644
            with open(src, "rb") as fh:
                tar.addfile(info, fh)
    # compress by hand with mtime=0 so the same source always yields the same payload
    # (tarfile's "w:gz" stamps the gzip header with the current time -> non-reproducible)
    buf = io.BytesIO()
    with gzip.GzipFile(filename="", mode="wb", compresslevel=9, fileobj=buf, mtime=0) as gz:
        gz.write(raw.getvalue())
    data = buf.getvalue()
    return data, hashlib.sha256(data).hexdigest()


def main() -> int:
    if not TEMPLATE.exists():
        print(f"template not found: {TEMPLATE}", file=sys.stderr)
        return 1

    files = collect()
    tar_bytes, sha = build_tarball(files)
    b64 = "\n".join(textwrap.wrap(base64.b64encode(tar_bytes).decode("ascii"), 76))

    script = TEMPLATE.read_text()
    if "${ACP_PAYLOAD_B64}" not in script:
        print("template has no ${ACP_PAYLOAD_B64} marker", file=sys.stderr)
        return 1

    script = script.replace("${ACP_PAYLOAD_B64}", b64).replace("${ACP_TAR_SHA256}", sha)
    OUTPUT.write_text(script)
    OUTPUT.chmod(0o755)

    print(f"built {OUTPUT.relative_to(ROOT)}")
    print(f"  files   : {len(files)}")
    print(f"  tarball : {len(tar_bytes) / 1024:.1f} KB")
    print(f"  script  : {len(script) / 1024:.1f} KB")
    print(f"  sha256  : {sha}")

    check = subprocess.run(["bash", "-n", str(OUTPUT)], capture_output=True, text=True)
    if check.returncode != 0:
        print("!! generated script fails `bash -n`:\n" + check.stderr, file=sys.stderr)
        return 1
    print("  bash -n : OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
