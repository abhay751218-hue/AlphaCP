#!/usr/bin/env python3
"""installer/dashboard-sync.sh — patched ModuleCatalog server par replace karta hai."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
cat = (repo / "features/dashboard/ModuleCatalog.php").read_text()

out = []
out.append("#!/usr/bin/env bash\n")
out.append("# AlphaCP — Dashboard sync (ModuleCatalog: installed features -> live)  v1.0\n")
out.append("set -euo pipefail\n")
out.append("PANEL=/usr/local/alphacp/panel\n")
out.append('echo "=================================================="\n')
out.append('echo " AlphaCP Dashboard sync installer  v1.0"\n')
out.append('echo "=================================================="\n')
out.append('echo "== Step 1: ModuleCatalog replace =="\n')
out.append('cat > "$PANEL/app/Support/ModuleCatalog.php" <<\'ACP_FILE_EOF\'\n')
out.append(cat.rstrip("\n") + "\n")
out.append("ACP_FILE_EOF\n")
out.append('echo "  + app/Support/ModuleCatalog.php (features -> live)"\n')
out.append('\n')
out.append('echo "== Step 2: cache clear =="\n')
out.append('cd "$PANEL"\n')
out.append("php artisan config:clear || true\n")
out.append("php artisan route:clear || true\n")
out.append('echo "[OK] cache cleared"\n')
out.append('\n')
out.append('echo "=================================================="\n')
out.append('echo " ==> DASHBOARD SYNC v1.0 APPLIED  (tiles ab live dikhenge)"\n')
out.append('echo "=================================================="\n')
out.append("alphacp-sync || true\n")

(repo / "installer/dashboard-sync.sh").write_text("".join(out))
print("WROTE installer/dashboard-sync.sh", (repo / "installer/dashboard-sync.sh").stat().st_size, "bytes")
