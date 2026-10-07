#!/usr/bin/env python3
"""installer/resellers.sh generate karta hai feature files se (heredoc-embedded)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
ctl = (repo / "features/resellers/app/Http/Controllers/ResellersController.php").read_text()
view = (repo / "features/resellers/resources/views/resellers/index.blade.php").read_text()
routes = (repo / "features/resellers/routes-resellers.php").read_text()

def block(dest: str, content: str) -> str:
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = []
out.append("""#!/usr/bin/env bash
# AlphaCP — Reseller Center (WHM-style) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Reseller Center installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/resellers"
""")
out.append(block("app/Http/Controllers/ResellersController.php", ctl))
out.append(block("resources/views/resellers/index.blade.php", view))
out.append("""
echo "== Step 2: routes (idempotent) =="
if ! grep -q "Reseller Center (WHM-style)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
""")
out.append(routes.rstrip("\n") + "\n")
out.append("""ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> RESELLER CENTER v1.0 INSTALLED  (panel: /resellers)"
echo "=================================================="
alphacp-sync || true
""")

(repo / "installer/resellers.sh").write_text("".join(out))
print("WROTE installer/resellers.sh", (repo / "installer/resellers.sh").stat().st_size, "bytes")
