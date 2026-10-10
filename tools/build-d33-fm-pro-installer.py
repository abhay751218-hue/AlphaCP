#!/usr/bin/env python3
"""D33 — FM pro: right-click context menu + row selection toolbar + upload progress + ZIP extract."""

FILES = [
    ("d33-files-index.blade.php", "PANEL:resources/views/files/index.blade.php"),
    ("d33-fm.js", "PANEL:public/assets/fm.js"),
    ("d33-FilesController.php", "PANEL:app/Http/Controllers/FilesController.php"),
    ("d33-FilesSet.php", "AGENT:src/Tasks/FilesSet.php"),
]

if __name__ == "__main__":
    print("D33 deploys via updater scripts")
