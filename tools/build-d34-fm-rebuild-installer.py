#!/usr/bin/env python3
"""D34 — FM demo-1 rebuild: dark brand header, breadcrumbs, checkbox multi-select, sort,
blue icon toolbar (Copy/Move/Download/Restore included), fetch-based ops, copy op (agent)."""

FILES = [
    ("d34-files-index.blade.php", "PANEL:resources/views/files/index.blade.php"),
    ("d34-fm.js", "PANEL:public/assets/fm.js"),
    ("d34-FilesController.php", "PANEL:app/Http/Controllers/FilesController.php"),
    ("d34-web.php", "PANEL:routes/web.php"),
    ("d34-Files.php", "AGENT:src/Files.php"),
    ("d34-FilesSet.php", "AGENT:src/Tasks/FilesSet.php"),
]

if __name__ == "__main__":
    print("D34 deploys via updater scripts")
