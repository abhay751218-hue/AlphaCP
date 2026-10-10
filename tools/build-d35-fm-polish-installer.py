#!/usr/bin/env python3
"""D35 — FM demo-exact polish: SVG toolbar icons, logo+user header, colored type badges,
dark context menu (demo-3), white upload toast + Cancel + MB (demo-4), Extract Archive modal
with target dir (demo-4, backend 'to' support)."""

FILES = [
    ("d35-files-index.blade.php", "PANEL:resources/views/files/index.blade.php"),
    ("d35-fm.js", "PANEL:public/assets/fm.js"),
    ("d35-FilesController.php", "PANEL:app/Http/Controllers/FilesController.php"),
    ("d35-FilesSet.php", "AGENT:src/Tasks/FilesSet.php"),
]

if __name__ == "__main__":
    print("D35 deploys via updater scripts")
