#!/usr/bin/env python3
"""installer/reseller-scope.sh generate karta hai (provider file embed)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
provider = (repo / "features/resellerscope/app/Providers/ResellerScopeProvider.php").read_text()
tpl = (repo / "installer/reseller-scope.sh.in").read_text()
assert "__PROVIDER__" in tpl, "placeholder missing"
out = tpl.replace("__PROVIDER__", provider.rstrip("\n"))
(repo / "installer/reseller-scope.sh").write_text(out)
print("WROTE installer/reseller-scope.sh", (repo / "installer/reseller-scope.sh").stat().st_size, "bytes")
