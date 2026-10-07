#!/usr/bin/env python3
"""installer/entry-gate.sh generate karta hai (controller embed)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
ctrl = (repo / "features/entrygate/app/Http/Controllers/Auth/EntryLoginController.php").read_text()
tpl = (repo / "installer/entry-gate.sh.in").read_text()
assert "__CONTROLLER__" in tpl
(repo / "installer/entry-gate.sh").write_text(tpl.replace("__CONTROLLER__", ctrl.rstrip("\n")))
print("WROTE installer/entry-gate.sh", (repo / "installer/entry-gate.sh").stat().st_size, "bytes")
